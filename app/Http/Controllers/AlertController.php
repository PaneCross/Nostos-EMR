<?php

// ─── AlertController ──────────────────────────────────────────────────────────
// Manages clinical alerts for the authenticated user's department.
//
// All endpoints filter by tenant and department via Alert::forUser() scope.
//
// GET /alerts              — paginated alerts for current user
//   ?status=active         — all active alerts (Alerts page full list)
//   ?status=dismissed      — inactive alerts dismissed in the last 30 days
//   (no status)            — bell view: bellVisible() scope (unread + ack'd within 24h)
//   ?severity=critical|warning|info  — filter by severity (active/bell only)
//   ?unread_only=1         — unacknowledged only (active/bell only)
// GET /alerts/unread-count — JSON {count: N} for notification bell polling
// POST /alerts             — manual alert (clinical roles only)
// PATCH /alerts/{id}/acknowledge — mark alert as acknowledged (idempotent)
// PATCH /alerts/{id}/resolve     — mark alert as resolved / inactive (idempotent)
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\User;
use App\Services\AlertService;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AlertController extends Controller
{
    public function __construct(
        private readonly AlertService $alertService,
        private readonly ImpersonationService $impersonationService,
    ) {}

    /**
     * When a super-admin is impersonating another user, alert queries should
     * be scoped to the impersonated user's department so the bell reflects
     * what that user would see. Audit actions (acknowledge/resolve) still use
     * the real authenticated user so the audit log records the SA's identity.
     */
    private function effectiveUser(Request $request): User
    {
        return $this->impersonationService->getImpersonatedUser()
            ?? $request->user();
    }

    /**
     * GET /alerts
     * Browser navigation → renders Alerts/Index Inertia page.
     * JSON API (Accept: application/json) behaviour varies by ?status param:
     *   status=active    → all active alerts, no bell filter (full Alerts page)
     *   status=dismissed → is_active=false, resolved in last 30 days (history tab)
     *   (no status)      → bell view: forUser() + bellVisible() scope
     */
    public function index(Request $request): JsonResponse|InertiaResponse
    {
        $user = $this->effectiveUser($request);

        // Return the full-page alerts view for browser / Inertia navigation
        if (! $request->wantsJson()) {
            return Inertia::render('Alerts/Index');
        }

        $status = $request->input('status');

        if ($status === 'dismissed') {
            // History tab: dismissed (inactive) alerts from the last 30 days,
            // scoped to the user's tenant and department.
            $query = Alert::where('tenant_id', $user->tenant_id)
                ->whereJsonContains('target_departments', $user->department)
                ->where('is_active', false)
                ->whereNotNull('resolved_at')
                ->where('resolved_at', '>=', now()->subDays(30))
                ->with('participant:id,mrn,first_name,last_name')
                ->orderByDesc('resolved_at');

            if ($severity = $request->input('severity')) {
                $query->where('severity', $severity);
            }
        } else {
            // Active alerts: full list (status=active) or bell view (no status).
            $query = Alert::forUser($user)
                ->with('participant:id,mrn,first_name,last_name')
                ->orderByDesc('created_at');

            // Bell view: hide alerts acknowledged more than 24 hours ago so the
            // dropdown self-clears over time without any user action required.
            if ($status !== 'active') {
                $query->bellVisible();
            }

            if ($severity = $request->input('severity')) {
                $query->where('severity', $severity);
            }

            if ($request->boolean('unread_only')) {
                $query->unread();
            }
        }

        return response()->json($query->paginate(30));
    }

    /**
     * GET /alerts/unread-count
     * Returns {count: N} for the notification bell badge.
     * Polled by the frontend every 60 seconds.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $this->alertService->unreadCount($this->effectiveUser($request)),
        ]);
    }

    /**
     * POST /alerts
     * Creates a manual alert. Restricted to admin roles (any department).
     * Body: {title, message, severity, target_departments, participant_id?}
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only admins can create manual alerts
        abort_unless($user->isAdmin(), 403, 'Only admin roles may create manual alerts.');

        $validated = $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'message'             => ['required', 'string'],
            'severity'            => ['required', Rule::in(Alert::SEVERITIES)],
            'target_departments'  => ['required', 'array', 'min:1'],
            'target_departments.*'=> ['required', 'string'],
            'participant_id'      => ['nullable', 'integer', 'exists:emr_participants,id'],
        ]);

        $alert = $this->alertService->create(array_merge($validated, [
            'tenant_id'          => $user->tenant_id,
            'source_module'      => 'manual',
            'alert_type'         => 'manual',
            'created_by_system'  => false,
            'created_by_user_id' => $user->id,
        ]));

        return response()->json($alert->load('participant:id,mrn,first_name,last_name'), 201);
    }

    /**
     * PATCH /alerts/{alert}/acknowledge
     * Marks an alert as acknowledged. Idempotent.
     * Uses effectiveUser() so impersonating super-admins pass the department check.
     * Tenant isolation always uses the real authenticated user.
     */
    public function acknowledge(Request $request, Alert $alert): JsonResponse
    {
        // Tenant isolation uses real user to prevent cross-tenant writes
        abort_if($alert->tenant_id !== $request->user()->tenant_id, 403);

        // Department check uses effective user (handles impersonation)
        $acknowledged = $this->alertService->acknowledge($alert, $this->effectiveUser($request));

        return response()->json($acknowledged);
    }

    /**
     * PATCH /alerts/{alert}/resolve
     * Marks an alert as resolved (is_active = false). Idempotent.
     * Uses effectiveUser() so impersonating super-admins pass the department check.
     * Tenant isolation always uses the real authenticated user.
     */
    public function resolve(Request $request, Alert $alert): JsonResponse
    {
        // Tenant isolation uses real user to prevent cross-tenant writes
        abort_if($alert->tenant_id !== $request->user()->tenant_id, 403);

        // Department check uses effective user (handles impersonation)
        $resolved = $this->alertService->resolve($alert, $this->effectiveUser($request));

        return response()->json($resolved);
    }
}
