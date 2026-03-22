<?php

// ─── TransferCompletionJob ─────────────────────────────────────────────────────
// Processes approved participant site transfers whose effective_date has arrived.
//
// Runs daily at 7:00 AM via Laravel Scheduler (routes/console.php).
// Queue: 'transfers' (Horizon config).
//
// For each approved transfer where effective_date <= today:
//   1. Calls TransferService::completeTransfer() inside a DB transaction
//   2. Updates participant.site_id, posts IDT chat alerts, marks completed
//   3. Logs outcome (completed count or any exceptions)
//
// Phase 10A
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\ParticipantSiteTransfer;
use App\Services\TransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TransferCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('transfers');
    }

    public function handle(TransferService $service): void
    {
        $due = ParticipantSiteTransfer::dueForCompletion()->with('participant')->get();

        if ($due->isEmpty()) {
            Log::info('[TransferCompletionJob] No transfers due for completion.');
            return;
        }

        $completed = 0;
        $errors    = 0;

        foreach ($due as $transfer) {
            try {
                $service->completeTransfer($transfer);
                $completed++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error("[TransferCompletionJob] Failed to complete transfer #{$transfer->id}: {$e->getMessage()}");
            }
        }

        Log::info("[TransferCompletionJob] Done. Completed: {$completed}, Errors: {$errors}.");
    }
}
