<?php

// ─── ImpersonationService ─────────────────────────────────────────────────────
// Manages super-admin impersonation and "Dashboard View" dept context.
//
// Two session keys:
//   impersonating_user_id  → full user impersonation (header dropdown)
//   viewing_as_department  → lightweight dashboard-only dept preview (nav selector)
//
// HIPAA / Audit rule:
//   All AuditLog entries always use the REAL super-admin's user ID.
//   The impersonated user's ID is NEVER passed to AuditLog::record().
//
// Default state (no session values):
//   - Super-admin sees all nav items and all pages without restriction.
//   - Dashboard defaults to 'it_admin' module cards (cleanest super-admin view).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class ImpersonationService
{
    // ── Session key constants ─────────────────────────────────────────────────

    public const SESSION_USER_ID = 'impersonating_user_id';
    public const SESSION_VIEW_AS = 'viewing_as_department';

    // ── Full user impersonation ───────────────────────────────────────────────

    /**
     * Start impersonating $target as $actor (must be super_admin).
     * Sets session key; clears any previous viewing_as context.
     * Audit log records actor (super-admin), not the target.
     */
    public function start(User $target, User $actor): void
    {
        session([self::SESSION_USER_ID => $target->id]);
        session()->forget(self::SESSION_VIEW_AS);

        AuditLog::record(
            action:       'super_admin.impersonation.start',
            tenantId:     $actor->tenant_id,
            userId:       $actor->id,
            resourceType: 'User',
            resourceId:   $target->id,
            description:  "Super admin started impersonating {$target->first_name} {$target->last_name} ({$target->department})",
            newValues:    [
                'target_user_id'   => $target->id,
                'target_name'      => "{$target->first_name} {$target->last_name}",
                'target_department'=> $target->department,
                'target_role'      => $target->role,
            ],
        );
    }

    /**
     * Stop active impersonation.
     * Clears both session keys; restores super-admin's own view.
     */
    public function stop(User $actor): void
    {
        $targetId = session(self::SESSION_USER_ID);

        session()->forget(self::SESSION_USER_ID);
        session()->forget(self::SESSION_VIEW_AS);

        AuditLog::record(
            action:      'super_admin.impersonation.stop',
            tenantId:    $actor->tenant_id,
            userId:      $actor->id,
            resourceType:'User',
            resourceId:  $targetId,
            description: "Super admin stopped impersonation",
        );
    }

    /** Return the currently-impersonated user, or null if not impersonating. */
    public function getImpersonatedUser(): ?User
    {
        $id = session(self::SESSION_USER_ID);
        return $id ? User::find($id) : null;
    }

    /** Whether the session has an active full-user impersonation. */
    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_USER_ID);
    }

    // ── Dashboard "View as Department" context ────────────────────────────────

    /**
     * Set a lightweight department context for Dashboard/Index.tsx.
     * ONLY affects which module cards the dashboard shows.
     * Does NOT restrict access to any other part of the app.
     */
    public function setViewAs(string $department): void
    {
        session([self::SESSION_VIEW_AS => $department]);
    }

    /** Clear the dashboard view-as context (dashboard falls back to 'it_admin' default). */
    public function clearViewAs(): void
    {
        session()->forget(self::SESSION_VIEW_AS);
    }

    /** Return the currently selected dashboard department context, defaulting to 'it_admin'. */
    public function getViewAsDepartment(): string
    {
        return session(self::SESSION_VIEW_AS, 'it_admin');
    }

    /** Whether a custom dashboard view-as dept is explicitly set in the session. */
    public function hasViewAs(): bool
    {
        return session()->has(self::SESSION_VIEW_AS);
    }
}
