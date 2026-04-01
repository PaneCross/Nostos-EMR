<?php

// ─── IncidentNotificationOverdueJob ──────────────────────────────────────────
// W4-6 / GAP-08: Daily job that creates critical alerts for incidents with
// cms_notification_required=true that are past their 72-hour regulatory deadline
// without cms_notification_sent_at being recorded.
//
// 42 CFR §460.136 requires CMS and State Medicaid Agency (SMA) notification
// within specified timeframes for: abuse_neglect, hospitalization, er_visit,
// unexpected_death.
//
// Deduplication: only creates one alert per incident (checks for existing active
// alert with alert_type='cms_notification_overdue' and metadata.incident_id).
//
// Schedule: daily at 06:00 (after DocumentationComplianceJob at 06:00)
// Queue: 'compliance'
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IncidentNotificationOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('compliance');
    }

    public function handle(): void
    {
        // Find all incidents that are past their CMS notification deadline
        $overdueIncidents = Incident::cmsNotificationOverdue()
            ->with('participant:id,first_name,last_name,tenant_id')
            ->get();

        $created = 0;

        foreach ($overdueIncidents as $incident) {
            // Dedup: skip if an active alert for this incident already exists
            $exists = Alert::where('tenant_id', $incident->tenant_id)
                ->where('alert_type', 'cms_notification_overdue')
                ->where('is_active', true)
                ->whereJsonContains('metadata->incident_id', $incident->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $participant = $incident->participant;
            $hoursOverdue = (int) abs(now()->diffInHours($incident->regulatory_deadline));

            Alert::create([
                'tenant_id'          => $incident->tenant_id,
                'participant_id'     => $incident->participant_id,
                'source_module'      => 'qa_compliance',
                'alert_type'         => 'cms_notification_overdue',
                'title'              => "CMS Notification Overdue: {$incident->typeLabel()}",
                'message'            => "CMS/SMA notification for {$participant?->first_name} {$participant?->last_name} "
                    . "({$incident->typeLabel()} on " . $incident->occurred_at?->toDateString() . ") "
                    . "is {$hoursOverdue} hours past the 72-hour regulatory deadline. "
                    . "42 CFR §460.136 requires immediate notification.",
                'severity'           => 'critical',
                'target_departments' => ['qa_compliance', 'enrollment'],
                'is_active'          => true,
                'created_by_system'  => true,
                'metadata'           => [
                    'incident_id'         => $incident->id,
                    'incident_type'       => $incident->incident_type,
                    'regulatory_deadline' => $incident->regulatory_deadline?->toIso8601String(),
                    'hours_overdue'       => $hoursOverdue,
                ],
            ]);

            $created++;
        }

        Log::info('[IncidentNotificationOverdueJob] Completed', [
            'overdue_incidents_found' => $overdueIncidents->count(),
            'alerts_created'          => $created,
        ]);
    }
}
