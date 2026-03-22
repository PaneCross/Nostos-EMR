<?php

// ─── AdlRecordObserver ────────────────────────────────────────────────────────
// Fires after a new ADL record is inserted.
// Checks against configured thresholds and triggers a breach response if needed.
// Registered in AppServiceProvider.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Observers;

use App\Models\AdlRecord;
use App\Services\AdlThresholdService;

class AdlRecordObserver
{
    public function __construct(private AdlThresholdService $thresholdService) {}

    /**
     * Called after a new AdlRecord is persisted.
     * Checks for threshold breach and logs/flags if one is detected.
     */
    public function created(AdlRecord $record): void
    {
        // Load participant for use in the breach message
        $participant = $record->participant;

        if ($this->thresholdService->checkBreach($record)) {
            $this->thresholdService->handleBreach($record, $participant);
        }
    }
}
