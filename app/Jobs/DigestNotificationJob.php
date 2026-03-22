<?php

// ─── DigestNotificationJob ────────────────────────────────────────────────────
// Runs every 2 hours via Horizon schedule.
// Collects users with pending digest notifications (tracked in cache by
// NotificationDispatcher::queueForDigest) and sends each a single
// DigestNotificationMail with the count of pending items.
//
// HIPAA: Email contains ZERO PHI — only the count of notifications.
//
// After sending, clears the cache counter so the next 2-hour window starts fresh.
//
// Runs on the 'notifications' queue.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Mail\DigestNotificationMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DigestNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    /**
     * Scan all active users for pending digest counters.
     * For each user with count > 0, send a DigestNotificationMail and clear the counter.
     */
    public function handle(): void
    {
        // Load all active users. For large deployments this would be chunked,
        // but for PACE (typically <500 users) a single query is fine.
        User::where('is_active', true)->each(function (User $user) {
            $key   = "digest_pending:{$user->id}";
            $count = (int) cache()->get($key, 0);

            if ($count <= 0) {
                return;
            }

            try {
                Mail::to($user->email)->queue(new DigestNotificationMail($user, $count));
            } catch (\Throwable $e) {
                Log::warning("DigestNotificationJob: failed to send to {$user->email}", [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            // Clear the counter after dispatching the email
            cache()->forget($key);
            cache()->forget($key . ':ttl_guard');
        });
    }
}
