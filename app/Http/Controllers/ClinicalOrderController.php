<?php

// ─── ClinicalOrderController ──────────────────────────────────────────────────
// W4-7: Lightweight CPOE endpoints. 42 CFR §460.90 — PACE services must be
// ordered and documented. Handles the full clinical order lifecycle for a
// single participant and provides a cross-participant worklist for departments.
//
// Prescriber departments (who can CREATE orders):
//   primary_care, therapies, social_work, idt, it_admin, super_admin (role)
//
// Fulfilling departments (who can ACKNOWLEDGE/RESULT/COMPLETE):
//   Determined per-order by target_department field.
//   Any member of that department + primary_care + it_admin can act.
//
// Routes:
//   GET    /participants/{participant}/orders          index
//   POST   /participants/{participant}/orders          store
//   GET    /participants/{participant}/orders/{order}  show
//   PATCH  /participants/{participant}/orders/{order}  update
//   POST   /participants/{participant}/orders/{order}/acknowledge  acknowledge
//   POST   /participants/{participant}/orders/{order}/result       result
//   POST   /participants/{participant}/orders/{order}/complete     complete
//   POST   /participants/{participant}/orders/{order}/cancel       cancel
//   GET    /orders                                     worklist (cross-participant)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\ClinicalOrder;
use App\Models\Participant;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ClinicalOrderController extends Controller
{
    // Departments authorized to create orders (prescribers per 42 CFR §460.90)
    private const PRESCRIBER_DEPARTMENTS = [
        'primary_care', 'therapies', 'social_work', 'idt', 'it_admin',
    ];

    public function __construct(private readonly AlertService $alertService) {}

    // ── Participant-scoped endpoints ──────────────────────────────────────────

    /**
     * GET /participants/{participant}/orders
     * Returns all orders for the participant, newest first.
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeTenant($participant);

        $orders = ClinicalOrder::where('participant_id', $participant->id)
            ->with(['orderedBy:id,first_name,last_name', 'acknowledgedBy:id,first_name,last_name'])
            ->orderByDesc('ordered_at')
            ->get()
            ->map(fn ($o) => $o->toApiArray());

        return response()->json([
            'orders'       => $orders,
            'total_count'  => $orders->count(),
            'active_count' => $orders->filter(fn ($o) => !in_array($o['status'], ClinicalOrder::TERMINAL_STATUSES))->count(),
        ]);
    }

    /**
     * POST /participants/{participant}/orders
     * Create a new clinical order. Prescriber depts only.
     * Auto-sets target_department from DEPARTMENT_ROUTING.
     * Creates an alert for stat/urgent orders.
     */
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizePrescriber();

        $validated = $request->validate([
            'order_type'         => ['required', 'string', 'in:' . implode(',', ClinicalOrder::ORDER_TYPES)],
            'priority'           => ['required', 'string', 'in:routine,urgent,stat'],
            'instructions'       => ['required', 'string', 'max:5000'],
            'clinical_indication'=> ['nullable', 'string', 'max:2000'],
            'target_facility'    => ['nullable', 'string', 'max:200'],
            'due_date'           => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $user = Auth::user();

        // Auto-route to fulfilling department
        $targetDept = ClinicalOrder::DEPARTMENT_ROUTING[$validated['order_type']] ?? 'primary_care';

        $order = ClinicalOrder::create([
            ...$validated,
            'participant_id'     => $participant->id,
            'tenant_id'          => $user->tenant_id,
            'site_id'            => $participant->site_id,
            'ordered_by_user_id' => $user->id,
            'ordered_at'         => now(),
            'status'             => 'pending',
            'target_department'  => $targetDept,
        ]);

        AuditLog::record(
            action: 'clinical_order.created',
            resourceType: 'ClinicalOrder',
            resourceId: $order->id,
            userId: $user->id,
            newValues: ['order_type' => $order->order_type, 'priority' => $order->priority, 'target_department' => $targetDept],
        );

        // Create alert for stat and urgent orders (42 CFR §460.90 rapid response)
        if (in_array($validated['priority'], ['stat', 'urgent'])) {
            $this->alertService->create([
                'tenant_id'          => $user->tenant_id,
                'alert_type'         => 'clinical_order_' . $validated['priority'],
                'severity'           => $order->alertSeverity(),
                'title'              => strtoupper($validated['priority']) . ' Order: ' . $order->orderTypeLabel(),
                'message'            => ucfirst($validated['priority']) . ' order for ' . $participant->first_name . ' ' . $participant->last_name . ': ' . $order->orderTypeLabel() . '. Ordered by ' . $user->first_name . ' ' . $user->last_name . '.',
                'source_module'      => 'cpoe',
                'target_departments' => [$targetDept, 'primary_care', 'idt'],
                'metadata'           => ['clinical_order_id' => $order->id, 'participant_id' => $participant->id],
            ]);
        }

        return response()->json(['order' => $order->load(['orderedBy:id,first_name,last_name'])->toApiArray()], 201);
    }

    /**
     * GET /participants/{participant}/orders/{order}
     * Single order detail.
     */
    public function show(Request $request, Participant $participant, ClinicalOrder $order): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeOrderBelongsToParticipant($order, $participant);

        $order->load(['orderedBy:id,first_name,last_name', 'acknowledgedBy:id,first_name,last_name']);

        return response()->json(['order' => $order->toApiArray()]);
    }

    /**
     * PATCH /participants/{participant}/orders/{order}
     * Update instructions / due_date / target_facility on a non-terminal order.
     */
    public function update(Request $request, Participant $participant, ClinicalOrder $order): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeOrderBelongsToParticipant($order, $participant);
        $this->authorizePrescriber();

        if ($order->isTerminal()) {
            return response()->json(['error' => 'Cannot update a completed or cancelled order.'], 422);
        }

        $validated = $request->validate([
            'instructions'        => ['sometimes', 'string', 'max:5000'],
            'clinical_indication' => ['nullable', 'string', 'max:2000'],
            'target_facility'     => ['nullable', 'string', 'max:200'],
            'due_date'            => ['nullable', 'date'],
        ]);

        $order->update($validated);

        return response()->json(['order' => $order->fresh(['orderedBy:id,first_name,last_name'])->toApiArray()]);
    }

    /**
     * POST /participants/{participant}/orders/{order}/acknowledge
     * Mark an order as acknowledged by the target department.
     */
    public function acknowledge(Request $request, Participant $participant, ClinicalOrder $order): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeOrderBelongsToParticipant($order, $participant);

        if ($order->status !== 'pending') {
            return response()->json(['error' => 'Only pending orders can be acknowledged.'], 422);
        }

        $user = Auth::user();
        $order->update([
            'status'                  => 'acknowledged',
            'acknowledged_by_user_id' => $user->id,
            'acknowledged_at'         => now(),
        ]);

        AuditLog::record(
            action: 'clinical_order.acknowledged',
            resourceType: 'ClinicalOrder',
            resourceId: $order->id,
            userId: $user->id,
        );

        return response()->json(['order' => $order->fresh(['orderedBy:id,first_name,last_name', 'acknowledgedBy:id,first_name,last_name'])->toApiArray()]);
    }

    /**
     * POST /participants/{participant}/orders/{order}/result
     * Record a result for the order (lab/imaging results).
     */
    public function result(Request $request, Participant $participant, ClinicalOrder $order): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeOrderBelongsToParticipant($order, $participant);

        if ($order->isTerminal()) {
            return response()->json(['error' => 'Cannot result a terminal order.'], 422);
        }

        $validated = $request->validate([
            'result_summary'    => ['required', 'string', 'max:5000'],
            'result_document_id'=> ['nullable', 'integer', 'exists:emr_documents,id'],
        ]);

        $user = Auth::user();
        $order->update([
            'status'             => 'resulted',
            'resulted_at'        => now(),
            'result_summary'     => $validated['result_summary'],
            'result_document_id' => $validated['result_document_id'] ?? null,
        ]);

        AuditLog::record(
            action: 'clinical_order.resulted',
            resourceType: 'ClinicalOrder',
            resourceId: $order->id,
            userId: $user->id,
            newValues: ['result_summary' => substr($validated['result_summary'], 0, 200)],
        );

        return response()->json(['order' => $order->fresh(['orderedBy:id,first_name,last_name'])->toApiArray()]);
    }

    /**
     * POST /participants/{participant}/orders/{order}/complete
     * Mark an order as fully completed.
     */
    public function complete(Request $request, Participant $participant, ClinicalOrder $order): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeOrderBelongsToParticipant($order, $participant);

        if ($order->isTerminal()) {
            return response()->json(['error' => 'Order is already in a terminal state.'], 422);
        }

        $user = Auth::user();
        $order->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        AuditLog::record(
            action: 'clinical_order.completed',
            resourceType: 'ClinicalOrder',
            resourceId: $order->id,
            userId: $user->id,
        );

        return response()->json(['order' => $order->fresh(['orderedBy:id,first_name,last_name'])->toApiArray()]);
    }

    /**
     * POST /participants/{participant}/orders/{order}/cancel
     * Cancel an order with a required reason.
     */
    public function cancel(Request $request, Participant $participant, ClinicalOrder $order): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeOrderBelongsToParticipant($order, $participant);

        if ($order->isTerminal()) {
            // 409 Conflict — cannot cancel an order that has already been completed or cancelled
            return response()->json(['error' => 'Order is already in a terminal state.'], 409);
        }

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ]);

        $user = Auth::user();
        $order->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $validated['cancellation_reason'],
        ]);

        AuditLog::record(
            action: 'clinical_order.cancelled',
            resourceType: 'ClinicalOrder',
            resourceId: $order->id,
            userId: $user->id,
            newValues: ['cancellation_reason' => $validated['cancellation_reason']],
        );

        return response()->json(['order' => $order->fresh(['orderedBy:id,first_name,last_name'])->toApiArray()]);
    }

    // ── Cross-participant worklist ─────────────────────────────────────────────

    /**
     * GET /orders
     * Cross-participant order worklist for the authenticated user's department.
     * Returns pending + in_progress orders for their target_department.
     * super_admin and primary_care see all departments.
     */
    public function worklist(Request $request): \Inertia\Response
    {
        $user     = Auth::user();
        $tenantId = $user->tenant_id;

        $query = ClinicalOrder::forTenant($tenantId)
            ->with(['participant:id,first_name,last_name,mrn', 'orderedBy:id,first_name,last_name'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderByRaw("CASE priority WHEN 'stat' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END")
            ->orderBy('ordered_at');

        // Narrow to relevant department unless super_admin/primary_care (they see all)
        $dept = $user->department;
        if (!$user->isSuperAdmin() && !in_array($dept, ['primary_care', 'it_admin'])) {
            $query->where('target_department', $dept);
        }

        $orders = $query->get()->map(fn ($o) => $o->toApiArray());

        // Counts for the status filter tabs
        $allActive = ClinicalOrder::forTenant($tenantId)->active()->count();
        $pending   = ClinicalOrder::forTenant($tenantId)->pending()->count();

        return Inertia::render('Clinical/Orders', [
            'orders'     => $orders,
            'allCount'   => $allActive,
            'pending'    => $pending,
            'userDept'   => $dept,
        ]);
    }

    // ── Authorization helpers ─────────────────────────────────────────────────

    /** Abort 403 if participant belongs to a different tenant. */
    private function authorizeTenant(Participant $participant): void
    {
        abort_if(
            Auth::user()->tenant_id !== $participant->tenant_id,
            403,
            'Access denied: cross-tenant participant access.'
        );
    }

    /** Abort 403 if order does not belong to the given participant. */
    private function authorizeOrderBelongsToParticipant(ClinicalOrder $order, Participant $participant): void
    {
        abort_if(
            $order->participant_id !== $participant->id,
            403,
            'Order does not belong to this participant.'
        );
    }

    /** Abort 403 if the current user cannot create/edit orders. */
    private function authorizePrescriber(): void
    {
        $user = Auth::user();
        if ($user->isSuperAdmin()) return;
        abort_if(
            !in_array($user->department, self::PRESCRIBER_DEPARTMENTS),
            403,
            'Only clinical staff may create or modify orders.'
        );
    }
}
