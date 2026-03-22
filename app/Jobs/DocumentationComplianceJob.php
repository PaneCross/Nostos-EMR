<?php

// ─── DocumentationComplianceJob ────────────────────────────────────────────────
// Runs daily at 6 AM (see routes/console.php).
// Scans all tenants for documentation compliance issues and creates alerts.
//
// Two checks:
//   1. Unsigned notes older than 24h → warning alert to the note's dept admin
//      (Note: clinicians have 24h to sign their chart entries per PACE policy)
//   2. Overdue assessments → info alert to the responsible dept admin
//
// Alert deduplication: checks for existing unacknowledged alerts of the same
// type for the same participant before creating to avoid notification spam.
//
// Queued on 'compliance' queue (processed by Horizon workers).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Assessment;
use App\Models\ClinicalNote;
use App\Models\Tenant;
use App\Services\AlertService;
use App\Services\QaMetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DocumentationComplianceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('compliance');
    }

    public function handle(QaMetricsService $metrics, AlertService $alertService): void
    {
        $tenants = Tenant::whereHas('participants')->pluck('id');

        $unsignedCount   = 0;
        $overdueCount    = 0;

        foreach ($tenants as $tenantId) {
            // ── 1. Unsigned notes > 24h ──────────────────────────────────────
            $unsignedNotes = $metrics->getUnsignedNotesOlderThan($tenantId, 24);

            foreach ($unsignedNotes as $note) {
                // De-dupe: skip if there's already an active unsigned-note alert for this note
                $existing = Alert::where('tenant_id', $tenantId)
                    ->where('alert_type', 'unsigned_note')
                    ->where('source_module', 'documentation_compliance')
                    ->where('participant_id', $note->participant_id)
                    ->whereJsonContains('new_values->note_id', $note->id)
                    ->where('is_active', true)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $alertService->create([
                    'tenant_id'          => $tenantId,
                    'participant_id'     => $note->participant_id,
                    'source_module'      => 'documentation_compliance',
                    'alert_type'         => 'unsigned_note',
                    'title'              => 'Unsigned Note > 24h',
                    'message'            => "A {$note->note_type} note from " .
                        $note->created_at->format('M j g:ia') .
                        " has not been signed. Chart entries must be signed within 24 hours.",
                    'severity'           => 'warning',
                    'target_departments' => [$note->department, 'qa_compliance'],
                    'created_by_system'  => true,
                    'new_values'         => ['note_id' => $note->id],
                ]);

                $unsignedCount++;
            }

            // ── 2. Overdue assessments ───────────────────────────────────────
            $overdueAssessments = $metrics->getOverdueAssessments($tenantId);

            foreach ($overdueAssessments as $assessment) {
                // De-dupe: skip if there's an active overdue alert for this assessment
                $existing = Alert::where('tenant_id', $tenantId)
                    ->where('alert_type', 'assessment_overdue')
                    ->where('source_module', 'documentation_compliance')
                    ->where('participant_id', $assessment->participant_id)
                    ->whereJsonContains('new_values->assessment_id', $assessment->id)
                    ->where('is_active', true)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $daysOverdue = (int) now()->diffInDays($assessment->next_due_date);

                $alertService->create([
                    'tenant_id'          => $tenantId,
                    'participant_id'     => $assessment->participant_id,
                    'source_module'      => 'documentation_compliance',
                    'alert_type'         => 'assessment_overdue',
                    'title'              => 'Assessment Overdue',
                    'message'            => ucwords(str_replace('_', ' ', $assessment->assessment_type)) .
                        " assessment for participant is {$daysOverdue} day(s) overdue.",
                    'severity'           => 'info',
                    'target_departments' => [$assessment->department ?? 'qa_compliance'],
                    'created_by_system'  => true,
                    'new_values'         => ['assessment_id' => $assessment->id],
                ]);

                $overdueCount++;
            }
        }

        Log::info('[DocumentationComplianceJob] Scan complete', [
            'tenants_scanned'  => count($tenants),
            'unsigned_alerts'  => $unsignedCount,
            'overdue_alerts'   => $overdueCount,
        ]);
    }
}
