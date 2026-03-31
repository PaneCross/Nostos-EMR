<?php

// ─── PermissionService ────────────────────────────────────────────────────────
// Facade over the RBAC permission table (emr_role_permissions) and the sidebar
// navigation structure. Used in two places:
//
//   1. HandleInertiaRequests middleware — shares permissionMap and navGroups
//      with every Inertia response so the React frontend knows what to render.
//
//   2. Controller authorization — use can() for module-level checks, or rely on
//      CheckDepartmentAccess middleware for route-level enforcement.
//
// RBAC model:
//   Every (department, role) pair has a row in emr_role_permissions with boolean
//   flags: can_view, can_create, can_edit, can_delete, can_export.
//   Modules map 1-to-1 with nav items (e.g., 'participants', 'care_plans').
//   Super admins bypass the table entirely and receive all permissions.
//
// allNavGroups() is the single source of truth for:
//   - Every nav item shown in the sidebar
//   - Every module key used in emr_role_permissions
//   - The href that the React router uses for navigation
//   If you add a new module (e.g., Phase 5 scheduling), add it here and seed
//   its permissions in PermissionSeeder.php.
//
// navGroups() filters allNavGroups() to only include items the user can view.
// The React AppShell receives the filtered list and never sees restricted routes.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Collection;

class PermissionService
{
    /**
     * Check if a user has a specific ability on a module.
     */
    public function can(User $user, string $module, string $ability = 'can_view'): bool
    {
        return RolePermission::check($user->department, $user->role, $module, $ability);
    }

    /**
     * Return all modules the user can view (used to build nav).
     */
    public function visibleModules(User $user): Collection
    {
        return RolePermission::visibleModulesFor($user->department, $user->role);
    }

    /**
     * Return a structured permission map for the frontend Inertia share.
     * Shape: { module: { view, create, edit, delete, export } }
     * Super admins (role) and Nostos SA dept users receive all permissions set to true.
     */
    public function permissionMap(User $user): array
    {
        if ($user->isSuperAdmin() || $user->isDeptSuperAdmin()) {
            $allModules = [];
            foreach ($this->allNavGroups() as $group) {
                foreach ($group['items'] as $item) {
                    $allModules[$item['module']] = [
                        'view'   => true,
                        'create' => true,
                        'edit'   => true,
                        'delete' => true,
                        'export' => true,
                    ];
                }
            }
            return $allModules;
        }

        $perms = RolePermission::where('department', $user->department)
            ->where('role', $user->role)
            ->get();

        return $perms->mapWithKeys(function (RolePermission $p) {
            return [
                $p->module => [
                    'view'   => $p->can_view,
                    'create' => $p->can_create,
                    'edit'   => $p->can_edit,
                    'delete' => $p->can_delete,
                    'export' => $p->can_export,
                ],
            ];
        })->toArray();
    }

    /**
     * Return nav groups for the sidebar, filtered by what the user can view.
     * Structure understood by the React sidebar component.
     * Super admins (role) and Nostos SA dept users see all nav groups unfiltered.
     */
    public function navGroups(User $user): array
    {
        if ($user->isSuperAdmin() || $user->isDeptSuperAdmin()) {
            return $this->allNavGroups();
        }

        $visible = $this->visibleModules($user)->flip();

        $allGroups = $this->allNavGroups();
        $result    = [];

        foreach ($allGroups as $group) {
            $filteredItems = array_filter(
                $group['items'],
                fn ($item) => $visible->has($item['module'])
            );

            if (! empty($filteredItems)) {
                $result[] = array_merge($group, ['items' => array_values($filteredItems)]);
            }
        }

        return $result;
    }

    /**
     * The complete sidebar navigation manifest — every group, item, module key,
     * and href in the application.
     *
     * This is intentionally hardcoded (not in a config file) so that module keys
     * stay co-located with their hrefs and labels — easier to grep and rename.
     *
     * IMPORTANT: The 'module' value must match the module column in emr_role_permissions.
     * When adding a new nav item:
     *   1. Add the entry here with label, module, href.
     *   2. Add a row in PermissionSeeder.php for every (department, role) pair.
     *   3. Run php artisan db:seed --class=PermissionSeeder (or migrate:fresh --seed).
     *
     * Items that are not yet built (Phase 5+) are kept here so the sidebar
     * renders them as "Coming Soon" stubs — the ComingSoonController handles those routes.
     */
    private function allNavGroups(): array
    {
        return [
            [
                'label' => 'Participants',
                'icon'  => 'users',
                'items' => [
                    ['label' => 'All Participants', 'module' => 'participants',    'href' => '/participants'],
                    ['label' => 'Enrollment / Intake', 'module' => 'enrollment',  'href' => '/enrollment'],
                ],
            ],
            [
                'label' => 'Clinical',
                'icon'  => 'clipboard',
                'items' => [
                    ['label' => 'Clinical Notes',   'module' => 'clinical_notes',   'href' => '/clinical/notes'],
                    ['label' => 'Vitals',           'module' => 'vitals',           'href' => '/clinical/vitals'],
                    ['label' => 'Assessments',      'module' => 'assessments',      'href' => '/clinical/assessments'],
                    ['label' => 'Care Plans',       'module' => 'care_plans',       'href' => '/clinical/care-plans'],
                    ['label' => 'Medications',      'module' => 'medications',      'href' => '/clinical/medications'],
                    ['label' => 'Orders',           'module' => 'orders',           'href' => '/clinical/orders'],
                ],
            ],
            [
                'label' => 'IDT',
                'icon'  => 'team',
                'items' => [
                    ['label' => 'IDT Dashboard',    'module' => 'idt_dashboard',    'href' => '/idt'],
                    ['label' => 'Meeting Minutes',  'module' => 'idt_minutes',      'href' => '/idt/meetings'],
                    ['label' => 'SDR Tracker',      'module' => 'sdr_tracker',      'href' => '/sdrs'],
                ],
            ],
            [
                'label' => 'Scheduling',
                'icon'  => 'calendar',
                'items' => [
                    ['label' => 'Appointments',     'module' => 'appointments',     'href' => '/schedule'],
                    ['label' => 'Day Center',       'module' => 'day_center',       'href' => '/scheduling/day-center'],
                ],
            ],
            [
                'label' => 'Transportation',
                'icon'  => 'truck',
                'items' => [
                    ['label' => 'Dashboard',        'module' => 'transport_dashboard', 'href' => '/transport'],
                    ['label' => 'Scheduler',        'module' => 'transport_scheduler', 'href' => '/transport/scheduler'],
                    ['label' => 'Dispatch Map',     'module' => 'dispatch_map',        'href' => '/transport/map'],
                    ['label' => 'Cancellations',    'module' => 'cancellations',       'href' => '/transport/cancellations'],
                    ['label' => 'Manifest',          'module' => 'transport_addons',    'href' => '/transport/manifest'],
                    ['label' => 'Vehicles',         'module' => 'vehicles',            'href' => '/transport/vehicles'],
                    ['label' => 'Vendors',          'module' => 'vendors',             'href' => '/transport/vendors'],
                    ['label' => 'Credentials',      'module' => 'transport_credentials', 'href' => '/transport/credentials'],
                    ['label' => 'Broker Settings',  'module' => 'broker_settings',     'href' => '/transport/broker'],
                    ['label' => 'Courtesy Calls',   'module' => 'courtesy_calls',      'href' => '/transport/calls'],
                ],
            ],
            [
                'label' => 'Billing',
                'icon'  => 'dollar',
                'items' => [
                    // Finance Dashboard — existing Phase 6C page
                    ['label' => 'Finance Dashboard',   'module' => 'billing',              'href' => '/finance/dashboard'],
                    // Phase 9B billing engine pages
                    ['label' => 'Encounters',          'module' => 'encounters',           'href' => '/billing/encounters'],
                    ['label' => 'EDI Batches',         'module' => 'edi_batches',          'href' => '/billing/batches'],
                    ['label' => 'Capitation',          'module' => 'capitation',           'href' => '/billing/capitation'],
                    ['label' => 'PDE Records',         'module' => 'pde_records',          'href' => '/billing/pde'],
                    ['label' => 'HPMS Files',          'module' => 'hpms_submissions',     'href' => '/billing/hpms'],
                    ['label' => 'HOS-M Surveys',       'module' => 'hos_m_surveys',        'href' => '/billing/hos-m'],
                    ['label' => 'Revenue Integrity',   'module' => 'revenue_integrity',    'href' => '/billing/revenue-integrity'],
                ],
            ],
            [
                'label' => 'Reports',
                'icon'  => 'chart',
                'items' => [
                    ['label' => 'Reports',          'module' => 'reports',             'href' => '/reports'],
                    ['label' => 'Audit Log',        'module' => 'audit_log',           'href' => '/it-admin/audit'],
                ],
            ],
            // ── W4-1: QA / Compliance (42 CFR §460.120–§460.121) ──────────────
            [
                'label' => 'QA / Compliance',
                'icon'  => 'clipboard',
                'items' => [
                    ['label' => 'Grievances', 'module' => 'grievances', 'href' => '/grievances'],
                ],
            ],
            [
                'label' => 'Administration',
                'icon'  => 'settings',
                'items' => [
                    ['label' => 'Users',            'module' => 'user_management',     'href' => '/it-admin/users'],
                    ['label' => 'Locations',        'module' => 'locations',           'href' => '/admin/locations'],
                    ['label' => 'System Settings',  'module' => 'system_settings',     'href' => '/admin/settings'],
                    ['label' => 'Chat',             'module' => 'chat',                'href' => '/chat'],
                ],
            ],
            // ── Phase 10B: Executive / Leadership ─────────────────────────────
            [
                'label' => 'Executive',
                'icon'  => 'chart',
                'items' => [
                    ['label' => 'Executive Overview', 'module' => 'executive_overview', 'href' => '/dashboard/executive'],
                ],
            ],
            // ── Phase 10B: Nostos Super Admin ──────────────────────────────────
            [
                'label' => 'Nostos Admin',
                'icon'  => 'settings',
                'items' => [
                    ['label' => 'Tenant Management', 'module' => 'tenant_management', 'href' => '/super-admin-panel'],
                ],
            ],
        ];
    }
}
