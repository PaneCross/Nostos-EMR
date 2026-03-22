<?php

// ─── ProfileController ────────────────────────────────────────────────────────
// Handles user profile-level settings exposed to authenticated users.
//
// Routes:
//   GET  /profile/notifications  → notifications()       Inertia page
//   PUT  /profile/notifications  → updateNotifications() JSON: save preferences
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProfileController extends Controller
{
    /** Notification preference types that can be customised per user. */
    private const PREF_KEYS = [
        'alert_critical',
        'alert_warning',
        'alert_info',
        'sdr_overdue',
        'new_message',
    ];

    /** Valid delivery values. */
    private const VALID_VALUES = ['in_app_only', 'email_immediate', 'email_digest', 'off'];

    /**
     * Render the notification preferences Inertia page.
     */
    public function notifications(Request $request): InertiaResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $prefs = [];
        foreach (self::PREF_KEYS as $key) {
            $prefs[$key] = $user->notificationPreference($key);
        }

        return Inertia::render('Profile/Notifications', [
            'preferences' => $prefs,
            'pref_keys'   => self::PREF_KEYS,
            'valid_values'=> self::VALID_VALUES,
        ]);
    }

    /**
     * Save the user's notification preferences.
     * Only recognised keys are accepted; unknown keys are ignored.
     */
    public function updateNotifications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences'         => ['required', 'array'],
            'preferences.*'       => ['required', 'string', 'in:in_app_only,email_immediate,email_digest,off'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Merge validated keys only — discard unknown keys from input
        $current = $user->notification_preferences ?? [];

        foreach (self::PREF_KEYS as $key) {
            if (array_key_exists($key, $data['preferences'])) {
                $current[$key] = $data['preferences'][$key];
            }
        }

        $user->update(['notification_preferences' => $current]);

        AuditLog::create([
            'user_id'       => $user->id,
            'action'        => 'profile.notification_preferences.update',
            'resource_type' => 'user',
            'resource_id'   => $user->id,
            'tenant_id'     => $user->tenant_id,
            'ip_address'    => $request->ip(),
        ]);

        return response()->json(['ok' => true]);
    }
}
