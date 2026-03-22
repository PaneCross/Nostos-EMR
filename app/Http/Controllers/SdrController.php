<?php

// ─── SdrController ────────────────────────────────────────────────────────────
// Manages Service Delivery Requests (SDRs) with 72-hour enforcement.
//
// Routes:
//   GET  /sdrs               — Inertia page: SDR index with tabs
//   POST /sdrs               — submit a new SDR
//   GET  /sdrs/{id}          — SDR detail (JSON)
//   PATCH /sdrs/{id}         — update status / assign / completion notes
//   DELETE /sdrs/{id}        — soft delete (cancel)
//
// Broadcasts SdrCreatedEvent on submission for real-time dept queue update.
// 72-hour due_at is enforced by Sdr model boot() — cannot be overridden.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Events\SdrCreatedEvent;
use App\Models\AuditLog;
use App\Models\Sdr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SdrController extends Controller
{
    private function authorizeForTenant(Sdr $sdr, $user): void
    {
        abort_if($sdr->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /sdrs
     * Inertia page: SDR index with My Department | Assigned To Me | Overdue | All (QA only) tabs.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // My department's requests (incoming)
        $myDept = Sdr::where('tenant_id', $user->tenant_id)
            ->forDepartment($user->department)
            ->open()
            ->with(['participant:id,mrn,first_name,last_name', 'requestingUser:id,first_name,last_name'])
            ->orderBy('due_at')
            ->get();

        // Assigned directly to this user
        $assignedToMe = Sdr::where('tenant_id', $user->tenant_id)
            ->where('assigned_to_user_id', $user->id)
            ->open()
            ->with(['participant:id,mrn,first_name,last_name'])
            ->orderBy('due_at')
            ->get();

        // Overdue across tenant
        $overdue = Sdr::where('tenant_id', $user->tenant_id)
            ->overdue()
            ->with(['participant:id,mrn,first_name,last_name', 'requestingUser:id,first_name,last_name'])
            ->orderBy('due_at')
            ->get();

        // All SDRs (QA/Compliance only)
        $allSdrs = null;
        if ($user->department === 'qa_compliance') {
            $allSdrs = Sdr::where('tenant_id', $user->tenant_id)
                ->with(['participant:id,mrn,first_name,last_name', 'requestingUser:id,first_name,last_name'])
                ->orderBy('due_at')
                ->paginate(50);
        }

        return Inertia::render('Sdrs/Index', [
            'myDeptSdrs'    => $myDept,
            'assignedToMe'  => $assignedToMe,
            'overdueSdrs'   => $overdue,
            'allSdrs'       => $allSdrs,
            'userDept'      => $user->department,
            'requestTypes'  => Sdr::REQUEST_TYPES,
            'departments'   => [
                'primary_care', 'therapies', 'social_work', 'behavioral_health',
                'dietary', 'activities', 'home_care', 'transportation', 'pharmacy', 'idt',
            ],
        ]);
    }

    /**
     * POST /sdrs
     * Submit a new SDR. due_at is auto-set to submitted_at + 72h by model.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'participant_id'       => ['required', 'integer', 'exists:emr_participants,id'],
            'assigned_department'  => ['required', 'string'],
            'request_type'         => ['required', Rule::in(Sdr::REQUEST_TYPES)],
            'description'          => ['required', 'string'],
            'priority'             => ['required', Rule::in(Sdr::PRIORITIES)],
            'assigned_to_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
        ]);

        $sdr = Sdr::create(array_merge($validated, [
            'tenant_id'             => $user->tenant_id,
            'requesting_user_id'    => $user->id,
            'requesting_department' => $user->department,
            'submitted_at'          => now(),
            // due_at is auto-set by Sdr::boot() to submitted_at + 72h
        ]));

        AuditLog::record(
            action: 'sdr.submitted',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'sdr',
            resourceId: $sdr->id,
            description: "SDR '{$sdr->typeLabel()}' submitted by {$user->department} → {$sdr->assigned_department}",
            newValues: $validated,
        );

        // Phase 4: broadcast for real-time dept queue update
        broadcast(new SdrCreatedEvent($sdr->load('participant:id,mrn,first_name,last_name')))->toOthers();

        return response()->json($sdr->load(['participant:id,mrn,first_name,last_name', 'requestingUser:id,first_name,last_name']), 201);
    }

    /**
     * GET /sdrs/{sdr}
     * Returns full SDR detail.
     */
    public function show(Request $request, Sdr $sdr): JsonResponse
    {
        $this->authorizeForTenant($sdr, $request->user());

        return response()->json(
            $sdr->load(['participant:id,mrn,first_name,last_name', 'requestingUser:id,first_name,last_name', 'assignedTo:id,first_name,last_name'])
        );
    }

    /**
     * PATCH /sdrs/{sdr}
     * Update SDR status, assign a user, or add completion notes.
     * Completed/cancelled SDRs cannot be re-opened via this endpoint.
     */
    public function update(Request $request, Sdr $sdr): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($sdr, $user);

        $validated = $request->validate([
            'status'               => ['sometimes', Rule::in(Sdr::STATUSES)],
            'assigned_to_user_id'  => ['sometimes', 'nullable', 'integer', 'exists:shared_users,id'],
            'completion_notes'     => ['sometimes', 'nullable', 'string'],
        ]);

        // If completing, require completion notes
        if (($validated['status'] ?? null) === 'completed') {
            $request->validate(['completion_notes' => ['required', 'string']]);
            $validated['completed_at'] = now();
        }

        $sdr->update($validated);

        AuditLog::record(
            action: 'sdr.updated',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'sdr',
            resourceId: $sdr->id,
            description: "SDR #{$sdr->id} updated: " . json_encode($validated),
            newValues: $validated,
        );

        return response()->json($sdr->fresh(['participant:id,mrn,first_name,last_name', 'assignedTo:id,first_name,last_name']));
    }

    /**
     * DELETE /sdrs/{sdr}
     * Soft-delete (cancel) an SDR. Cancelled SDRs are excluded from active views.
     */
    public function destroy(Request $request, Sdr $sdr): \Illuminate\Http\Response
    {
        $user = $request->user();
        $this->authorizeForTenant($sdr, $user);

        $sdr->update(['status' => 'cancelled']);
        $sdr->delete();   // soft delete

        AuditLog::record(
            action: 'sdr.cancelled',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'sdr',
            resourceId: $sdr->id,
            description: "SDR #{$sdr->id} cancelled",
        );

        return response()->noContent();
    }
}
