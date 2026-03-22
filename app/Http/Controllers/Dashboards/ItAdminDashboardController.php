<?php

// ─── ItAdminDashboardController ───────────────────────────────────────────────
// JSON widget endpoints for the IT Administration department live dashboard.
// All endpoints require the it_admin department (or super_admin).
// This is distinct from ItAdminController (which serves the full IT Admin pages
// at /it-admin/* for user provisioning, integration management, and audit log).
//
// Routes (GET, all under /dashboards/it-admin/):
//   users        — recently provisioned + deactivated users
//   integrations — per connector health: last message, status, error count
//   audit        — last 20 audit log entries (filterable by action)
//   config       — tenant configuration: transport_mode, auto_logout, sites
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IntegrationLog;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ItAdminDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    /** Abort 403 if the authenticated user is not it_admin or super_admin. */
    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'it_admin') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * User management summary: recently provisioned users + recently deactivated users.
     * Returns last 5 of each for at-a-glance IT dashboard view.
     */
    public function users(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        // Recently provisioned (created in last 30 days)
        $recentlyProvisioned = User::where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->first_name . ' ' . $u->last_name,
                'email'      => $u->email,
                'department' => $u->department,
                'role'       => $u->role,
                'is_active'  => $u->is_active,
                'created_at' => $u->created_at?->diffForHumans(),
            ]);

        // Recently deactivated (is_active=false, updated in last 30 days)
        $recentlyDeactivated = User::where('tenant_id', $tenantId)
            ->where('is_active', false)
            ->where('updated_at', '>=', now()->subDays(30))
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->first_name . ' ' . $u->last_name,
                'email'      => $u->email,
                'department' => $u->department,
                'is_active'  => $u->is_active,
                'updated_at' => $u->updated_at?->diffForHumans(),
            ]);

        $totalActive   = User::where('tenant_id', $tenantId)->where('is_active', true)->count();
        $totalInactive = User::where('tenant_id', $tenantId)->where('is_active', false)->count();

        return response()->json([
            'recently_provisioned'  => $recentlyProvisioned,
            'recently_deactivated'  => $recentlyDeactivated,
            'total_active'          => $totalActive,
            'total_inactive'        => $totalInactive,
        ]);
    }

    /**
     * Integration health status per connector type.
     * Red indicator if last message > 24 hours ago OR any failed messages exist.
     * Connectors: hl7_adt, lab_results, pharmacy_ncpdp, other.
     */
    public function integrations(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $connectors = [];
        foreach (IntegrationLog::CONNECTOR_TYPES as $connectorType) {
            // Get the last log entry for this connector
            $last = IntegrationLog::where('tenant_id', $tenantId)
                ->forConnector($connectorType)
                ->orderByDesc('created_at')
                ->first();

            $errorCount = IntegrationLog::where('tenant_id', $tenantId)
                ->forConnector($connectorType)
                ->where('status', 'failed')
                ->count();

            $lastMessageAt = $last?->created_at;
            $isStale = $lastMessageAt
                ? $lastMessageAt->diffInHours(now()) > 24
                : true; // Never received = stale

            $connectors[] = [
                'connector_type'    => $connectorType,
                'last_status'       => $last?->status,
                'last_message_at'   => $lastMessageAt?->diffForHumans(),
                'error_count'       => $errorCount,
                'is_healthy'        => ! $isStale && $errorCount === 0,
                'is_stale'          => $isStale,
                'total_today'       => IntegrationLog::where('tenant_id', $tenantId)
                    ->forConnector($connectorType)
                    ->whereDate('created_at', today())
                    ->count(),
            ];
        }

        return response()->json(['connectors' => $connectors]);
    }

    /**
     * Last 20 audit log entries for this tenant, filterable by action prefix.
     * Supports ?action=fhir.read to filter FHIR reads, etc.
     */
    public function audit(Request $request): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $query = AuditLog::where('tenant_id', $tenantId)
            ->with(['user:id,first_name,last_name,department'])
            ->orderBy('created_at', 'desc')
            ->limit(20);

        // Optional action filter (e.g. ?action=fhir.read)
        if ($action = $request->query('action')) {
            $query->where('action', 'like', $action . '%');
        }

        $entries = $query->get()
            ->map(fn (AuditLog $log) => [
                'id'            => $log->id,
                'action'        => $log->action,
                'user'          => $log->user ? [
                    'id'         => $log->user->id,
                    'name'       => $log->user->first_name . ' ' . $log->user->last_name,
                    'department' => $log->user->department,
                ] : null,
                'resource_type' => $log->resource_type,
                'resource_id'   => $log->resource_id,
                'ip_address'    => $log->ip_address,
                'created_at'    => $log->created_at?->diffForHumans(),
            ]);

        return response()->json(['entries' => $entries]);
    }

    /**
     * Tenant configuration panel data.
     * Returns editable settings: transport_mode, auto_logout_minutes.
     * Also returns the site list for the site management section.
     * POST updates to these settings go through ItAdminController, not here.
     */
    public function config(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $tenant = Tenant::find($tenantId);
        $sites  = Site::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get()
            ->map(fn (Site $s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'mrn_prefix' => $s->mrn_prefix,
            ]);

        return response()->json([
            'transport_mode'      => $tenant?->transport_mode ?? 'direct',
            'auto_logout_minutes' => $tenant?->auto_logout_minutes ?? 15,
            'sites'               => $sites,
            'site_count'          => $sites->count(),
        ]);
    }
}
