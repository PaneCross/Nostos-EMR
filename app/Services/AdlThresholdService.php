<?php

// ─── AdlThresholdService ──────────────────────────────────────────────────────
// Determines whether a new ADL record breaches the participant's configured threshold
// for that ADL category and handles the breach response.
//
// Breach logic: LEVELS is ordered best→worst (index 0 = independent, index 4 = total_dependent).
// If the new record's level index > threshold level index → breach (participant declined).
//
// Phase 4: handleBreach() now creates an emr_alert targeting primary_care + social_work
// and broadcasts ParticipantAdlBreachEvent for real-time cross-department notification.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Events\ParticipantAdlBreachEvent;
use App\Models\AdlRecord;
use App\Models\AdlThreshold;
use App\Models\AuditLog;
use App\Models\Participant;
use Illuminate\Support\Facades\Log;

class AdlThresholdService
{
    public function __construct(private readonly AlertService $alertService) {}

    /**
     * Check whether a new ADL record breaches the configured threshold.
     *
     * Returns true if the recorded independence_level is WORSE than the threshold
     * (i.e., further toward 'total_dependent'). Returns false if no threshold is set.
     */
    public function checkBreach(AdlRecord $record): bool
    {
        $threshold = AdlThreshold::where('participant_id', $record->participant_id)
            ->where('adl_category', $record->adl_category)
            ->first();

        if (! $threshold) {
            return false;  // No threshold configured — no breach possible
        }

        // Higher LEVELS index = more dependent = worse function
        $recordIndex    = $record->levelIndex();
        $thresholdIndex = $threshold->levelIndex();

        return $recordIndex > $thresholdIndex;
    }

    /**
     * Mark the record as breached, create an alert, and broadcast the event.
     * Called by AdlRecordObserver when checkBreach() returns true.
     */
    public function handleBreach(AdlRecord $record, Participant $participant): void
    {
        // Update the record (direct update to avoid re-firing the observer)
        AdlRecord::where('id', $record->id)->update(['threshold_breached' => true]);

        $threshold = AdlThreshold::where('participant_id', $record->participant_id)
            ->where('adl_category', $record->adl_category)
            ->first();

        $message = sprintf(
            'ADL decline detected: %s — %s now "%s" (threshold: "%s")',
            $participant->fullName(),
            ucwords(str_replace('_', ' ', $record->adl_category)),
            $record->independence_level,
            $threshold?->threshold_level ?? 'unknown',
        );

        Log::warning("[ADL Threshold] {$message}", [
            'participant_id' => $participant->id,
            'mrn'            => $participant->mrn,
            'category'       => $record->adl_category,
            'level'          => $record->independence_level,
            'threshold'      => $threshold?->threshold_level,
        ]);

        AuditLog::record(
            action: 'participant.adl.threshold_breached',
            tenantId: $participant->tenant_id,
            userId: null,   // system-generated
            resourceType: 'participant',
            resourceId: $participant->id,
            description: $message,
            newValues: [
                'adl_record_id'     => $record->id,
                'adl_category'      => $record->adl_category,
                'recorded_level'    => $record->independence_level,
                'threshold_level'   => $threshold?->threshold_level,
            ],
        );

        // ── Phase 4: Create alert targeting primary_care + social_work ────────
        $this->alertService->create([
            'tenant_id'          => $participant->tenant_id,
            'participant_id'     => $participant->id,
            'source_module'      => 'adl',
            'alert_type'         => 'adl_decline',
            'severity'           => $record->independence_level === 'total_dependent' ? 'critical' : 'warning',
            'title'              => 'ADL Decline — ' . ucwords(str_replace('_', ' ', $record->adl_category)),
            'message'            => $message,
            'target_departments' => ['primary_care', 'social_work'],
            'created_by_system'  => true,
        ]);

        // ── Phase 4: Broadcast for real-time cross-dept chart refresh ─────────
        broadcast(new ParticipantAdlBreachEvent($record->refresh()))->toOthers();
    }
}
