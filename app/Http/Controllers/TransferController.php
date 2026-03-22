<?php

// ─── TransferController ───────────────────────────────────────────────────────
// Handles site-to-site participant transfer CRUD.
//
// Endpoints:
//   GET    /participants/{participant}/transfers         — transfer history
//   POST   /participants/{participant}/transfers         — request new transfer
//   POST   /participants/{participant}/transfers/{id}/approve
//   POST   /participants/{participant}/transfers/{id}/cancel
//
// Authorization:
//   - All endpoints: auth middleware
//   - Request: enrollment + it_admin + super_admin
//   - Approve: enrollment (admin role) + it_admin + super_admin
//   - Cancel: enrollment + it_admin + super_admin
//   - View history: any authenticated dept (read-only preview)
//
// Phase 10A
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Models\ParticipantSiteTransfer;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transferService) {}

    // ── GET /participants/{participant}/transfers ──────────────────────────────

    /**
     * Return all transfers for a participant, newest first.
     * Includes site names for display.
     */
    public function index(Participant $participant): JsonResponse
    {
        $this->requireSameTenant($participant);

        $transfers = ParticipantSiteTransfer::where('participant_id', $participant->id)
            ->with(['fromSite:id,name', 'toSite:id,name', 'requestedBy:id,first_name,last_name', 'approvedBy:id,first_name,last_name'])
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn ($t) => $this->formatTransfer($t));

        return response()->json(['transfers' => $transfers]);
    }

    // ── POST /participants/{participant}/transfers ─────────────────────────────

    /**
     * Submit a transfer request.
     * Allowed: enrollment, it_admin, super_admin.
     */
    public function request(Request $request, Participant $participant): JsonResponse
    {
        $this->requireSameTenant($participant);
        $this->requireDept(['enrollment', 'it_admin']);

        $validated = $request->validate([
            'to_site_id'            => 'required|integer|exists:shared_sites,id',
            'transfer_reason'       => 'required|string|in:' . implode(',', ParticipantSiteTransfer::TRANSFER_REASONS),
            'transfer_reason_notes' => 'nullable|string|max:1000',
            'effective_date'        => 'required|date|after_or_equal:today',
        ]);

        // Prevent duplicate pending/approved transfer for this participant
        $existing = ParticipantSiteTransfer::where('participant_id', $participant->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Participant already has an active transfer request (pending or approved). Cancel it first.',
            ], 409);
        }

        // Prevent transfer to current site
        if ((int) $validated['to_site_id'] === (int) $participant->site_id) {
            return response()->json([
                'message' => 'Cannot transfer participant to their current site.',
            ], 422);
        }

        $transfer = $this->transferService->requestTransfer(
            participant: $participant,
            toSiteId: $validated['to_site_id'],
            reason: $validated['transfer_reason'],
            notes: $validated['transfer_reason_notes'] ?? null,
            effectiveDate: $validated['effective_date'],
            requestedBy: Auth::user(),
        );

        return response()->json($this->formatTransfer($transfer->load(['fromSite:id,name', 'toSite:id,name'])), 201);
    }

    // ── POST /participants/{participant}/transfers/{transfer}/approve ──────────

    /**
     * Approve a pending transfer.
     * Allowed: enrollment, it_admin, super_admin.
     */
    public function approve(Participant $participant, ParticipantSiteTransfer $transfer): JsonResponse
    {
        $this->requireSameTenant($participant);
        $this->requireDept(['enrollment', 'it_admin']);

        if ($transfer->participant_id !== $participant->id) {
            abort(404);
        }

        $updated = $this->transferService->approveTransfer($transfer, Auth::user());

        return response()->json($this->formatTransfer($updated->load(['fromSite:id,name', 'toSite:id,name'])));
    }

    // ── POST /participants/{participant}/transfers/{transfer}/cancel ───────────

    /**
     * Cancel a pending or approved transfer.
     * Allowed: enrollment, it_admin, super_admin.
     */
    public function cancel(Participant $participant, ParticipantSiteTransfer $transfer): JsonResponse
    {
        $this->requireSameTenant($participant);
        $this->requireDept(['enrollment', 'it_admin']);

        if ($transfer->participant_id !== $participant->id) {
            abort(404);
        }

        $updated = $this->transferService->cancelTransfer($transfer, Auth::user());

        return response()->json($this->formatTransfer($updated->load(['fromSite:id,name', 'toSite:id,name'])));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function requireSameTenant(Participant $participant): void
    {
        if ($participant->tenant_id !== Auth::user()->tenant_id) {
            abort(404);
        }
    }

    private function requireDept(array $allowed): void
    {
        $user = Auth::user();
        if ($user->isSuperAdmin()) {
            return;
        }
        if (!in_array($user->department, $allowed, true)) {
            abort(403);
        }
    }

    private function formatTransfer(ParticipantSiteTransfer $t): array
    {
        return [
            'id'                    => $t->id,
            'participant_id'        => $t->participant_id,
            'from_site'             => $t->fromSite ? ['id' => $t->fromSite->id, 'name' => $t->fromSite->name] : null,
            'to_site'               => $t->toSite   ? ['id' => $t->toSite->id,   'name' => $t->toSite->name]   : null,
            'transfer_reason'       => $t->transfer_reason,
            'transfer_reason_label' => ParticipantSiteTransfer::TRANSFER_REASON_LABELS[$t->transfer_reason] ?? $t->transfer_reason,
            'transfer_reason_notes' => $t->transfer_reason_notes,
            'requested_by'          => $t->requestedBy
                ? ['id' => $t->requestedBy->id, 'name' => $t->requestedBy->first_name . ' ' . $t->requestedBy->last_name]
                : null,
            'requested_at'          => $t->requested_at?->toIso8601String(),
            'approved_by'           => $t->approvedBy
                ? ['id' => $t->approvedBy->id, 'name' => $t->approvedBy->first_name . ' ' . $t->approvedBy->last_name]
                : null,
            'approved_at'           => $t->approved_at?->toIso8601String(),
            'effective_date'        => $t->effective_date?->format('Y-m-d'),
            'status'                => $t->status,
            'notification_sent'     => $t->notification_sent,
        ];
    }
}
