<?php

// ─── IdtReviewFrequencyJob ─────────────────────────────────────────────────────
// Daily job that identifies enrolled participants who are overdue for their
// 6-month IDT reassessment and creates deduplicating warning alerts.
//
// 42 CFR §460.104(c): The IDT must reassess each participant at least every
// 6 months, and more frequently when there is a significant change in status.
// This is a common CMS survey deficiency in PACE programs.
//
// Schedule: daily at 07:30 (staggered from TransferCompletionJob at 7am)
// Queue:    idt-review
//
// Deduplication: skips participants that already have an unacknowledged
// 'idt_review_overdue' alert (is_active=true) — avoids flooding the alert feed.
//
// Alert type: 'idt_review_overdue'
// Severity:   'warning'
// Target:     ['idt'] (IDT team responsible for scheduling reassessments)
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IdtReviewFrequencyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Days between IDT reassessments required by 42 CFR §460.104(c). */
    public const REASSESSMENT_DAYS = 180;

    public $tries = 3;

    public function handle(): void
    {
        $processed = 0;
        $alertsCreated = 0;

        Tenant::all()->each(function (Tenant $tenant) use (&$processed, &$alertsCreated) {
            $overdue = Participant::forTenant($tenant->id)
                ->where('enrollment_status', 'enrolled')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get()
                ->filter(fn (Participant $p) => $p->idtReviewOverdue());

            foreach ($overdue as $participant) {
                $processed++;

                // Deduplication: skip if an unacknowledged 'idt_review_overdue' alert already
                // exists for this participant. Use is_active=true (not is_dismissed — that column
                // does not exist on emr_alerts). Use alert_type for dedup (not action_type).
                $existing = Alert::where('tenant_id', $tenant->id)
                    ->where('participant_id', $participant->id)
                    ->where('alert_type', 'idt_review_overdue')
                    ->where('is_active', true)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $lastReview   = $participant->lastIdtReviewedAt();
                $daysOverdue  = $lastReview
                    ? (int) $lastReview->diffInDays(now()) - self::REASSESSMENT_DAYS
                    : null;
                $overdueText  = $daysOverdue !== null
                    ? " ({$daysOverdue} days overdue)"
                    : ' (no IDT review on record)';

                Alert::create([
                    'tenant_id'          => $tenant->id,
                    'participant_id'     => $participant->id,
                    'source_module'      => 'idt',
                    'alert_type'         => 'idt_review_overdue',
                    'title'              => 'IDT Reassessment Overdue',
                    'message'            => "{$participant->first_name} {$participant->last_name} ({$participant->mrn})"
                        . " is overdue for their 6-month IDT reassessment"
                        . "{$overdueText}. 42 CFR §460.104(c) requires reassessment at least every 6 months.",
                    'severity'           => 'warning',
                    'target_departments' => ['idt'],
                    'metadata'           => [
                        'participant_mrn'  => $participant->mrn,
                        'last_reviewed_at' => $lastReview?->toDateString(),
                        'days_overdue'     => $daysOverdue,
                    ],
                    'created_by_system'  => true,
                    'is_active'          => true,
                ]);

                $alertsCreated++;
            }
        });

        Log::info('IdtReviewFrequencyJob complete', [
            'participants_checked' => $processed,
            'alerts_created'       => $alertsCreated,
        ]);
    }
}
