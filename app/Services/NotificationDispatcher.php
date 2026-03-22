<?php

// ─── NotificationDispatcher ───────────────────────────────────────────────────
// Delivers alert notifications to users based on their per-type preferences.
//
// Called from AlertService (or anywhere an Alert is created) after the alert
// is persisted. Iterates every user in the alert's target_departments and
// dispatches the appropriate delivery for their preference.
//
// Delivery modes:
//   in_app_only     — handled by AlertCreatedEvent + Reverb (no email)
//   email_immediate — send NotificationMail now via queue
//   email_digest    — defer to DigestNotificationJob (no immediate email)
//   off             — skip entirely
//
// HIPAA: NotificationMail and DigestNotificationMail contain ZERO PHI.
//
// The preference key is derived from the alert's severity:
//   critical → 'alert_critical'
//   warning  → 'alert_warning'
//   info     → 'alert_info'
// Missing preference falls back to 'in_app_only'.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class NotificationDispatcher
{
    /**
     * Dispatch notifications for an alert to all affected users.
     *
     * @param  Alert  $alert  The newly created alert.
     */
    public function dispatch(Alert $alert): void
    {
        if (empty($alert->target_departments)) {
            return;
        }

        $prefKey = $this->preferenceKey($alert->severity);

        $users = User::where('tenant_id', $alert->tenant_id)
            ->where('is_active', true)
            ->whereIn('department', $alert->target_departments)
            ->get();

        foreach ($users as $user) {
            $this->deliverTo($user, $prefKey);
        }
    }

    /**
     * Deliver one notification to one user based on their preference.
     *
     * @param  User    $user
     * @param  string  $prefKey  e.g. 'alert_critical'
     */
    public function deliverTo(User $user, string $prefKey): void
    {
        $preference = $user->notificationPreference($prefKey);

        match ($preference) {
            // In-app is handled by AlertCreatedEvent broadcasting via Reverb
            'in_app_only'     => null,

            // Queue an immediate email (no PHI)
            'email_immediate' => Mail::to($user->email)
                ->queue(new NotificationMail($user)),

            // Mark for digest collection — DigestNotificationJob picks these up
            'email_digest'    => $this->queueForDigest($user),

            // 'off' or unknown — do nothing
            default           => null,
        };
    }

    /**
     * Mark the user as having a pending digest item.
     * Uses the Laravel cache with a 2-hour TTL — DigestNotificationJob
     * reads and clears these counters each time it runs.
     *
     * Cache key: digest_pending:{user_id}
     * Value: integer count of pending notifications since last digest.
     */
    public function queueForDigest(User $user): void
    {
        $key = "digest_pending:{$user->id}";
        cache()->increment($key);
        // Ensure the key has a TTL so stale data is cleaned up even if
        // DigestNotificationJob fails to run.
        if (! cache()->has($key . ':ttl_guard')) {
            cache()->put($key . ':ttl_guard', true, now()->addHours(24));
            // Re-set the count key with a TTL the first time it's created
            $count = (int) cache()->get($key, 1);
            cache()->put($key, $count, now()->addHours(24));
        }
    }

    /**
     * Derive the notification preference key from an alert severity.
     *
     * @param  string  $severity  'critical' | 'warning' | 'info'
     * @return string  Preference key like 'alert_critical'
     */
    public function preferenceKey(string $severity): string
    {
        return 'alert_' . $severity;
    }
}
