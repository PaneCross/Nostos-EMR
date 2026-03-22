<?php

// ─── LateMarDetectionJob ──────────────────────────────────────────────────────
// Scans eMAR records whose scheduled_time has passed without administration
// and marks them as 'late'. Scheduled every 30 minutes via Horizon.
//
// A dose is "late" when:
//   - status = 'scheduled' (not yet charted by a nurse)
//   - scheduled_time < now() (the administration window has passed)
//
// The LATE_THRESHOLD_MINUTES constant (30 min) gives nurses a grace period
// before a dose is flagged — a dose due at 08:00 won't flag as late until 08:30.
//
// When a record is marked late, an alert is also created targeting the
// 'primary_care' and 'nursing' (therapies) departments via AlertService.
// The alert is informational (type=warning) and scoped to the participant.
//
// This job runs on the 'mar-detection' queue.
// Horizon should have a worker assigned to 'mar-detection' queue.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\EmarRecord;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LateMarDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of minutes after scheduled_time before a dose is flagged as late.
     * This grace period avoids false positives for doses being administered on time.
     */
    private const LATE_THRESHOLD_MINUTES = 30;

    public function __construct()
    {
        $this->onQueue('mar-detection');
    }

    public function handle(AlertService $alertService): void
    {
        // Find all scheduled (uncharged) doses whose window + grace period has passed
        $overdueRecords = EmarRecord::with('medication:id,drug_name', 'participant:id,first_name,last_name,tenant_id')
            ->where('status', 'scheduled')
            ->where('scheduled_time', '<', now()->subMinutes(self::LATE_THRESHOLD_MINUTES))
            ->get();

        if ($overdueRecords->isEmpty()) {
            return;
        }

        $markedLate = 0;

        foreach ($overdueRecords as $record) {
            // Update the record status to 'late' (append-only: direct update, not save())
            EmarRecord::where('id', $record->id)->update(['status' => 'late']);

            $participant = $record->participant;
            $medName     = $record->medication?->drug_name ?? 'Unknown medication';

            // Create a warning alert for the care team
            $alertService->create([
                'tenant_id'          => $record->tenant_id,
                'participant_id'     => $participant->id,
                'source_module'      => 'medications',
                'alert_type'         => 'warning',
                'title'              => "Late Medication Dose — {$medName}",
                'message'            => "{$medName} was scheduled at "
                                       . $record->scheduled_time->format('g:i A')
                                       . " for {$participant->first_name} {$participant->last_name}"
                                       . " but has not been charted.",
                'severity'           => 'warning',
                'target_departments' => ['primary_care', 'therapies'],
                'created_by_system'  => true,
            ]);

            AuditLog::record(
                action:       'emar.late_dose_flagged',
                tenantId:     $record->tenant_id,
                userId:       null,
                resourceType: 'emar_record',
                resourceId:   $record->id,
                description:  "eMAR dose flagged late: {$medName} scheduled at "
                              . $record->scheduled_time->toIso8601String(),
            );

            $markedLate++;
        }

        Log::info('LateMarDetectionJob: late doses flagged', [
            'count'    => $markedLate,
            'run_at'   => now()->toIso8601String(),
        ]);
    }
}
