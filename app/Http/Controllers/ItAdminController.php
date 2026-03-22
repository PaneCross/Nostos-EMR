<?php

// ─── ItAdminController ────────────────────────────────────────────────────────
// Powers the IT Admin panel — integrations monitoring, user management, audit log.
//
// Route list:
//   GET  /it-admin/integrations               → integrations()  (Inertia page)
//   GET  /it-admin/integrations/log           → integrationLog() (JSON paginated)
//   POST /it-admin/integrations/{log}/retry   → retryIntegration() (re-dispatch job)
//   GET  /it-admin/users                      → users()  (Inertia page)
//   POST /it-admin/users                      → provisionUser() (create user + send welcome email)
//   POST /it-admin/users/{user}/deactivate    → deactivateUser() + invalidate sessions
//   POST /it-admin/users/{user}/reactivate    → reactivateUser()
//   POST /it-admin/users/{user}/reset-access  → resetAccess() (invalidate sessions only)
//   GET  /it-admin/audit                      → audit()  (Inertia page)
//   GET  /it-admin/audit/log                  → auditLog()  (JSON paginated)
//   GET  /it-admin/audit/export               → exportAuditCsv() (CSV download)
//
// Authorization: all routes require department='it_admin'.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Jobs\ProcessHl7AdtJob;
use App\Jobs\ProcessLabResultJob;
use App\Mail\WelcomeEmail;
use App\Models\AuditLog;
use App\Models\IntegrationLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ItAdminController extends Controller
{
    // ── Integrations ──────────────────────────────────────────────────────────

    /**
     * Render the IT Admin integrations monitoring panel.
     * Shows connector status summary + recent message log.
     *
     * GET /it-admin/integrations
     */
    public function integrations(Request $request): InertiaResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        // Connector status summary for the status cards
        $summary = [];
        foreach (IntegrationLog::CONNECTOR_TYPES as $type) {
            $last = IntegrationLog::forTenant($tenantId)
                ->forConnector($type)
                ->latest('created_at')
                ->first();

            $summary[$type] = [
                'last_received'  => $last?->created_at,
                'last_status'    => $last?->status,
                'failed_count'   => IntegrationLog::forTenant($tenantId)->forConnector($type)->failed()->count(),
            ];
        }

        // Recent 20 log entries for the initial table
        $recentLog = IntegrationLog::forTenant($tenantId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'connector_type', 'direction', 'status', 'error_message', 'retry_count', 'created_at', 'processed_at']);

        return Inertia::render('ItAdmin/Integrations', [
            'summary'        => $summary,
            'recentLog'      => $recentLog,
            'connectorTypes' => IntegrationLog::CONNECTOR_TYPES,
        ]);
    }

    /**
     * Return a paginated integration log (JSON) for the IT Admin log viewer.
     * Supports filtering by connector_type and status.
     *
     * GET /it-admin/integrations/log
     */
    public function integrationLog(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $query = IntegrationLog::forTenant($tenantId)->orderByDesc('created_at');

        if ($request->filled('connector_type')) {
            $query->forConnector($request->query('connector_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $log = $query->paginate(50);

        return response()->json($log);
    }

    /**
     * Retry a failed integration log entry by re-dispatching its job.
     * Increments retry_count and sets status to 'retried'.
     *
     * POST /it-admin/integrations/{log}/retry
     */
    public function retryIntegration(Request $request, IntegrationLog $log): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        // Tenant isolation — IT Admins can only retry their own tenant's entries
        abort_if($log->tenant_id !== $tenantId, 403, 'Access denied');
        abort_if($log->status !== 'failed', 422, 'Only failed entries can be retried');

        $log->markRetried();

        // Re-dispatch the correct job based on connector type
        match ($log->connector_type) {
            'hl7_adt'     => ProcessHl7AdtJob::dispatch($log->id, $log->raw_payload, $tenantId)->onQueue('integrations'),
            'lab_results' => ProcessLabResultJob::dispatch($log->id, $log->raw_payload, $tenantId)->onQueue('integrations'),
            default       => null, // other connector types: no-op for now
        };

        AuditLog::record(
            action:       'it_admin.integration.retry',
            resourceType: 'IntegrationLog',
            resourceId:   $log->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
        );

        return response()->json(['retried' => true, 'retry_count' => $log->retry_count]);
    }

    // ── User Management ───────────────────────────────────────────────────────

    /**
     * Render the IT Admin user management panel.
     * Shows all users for the tenant with department + active status.
     *
     * GET /it-admin/users
     */
    public function users(Request $request): InertiaResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $users = User::where('tenant_id', $tenantId)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'department', 'is_active', 'created_at']);

        return Inertia::render('ItAdmin/Users', [
            'users' => $users,
        ]);
    }

    /**
     * Provision a new user account and send a welcome email.
     * IT Admin provisions all accounts — no self-registration.
     *
     * POST /it-admin/users
     */
    public function provisionUser(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'unique:shared_users,email'],
            'department' => ['required', 'string', 'in:primary_care,therapies,social_work,behavioral_health,dietary,activities,home_care,transportation,pharmacy,idt,enrollment,finance,qa_compliance,it_admin'],
            'role'       => ['required', 'string', 'in:admin,standard'],
        ]);

        $user = User::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'is_active' => true,
        ]));

        // Send welcome email via Mailpit in dev (http://localhost:8025)
        Mail::to($user->email)->send(new WelcomeEmail($user));

        AuditLog::record(
            action:       'it_admin.user.provisioned',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
            newValues:    ['email' => $user->email, 'department' => $user->department],
        );

        return response()->json(['user' => $user->only('id', 'first_name', 'last_name', 'email', 'department', 'role', 'is_active')], 201);
    }

    /**
     * Deactivate a user account and invalidate all active sessions.
     * Sets is_active=false so they cannot request new OTP codes.
     *
     * POST /it-admin/users/{user}/deactivate
     */
    public function deactivateUser(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        abort_if($user->tenant_id !== $tenantId, 403, 'Access denied');
        abort_if($user->id === $request->user()->id, 422, 'Cannot deactivate your own account');

        $user->update(['is_active' => false]);

        // Invalidate all active sessions so the user is logged out immediately
        $this->invalidateSessions($user->id);

        AuditLog::record(
            action:       'it_admin.user.deactivated',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
            newValues:    ['is_active' => false],
        );

        return response()->json(['deactivated' => true]);
    }

    /**
     * Reset a user's access by invalidating all their active sessions.
     * User remains active but must sign in again.
     *
     * POST /it-admin/users/{user}/reset-access
     */
    public function resetAccess(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        abort_if($user->tenant_id !== $tenantId, 403, 'Access denied');

        $this->invalidateSessions($user->id);

        AuditLog::record(
            action:       'it_admin.user.access_reset',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
        );

        return response()->json(['reset' => true]);
    }

    /**
     * Reactivate a previously deactivated user account.
     *
     * POST /it-admin/users/{user}/reactivate
     */
    public function reactivateUser(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        abort_if($user->tenant_id !== $tenantId, 403, 'Access denied');

        $user->update(['is_active' => true]);

        AuditLog::record(
            action:       'it_admin.user.reactivated',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
            newValues:    ['is_active' => true],
        );

        return response()->json(['reactivated' => true]);
    }

    // ── Audit Log Viewer ──────────────────────────────────────────────────────

    /**
     * Render the IT Admin audit log viewer page.
     *
     * GET /it-admin/audit
     */
    public function audit(Request $request): InertiaResponse
    {
        $this->requireItAdmin($request);

        return Inertia::render('ItAdmin/Audit', [
            'initialCount' => AuditLog::where('tenant_id', $request->user()->tenant_id)->count(),
        ]);
    }

    /**
     * Return a paginated audit log (JSON) for the IT Admin audit viewer.
     * Supports filtering by action, user_id, resource_type, and date range.
     *
     * GET /it-admin/audit/log
     */
    public function auditLog(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $query = AuditLog::where('tenant_id', $tenantId)
            ->with('user:id,first_name,last_name')
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->query('action') . '%');
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->query('resource_type'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        return response()->json($query->paginate(100));
    }

    /**
     * Export audit log as CSV for compliance purposes.
     *
     * GET /it-admin/audit/export
     */
    public function exportAuditCsv(Request $request): Response
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $rows = AuditLog::where('tenant_id', $tenantId)
            ->with('user:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->limit(10000) // cap at 10k rows for export
            ->get();

        $filename = 'audit_log_' . now()->format('Y-m-d') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $csv = "ID,Action,Resource Type,Resource ID,User,IP Address,Created At\n";
        foreach ($rows as $row) {
            $userName = $row->user ? "{$row->user->first_name} {$row->user->last_name}" : 'System';
            $csv .= implode(',', [
                $row->id,
                $row->action,
                $row->resource_type ?? '',
                $row->resource_id   ?? '',
                $userName,
                $row->ip_address    ?? '',
                $row->created_at,
            ]) . "\n";
        }

        return response($csv, 200, $headers);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Delete all database sessions for a user, forcing immediate logout.
     * Uses the sessions table (configured in config/session.php).
     */
    private function invalidateSessions(int $userId): void
    {
        DB::table('sessions')->where('user_id', $userId)->delete();
    }

    // ── Authorization helper ──────────────────────────────────────────────────

    /** All IT Admin panel endpoints require department='it_admin'. */
    private function requireItAdmin(Request $request): void
    {
        abort_if($request->user()->department !== 'it_admin', 403, 'IT Admin access required');
    }
}
