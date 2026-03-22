<?php

// ─── SdrDeadlineEnforcementJob ────────────────────────────────────────────────
// Enforces the 72-hour SDR completion window. Scheduled every 15 minutes via
// Laravel Scheduler (see routes/console.php).
//
// For each open SDR:
//   - If now > due_at AND NOT escalated → escalate + critical alert
//   - If hours_remaining <= 8          → warning alert (de-duped)
//   - If hours_remaining <= 24         → info alert (de-duped)
//
// Alert deduplication: SdrDeadlineService checks for existing active alerts
// of the same type before creating new ones (prevents spam on repeated runs).
//
// This job is queued on the 'sdr-enforcement' queue (Horizon config).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Sdr;
use App\Services\SdrDeadlineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SdrDeadlineEnforcementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sdr-enforcement');
    }

    public function handle(SdrDeadlineService $service): void
    {
        // Process all open SDRs across all tenants
        $openSdrs = Sdr::open()
            ->with(['participant:id,first_name,last_name,mrn,tenant_id'])
            ->orderBy('due_at')
            ->get();

        if ($openSdrs->isEmpty()) {
            return;
        }

        $counts = $service->processBatch($openSdrs);

        Log::info('[SdrDeadlineEnforcementJob] Batch complete', [
            'total_processed' => $openSdrs->count(),
            'info_alerts'     => $counts['info'],
            'warning_alerts'  => $counts['warning'],
            'escalated'       => $counts['escalated'],
        ]);
    }
}
