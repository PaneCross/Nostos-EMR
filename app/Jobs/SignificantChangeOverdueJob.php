<?php

// ─── SignificantChangeOverdueJob ──────────────────────────────────────────────
// W4-6 / GAP-10: Daily job that creates warning alerts for significant change
// events that are past their 30-day IDT reassessment deadline.
//
// 42 CFR §460.104(b): IDT must reassess within 30 days of significant change
// in participant health status (hospitalization, fall with injury, functional decline).
//
// Deduplication: only creates one active alert per significant change event.
// Schedule: daily at 07:00 (30 min after IncidentNotificationOverdueJob)
// Queue: 'compliance'
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\SignificantChangeEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SignificantChangeOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('compliance');
    }

    public function handle(): void
    {
        // All pending events past their due date
        $overdueEvents = SignificantChangeEvent::overdue()
            ->with('participant:id,first_name,last_name,tenant_id')
            ->get();

        $created = 0;

        foreach ($overdueEvents as $event) {
            // Dedup: skip if an active alert already exists for this event
            $exists = Alert::where('tenant_id', $event->tenant_id)
                ->where('alert_type', 'significant_change_idt_overdue')
                ->where('is_active', true)
                ->whereJsonContains('metadata->significant_change_event_id', $event->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $participant = $event->participant;
            $daysOverdue = (int) abs(now()->diffInDays($event->idt_review_due_date));

            Alert::create([
                'tenant_id'          => $event->tenant_id,
                'participant_id'     => $event->participant_id,
                'source_module'      => 'idt',
                'alert_type'         => 'significant_change_idt_overdue',
                'title'              => "IDT Reassessment Overdue: {$event->triggerTypeLabel()}",
                'message'            => "IDT reassessment for {$participant?->first_name} {$participant?->last_name} "
                    . "following {$event->triggerTypeLabel()} on {$event->trigger_date->toFormattedDateString()} "
                    . "is {$daysOverdue} days past the 30-day deadline. "
                    . "42 CFR §460.104(b) requires reassessment within 30 days of significant change.",
                'severity'           => 'warning',
                'target_departments' => ['idt'],
                'is_active'          => true,
                'created_by_system'  => true,
                'metadata'           => [
                    'significant_change_event_id' => $event->id,
                    'trigger_type'                => $event->trigger_type,
                    'trigger_date'                => $event->trigger_date->toDateString(),
                    'idt_review_due_date'          => $event->idt_review_due_date->toDateString(),
                    'days_overdue'                => $daysOverdue,
                ],
            ]);

            $created++;
        }

        Log::info('[SignificantChangeOverdueJob] Completed', [
            'overdue_events_found' => $overdueEvents->count(),
            'alerts_created'       => $created,
        ]);
    }
}
