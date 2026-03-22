<?php

// ─── AlertController ──────────────────────────────────────────────────────────
// Manages clinical alerts for the authenticated user's department.
//
// All endpoints filter by tenant and department via Alert::forUser() scope.
//
// GET /alerts              — paginated active alerts for current user
// GET /alerts/unread-count — JSON {count: N} for notification bell polling
// POST /alerts             — manual alert (clinical roles only)
// PATCH /alerts/{id}/acknowledge — mark alert as acknowledged (idempotent)
// PATCH /alerts/{id}/resolve     — mark alert as resolved / inactive (idempotent)
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertController extends Controller
{
    public function __construct(private readonly AlertService $alertService) {}

    /**
     * GET /alerts
     * Returns paginated active alerts for the current user's department.
     * Supports ?severity=critical|warning|info, ?unread_only=1
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Alert::forUser($user)
            ->with('participant:id,mrn,first_name,last_name')
            ->orderByDesc('created_at');

        if ($severity = $request->input('severity')) {
            $query->where('severity', $severity);
        }

        if ($request->boolean('unread_only')) {
            $query->unread();
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
            'count' => $this->alertService->unreadCount($request->user()),
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
     * User must be in one of the alert's target departments.
     */
    public function acknowledge(Request $request, Alert $alert): JsonResponse
    {
        // Tenant isolation
        abort_if($alert->tenant_id !== $request->user()->tenant_id, 403);

        $acknowledged = $this->alertService->acknowledge($alert, $request->user());

        return response()->json($acknowledged);
    }

    /**
     * PATCH /alerts/{alert}/resolve
     * Marks an alert as resolved (is_active = false). Idempotent.
     * User must be in one of the alert's target departments.
     */
    public function resolve(Request $request, Alert $alert): JsonResponse
    {
        // Tenant isolation
        abort_if($alert->tenant_id !== $request->user()->tenant_id, 403);

        $resolved = $this->alertService->resolve($alert, $request->user());

        return response()->json($resolved);
    }
}
