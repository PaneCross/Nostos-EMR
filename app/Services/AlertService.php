<?php

// ─── AlertService ─────────────────────────────────────────────────────────────
// Creates, acknowledges, and resolves clinical alerts.
// Broadcasts AlertCreatedEvent via Reverb for real-time bell badge updates.
//
// Alert creation sources:
//   - System (ADL breach, SDR overdue, assessment overdue): created_by_system = true
//   - Manual (clinical staff): created_by_system = false, created_by_user_id = user
//
// Acknowledgment: any user in a target department may acknowledge.
// Resolution: sets is_active = false (alert disappears from all views).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Events\AlertCreatedEvent;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class AlertService
{
    /**
     * Create a new alert and broadcast to target departments.
     *
     * Required keys in $data:
     *   tenant_id, source_module, alert_type, title, message, severity,
     *   target_departments (array of dept slugs)
     *
     * Optional keys:
     *   participant_id, created_by_system (default true), created_by_user_id
     */
    public function create(array $data): Alert
    {
        $data['created_by_system'] ??= true;

        $alert = Alert::create($data);

        // Broadcast for real-time bell badge updates across all target departments
        broadcast(new AlertCreatedEvent($alert))->toOthers();

        Log::info('[AlertService] Alert created', [
            'alert_id'       => $alert->id,
            'type'           => $alert->alert_type,
            'severity'       => $alert->severity,
            'tenant_id'      => $alert->tenant_id,
            'participant_id' => $alert->participant_id,
            'target_depts'   => $alert->target_departments,
        ]);

        return $alert;
    }

    /**
     * Acknowledge an alert on behalf of a user.
     * Idempotent: re-acknowledging is a no-op.
     * Throws 403 if the user's department is not in target_departments.
     * Logs to audit trail.
     */
    public function acknowledge(Alert $alert, User $user): Alert
    {
        abort_if(
            ! in_array($user->department, $alert->target_departments ?? [], true),
            403,
            'Your department is not a target for this alert.'
        );

        if ($alert->acknowledged_at !== null) {
            return $alert;  // Already acknowledged — idempotent
        }

        $alert->update([
            'acknowledged_at'          => now(),
            'acknowledged_by_user_id'  => $user->id,
        ]);

        AuditLog::record(
            action: 'alert.acknowledged',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'alert',
            resourceId: $alert->id,
            description: "Alert acknowledged: {$alert->title}",
        );

        return $alert->refresh();
    }

    /**
     * Resolve an alert (marks is_active = false).
     * Resolved alerts are hidden from all user views.
     * Any user in target departments may resolve.
     * Idempotent: re-resolving is a no-op.
     */
    public function resolve(Alert $alert, User $user): Alert
    {
        abort_if(
            ! in_array($user->department, $alert->target_departments ?? [], true),
            403,
            'Your department is not a target for this alert.'
        );

        if (! $alert->is_active) {
            return $alert;  // Already resolved — idempotent
        }

        $alert->update([
            'is_active'   => false,
            'resolved_at' => now(),
        ]);

        AuditLog::record(
            action: 'alert.resolved',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'alert',
            resourceId: $alert->id,
            description: "Alert resolved: {$alert->title}",
        );

        return $alert->refresh();
    }

    /**
     * Return active alerts visible to the given user, ordered newest-first.
     * Applies forUser() scope: tenant match + dept match + is_active = true.
     */
    public function forUser(User $user, int $limit = 20): Collection
    {
        return Alert::forUser($user)
            ->with('participant:id,mrn,first_name,last_name')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Count unread (not acknowledged) active alerts for the given user.
     * Used by the notification bell badge.
     */
    public function unreadCount(User $user): int
    {
        return Alert::forUser($user)->unread()->count();
    }
}
