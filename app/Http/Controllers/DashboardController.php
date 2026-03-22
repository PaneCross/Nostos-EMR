<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\ImpersonationService;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private PermissionService $permissionService,
        private ImpersonationService $impersonation,
    ) {}

    public function show(Request $request, string $department): Response|\Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();

        if ($user->isDeptSuperAdmin()) {
            // Nostos SA dept users can view any department's dashboard.
            // Default to 'super_admin' (their own dashboard) if accessing root.
            // No impersonation system for dept SA — they see raw data across tenants.
        } elseif ($user->isExecutive()) {
            // Executives can only access their own executive dashboard.
            if ($department !== 'executive') {
                return Inertia::location('/dashboard/executive');
            }
        } elseif ($user->isSuperAdmin()) {
            // Full impersonation active: redirect to the impersonated user's dept dashboard
            if ($this->impersonation->isImpersonating()) {
                $effective = $this->impersonation->getImpersonatedUser();
                if ($effective && $department !== $effective->department) {
                    return Inertia::location("/dashboard/{$effective->department}");
                }
                // Already on the correct dept — render it
            } else {
                // No impersonation: use "Dashboard View" selector context (defaults to 'it_admin')
                $department = $this->impersonation->getViewAsDepartment();
            }
            // Super-admins see all nav items and all pages regardless of this dept context.
            // This only controls which module cards appear on the dashboard.
        } elseif ($user->department !== $department) {
            // Regular user: enforce own dept only
            AuditLog::record(
                action:      'unauthorized_access',
                tenantId:    $user->tenant_id,
                userId:      $user->id,
                description: "Attempted to access {$department} dashboard from {$user->department}",
            );

            abort(403, 'Access denied. You are not authorised to view this department.');
        }

        // Effective user for permission/nav rendering (impersonated user or real user)
        $effective = ($user->isSuperAdmin() && $this->impersonation->isImpersonating())
            ? ($this->impersonation->getImpersonatedUser() ?? $user)
            : $user;

        return Inertia::render('Dashboard/Index', [
            'department'      => $department,
            'departmentLabel' => $effective->departmentLabel(),
            'role'            => $effective->role,
            'navGroups'       => $this->permissionService->navGroups($effective),
            'permissions'     => $this->permissionService->permissionMap($effective),
        ]);
    }

    /** Redirect / to the user's department dashboard. */
    public function redirect(): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();

        // Nostos SA dept: go to their super_admin dashboard
        if ($user->isDeptSuperAdmin()) {
            return redirect('/dashboard/super_admin');
        }

        // Executive: go to executive dashboard
        if ($user->isExecutive()) {
            return redirect('/dashboard/executive');
        }

        if ($user->isSuperAdmin()) {
            // Super-admin: redirect to their dashboard view context (defaults to it_admin)
            $dept = $this->impersonation->isImpersonating()
                ? ($this->impersonation->getImpersonatedUser()?->department ?? 'it_admin')
                : $this->impersonation->getViewAsDepartment();

            return redirect("/dashboard/{$dept}");
        }

        return redirect("/dashboard/{$user->department}");
    }
}
