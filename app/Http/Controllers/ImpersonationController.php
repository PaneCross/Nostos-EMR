<?php

// ─── ImpersonationController ───────────────────────────────────────────────────
// Super-admin-only endpoints for user impersonation and dashboard view context.
//
// All routes require auth middleware + isSuperAdmin() guard.
//
// Routes:
//   GET    /super-admin/users                 → users()          List tenant users for dropdown
//   POST   /super-admin/impersonate/{user}    → start()          Start full user impersonation
//   DELETE /super-admin/impersonate           → stop()           Stop impersonation
//   POST   /super-admin/view-as              → setViewAs()       Set dashboard dept context
//   DELETE /super-admin/view-as              → clearViewAs()     Clear dashboard dept context
//
// Audit logging:
//   ImpersonationService logs start/stop events with the REAL super-admin's user ID.
//   The impersonated user's ID is never used as the AuditLog actor.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    // Valid department slugs (mirrors shared_users department enum)
    private const VALID_DEPARTMENTS = [
        'primary_care', 'therapies', 'social_work', 'behavioral_health',
        'dietary', 'activities', 'home_care', 'transportation', 'pharmacy',
        'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
    ];

    public function __construct(private ImpersonationService $impersonation) {}

    // ── Guard ─────────────────────────────────────────────────────────────────

    /** Abort with 403 if the authenticated user is not a super-admin. */
    private function requireSuperAdmin(): void
    {
        abort_if(! Auth::user()->isSuperAdmin(), 403, 'Super admin access required.');
    }

    // ── User list for dropdown ────────────────────────────────────────────────

    /**
     * Return all users in the current tenant (lightweight, for impersonation dropdown).
     * Excludes the requesting super-admin themselves (no self-impersonation).
     */
    public function users(Request $request): JsonResponse
    {
        $this->requireSuperAdmin();

        $superAdmin = Auth::user();

        $users = User::where('tenant_id', $superAdmin->tenant_id)
            ->where('id', '!=', $superAdmin->id)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'department', 'role', 'is_active'])
            ->map(fn ($u) => [
                'id'               => $u->id,
                'first_name'       => $u->first_name,
                'last_name'        => $u->last_name,
                'email'            => $u->email,
                'department'       => $u->department,
                'department_label' => $u->departmentLabel(),
                'role'             => $u->role,
            ]);

        return response()->json(['users' => $users]);
    }

    // ── Full user impersonation ───────────────────────────────────────────────

    /**
     * Start impersonating $user.
     * Target must be in the same tenant as the super-admin.
     */
    public function start(Request $request, User $user): JsonResponse
    {
        $this->requireSuperAdmin();

        $superAdmin = Auth::user();

        // Tenant isolation: cannot impersonate users from a different tenant
        if ($user->tenant_id !== $superAdmin->tenant_id) {
            abort(403, 'Cannot impersonate a user from a different tenant.');
        }

        $this->impersonation->start($user, $superAdmin);

        return response()->json([
            'impersonating' => true,
            'user' => [
                'id'               => $user->id,
                'first_name'       => $user->first_name,
                'last_name'        => $user->last_name,
                'department'       => $user->department,
                'department_label' => $user->departmentLabel(),
                'role'             => $user->role,
            ],
        ]);
    }

    /**
     * Stop active impersonation and return to full super-admin view.
     */
    public function stop(Request $request): JsonResponse
    {
        $this->requireSuperAdmin();

        $this->impersonation->stop(Auth::user());

        return response()->json(['impersonating' => false]);
    }

    // ── Dashboard "View as Department" context ────────────────────────────────

    /**
     * Set the dashboard view-as department context.
     * Only affects which module cards the dashboard renders.
     * Does NOT restrict access to any other pages.
     */
    public function setViewAs(Request $request): JsonResponse
    {
        $this->requireSuperAdmin();

        $validated = $request->validate([
            'department' => ['required', 'string', 'in:' . implode(',', self::VALID_DEPARTMENTS)],
        ]);

        $this->impersonation->setViewAs($validated['department']);

        return response()->json(['viewing_as_department' => $validated['department']]);
    }

    /**
     * Clear the dashboard view-as context (dashboard reverts to 'it_admin' default).
     */
    public function clearViewAs(Request $request): JsonResponse
    {
        $this->requireSuperAdmin();

        $this->impersonation->clearViewAs();

        return response()->json(['viewing_as_department' => null]);
    }
}
