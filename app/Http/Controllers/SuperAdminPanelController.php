<?php

// ─── SuperAdminPanelController ────────────────────────────────────────────────
// Phase 10B — Nostos Super Admin panel for platform-level tenant management.
//
// Accessible only to users with department='super_admin' (Nostos staff) or
// role='super_admin' (tj@nostos.tech).
//
// The panel is NOT for PACE organization IT admins — it is Nostos-internal.
// IT admins use /it-admin/* for their own tenant's user/integration management.
//
// Routes:
//   GET  /super-admin-panel           → Inertia SuperAdmin/Index page
//   GET  /super-admin-panel/tenants   → JSON: all tenants with participant + user counts
//   GET  /super-admin-panel/health    → JSON: system health (table counts, queues, jobs)
//   POST /super-admin-panel/onboard   → Create tenant + first site + provision admin user
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminPanelController extends Controller
{
    // ── Guard ─────────────────────────────────────────────────────────────────

    private function requireNostosSuperAdmin(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && ! $user->isDeptSuperAdmin()) {
            abort(403, 'Nostos Super Admin access required.');
        }
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    /**
     * Render the Super Admin panel (Inertia page).
     * Pre-loads tenant count + basic health summary for initial render.
     */
    public function index(): Response
    {
        $this->requireNostosSuperAdmin();

        $tenantCount    = Tenant::count();
        $userCount      = User::count();
        $participantCount = DB::table('emr_participants')->whereNull('deleted_at')->count();

        AuditLog::record(
            action:      'super_admin_panel.accessed',
            tenantId:    Auth::user()->tenant_id,
            userId:      Auth::user()->id,
            description: 'Super admin panel accessed.',
        );

        return Inertia::render('SuperAdmin/Index', [
            'summary' => [
                'tenant_count'      => $tenantCount,
                'user_count'        => $userCount,
                'participant_count' => $participantCount,
            ],
        ]);
    }

    // ── JSON Endpoints ────────────────────────────────────────────────────────

    /**
     * All tenants with participant + user counts for the tenant list table.
     */
    public function tenants(): JsonResponse
    {
        $this->requireNostosSuperAdmin();

        $tenants = Tenant::orderBy('name')->get();

        $data = $tenants->map(function (Tenant $tenant) {
            $userCount = User::where('tenant_id', $tenant->id)->count();
            $participantCount = DB::table('emr_participants')
                ->where('tenant_id', $tenant->id)
                ->whereNull('deleted_at')
                ->count();
            $siteCount = Site::where('tenant_id', $tenant->id)->count();

            return [
                'id'                => $tenant->id,
                'name'              => $tenant->name,
                'transport_mode'    => $tenant->transport_mode,
                'user_count'        => $userCount,
                'participant_count' => $participantCount,
                'site_count'        => $siteCount,
                'created_at'        => $tenant->created_at?->toDateString(),
            ];
        });

        return response()->json(['tenants' => $data]);
    }

    /**
     * System health: row counts for key tables, recent failed jobs, queue depth.
     */
    public function health(): JsonResponse
    {
        $this->requireNostosSuperAdmin();

        $tables = [
            'emr_participants'             => 'Participants',
            'emr_clinical_notes'           => 'Clinical Notes',
            'emr_care_plans'               => 'Care Plans',
            'emr_medications'              => 'Medications',
            'emr_appointments'             => 'Appointments',
            'emr_alerts'                   => 'Alerts',
            'emr_transport_requests'       => 'Transport Requests',
            'shared_audit_logs'            => 'Audit Log Entries',
            'emr_chat_messages'            => 'Chat Messages',
            'emr_integration_log'          => 'Integration Events',
        ];

        $counts = [];
        foreach ($tables as $table => $label) {
            try {
                $counts[] = [
                    'table' => $label,
                    'count' => DB::table($table)->count(),
                ];
            } catch (\Exception) {
                $counts[] = ['table' => $label, 'count' => null];
            }
        }

        $failedJobs    = DB::table('failed_jobs')->count();
        $pendingJobs   = DB::table('jobs')->count();

        return response()->json([
            'table_counts' => $counts,
            'queues' => [
                'failed_jobs'  => $failedJobs,
                'pending_jobs' => $pendingJobs,
            ],
        ]);
    }

    /**
     * Onboarding wizard — create a new tenant with its first site and admin user.
     *
     * Wizard steps bundled as a single POST (all-or-nothing DB transaction):
     *   Step 1: Tenant details
     *   Step 2: First site
     *   Step 3: Admin user for the new tenant
     *   Step 4: Permissions (auto-seeded from PermissionSeeder defaults)
     *   Step 5: Confirmation (handled client-side — this endpoint does the work)
     */
    public function onboard(Request $request): JsonResponse
    {
        $this->requireNostosSuperAdmin();

        $data = $request->validate([
            // Step 1 — Tenant
            'tenant_name'          => 'required|string|max:120|unique:shared_tenants,name',
            'transport_mode'       => 'required|in:direct,broker',
            'auto_logout_minutes'  => 'required|integer|min:5|max:120',
            // Step 2 — First site
            'site_name'            => 'required|string|max:100',
            'site_city'            => 'nullable|string|max:80',
            'site_state'           => 'nullable|string|max:2',
            // Step 3 — Admin user
            'admin_first_name'     => 'required|string|max:60',
            'admin_last_name'      => 'required|string|max:60',
            'admin_email'          => 'required|email|unique:shared_users,email',
            'admin_department'     => 'required|in:it_admin,enrollment',
        ]);

        $actor = Auth::user();

        $result = DB::transaction(function () use ($data, $actor) {
            // Create tenant (slug derived from name; unique-ified with short random suffix)
            $baseSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($data['tenant_name'])));
            $slug     = $baseSlug . '-' . substr(str_replace('-', '', \Illuminate\Support\Str::uuid()), 0, 6);
            $tenant = Tenant::create([
                'name'                => $data['tenant_name'],
                'slug'                => $slug,
                'transport_mode'      => $data['transport_mode'],
                'auto_logout_minutes' => $data['auto_logout_minutes'],
            ]);

            // Create first site
            $mrnPrefix = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $data['site_name']), 0, 4));
            $site = Site::create([
                'tenant_id'  => $tenant->id,
                'name'       => $data['site_name'],
                'mrn_prefix' => $mrnPrefix ?: 'SITE',
                'city'       => $data['site_city'] ?? null,
                'state'      => $data['site_state'] ?? null,
                'is_active'  => true,
            ]);

            // Create admin user (no password — OTP-only auth)
            $user = User::create([
                'tenant_id'              => $tenant->id,
                'site_id'                => $site->id,
                'first_name'             => $data['admin_first_name'],
                'last_name'              => $data['admin_last_name'],
                'email'                  => $data['admin_email'],
                'department'             => $data['admin_department'],
                'role'                   => 'admin',
                'is_active'              => true,
                'provisioned_by_user_id' => $actor->id,
                'provisioned_at'         => now(),
            ]);

            AuditLog::record(
                action:       'tenant.onboarded',
                tenantId:     $actor->tenant_id,
                userId:       $actor->id,
                resourceType: 'tenant',
                resourceId:   $tenant->id,
                description:  "Onboarded tenant '{$tenant->name}' with site '{$site->name}' and admin {$user->email}.",
            );

            return [
                'tenant_id' => $tenant->id,
                'site_id'   => $site->id,
                'user_id'   => $user->id,
            ];
        });

        return response()->json([
            'message' => 'Tenant onboarded successfully.',
            'ids'     => $result,
        ], 201);
    }
}
