<?php

// ─── MedReconciliationController ──────────────────────────────────────────────
// Exposes the 5-step CMS PACE medication reconciliation workflow via JSON API.
// All routes are nested under /participants/{participant}/.
//
// Route list:
//   POST   /participants/{participant}/med-reconciliation/start       → start()
//   GET    /participants/{participant}/med-reconciliation/comparison  → comparison()
//   POST   /participants/{participant}/med-reconciliation/decisions   → decisions()
//   POST   /participants/{participant}/med-reconciliation/approve     → approve()
//   GET    /participants/{participant}/med-reconciliation/history     → history()
//
// Authorization:
//   - Any authenticated user may start/view comparisons (clinicians from any dept).
//   - Decisions require membership in PRESCRIBER_DEPARTMENTS.
//   - Approval requires membership in APPROVER_DEPARTMENTS (enforced in FormRequest).
//   - All routes enforce participant→tenant match (abort 403 on mismatch).
//
// Idempotency: start() returns the existing in_progress/decisions_made record if
// one exists — safe to call repeatedly from the wizard.
//
// Immutability: approved records are locked — mutation routes return 409.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\ApplyDecisionsRequest;
use App\Http\Requests\ApproveReconciliationRequest;
use App\Http\Requests\StartMedReconciliationRequest;
use App\Models\MedReconciliation;
use App\Models\Participant;
use App\Services\MedReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedReconciliationController extends Controller
{
    public function __construct(
        private readonly MedReconciliationService $service,
    ) {}

    // ── Step 1: Start ─────────────────────────────────────────────────────────

    /**
     * Create (or return existing) in_progress reconciliation for the participant.
     * Idempotent — safe to call again if the wizard is reopened.
     *
     * POST /participants/{participant}/med-reconciliation/start
     */
    public function start(StartMedReconciliationRequest $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $rec = $this->service->startReconciliation(
            participant: $participant,
            priorSource: $request->validated('prior_source'),
            type:        $request->validated('type'),
            user:        $request->user(),
        );

        return response()->json($rec->load(['reconciledBy', 'approvedBy']), 201);
    }

    // ── Step 2: Save prior medications ────────────────────────────────────────

    /**
     * Replace the prior_medications list on the active reconciliation record.
     * Called from Step 2 of the wizard before the comparison is generated.
     *
     * POST /participants/{participant}/med-reconciliation/prior-meds
     */
    public function savePriorMeds(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $request->validate([
            'medications'                 => ['required', 'array', 'min:1'],
            'medications.*.drug_name'     => ['required', 'string', 'max:200'],
            'medications.*.dose'          => ['nullable', 'string', 'max:50'],
            'medications.*.dose_unit'     => ['nullable', 'string', 'max:20'],
            'medications.*.frequency'     => ['nullable', 'string', 'max:100'],
            'medications.*.route'         => ['nullable', 'string', 'max:50'],
            'medications.*.prescriber'    => ['nullable', 'string', 'max:150'],
            'medications.*.notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $rec = $this->resolveActiveRec($participant);

        try {
            $this->service->addPriorMedications($rec, $request->validated('medications'));
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($rec->fresh(), 200);
    }

    // ── Step 3: Generate comparison ───────────────────────────────────────────

    /**
     * Diff the prior medication list vs current active medications.
     * Returns matched/prior_only/current_only arrays with recommendations.
     *
     * GET /participants/{participant}/med-reconciliation/comparison
     */
    public function comparison(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        // Find the active reconciliation record for this participant
        $rec = $this->resolveActiveRec($participant);

        $diff = $this->service->generateComparison($rec);

        return response()->json([
            'reconciliation' => $rec,
            'comparison'     => $diff,
        ]);
    }

    // ── Step 4: Apply decisions ───────────────────────────────────────────────

    /**
     * Execute clinician decisions (keep/discontinue/add/modify) against
     * emr_medications and stamp the reconciliation record.
     * Requires PRESCRIBER_DEPARTMENTS membership.
     *
     * POST /participants/{participant}/med-reconciliation/decisions
     */
    public function decisions(ApplyDecisionsRequest $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());
        $this->authorizePrescriber($request->user());

        $rec = $this->resolveActiveRec($participant);

        if ($rec->isLocked()) {
            return response()->json(['message' => 'Reconciliation is already approved and locked.'], 409);
        }

        $this->service->applyDecisions($rec, $request->validated('decisions'), $request->user());

        return response()->json($rec->fresh()->load(['reconciledBy']), 200);
    }

    // ── Step 5: Provider approval ─────────────────────────────────────────────

    /**
     * Lock the reconciliation record. Provider must be in APPROVER_DEPARTMENTS.
     * Authorization is enforced in ApproveReconciliationRequest::authorize().
     *
     * POST /participants/{participant}/med-reconciliation/approve
     */
    public function approve(ApproveReconciliationRequest $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $rec = $this->resolveActiveRec($participant);

        try {
            $this->service->providerApproval($rec, $request->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($rec->fresh()->load(['reconciledBy', 'approvedBy']), 200);
    }

    // ── History ───────────────────────────────────────────────────────────────

    /**
     * Return all reconciliation records for the participant (approved + in-progress).
     * Ordered newest first. Includes the approving provider for display.
     *
     * GET /participants/{participant}/med-reconciliation/history
     */
    public function history(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $records = MedReconciliation::where('participant_id', $participant->id)
            ->with(['reconciledBy:id,first_name,last_name,department', 'approvedBy:id,first_name,last_name,department'])
            ->latest()
            ->paginate(20);

        return response()->json($records);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Resolve the single active reconciliation for the participant.
     * Prefers in_progress → decisions_made. Aborts 404 if none found.
     */
    private function resolveActiveRec(Participant $participant): MedReconciliation
    {
        $rec = MedReconciliation::where('participant_id', $participant->id)
            ->whereIn('status', ['in_progress', 'decisions_made'])
            ->latest()
            ->first();

        if (!$rec) {
            // Also try the most recent approved record (for history display)
            $rec = MedReconciliation::where('participant_id', $participant->id)
                ->latest()
                ->first();
        }

        abort_if(!$rec, 404, 'No active medication reconciliation found for this participant.');

        return $rec;
    }

    /**
     * Abort 403 if the participant belongs to a different tenant than the user.
     * Prevents cross-tenant data access.
     */
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if(
            $participant->tenant_id !== $user->tenant_id,
            403,
            'Access denied: participant belongs to a different organization.',
        );
    }

    /**
     * Abort 403 if the user is not in a department permitted to prescribe/apply decisions.
     */
    private function authorizePrescriber($user): void
    {
        abort_if(
            !in_array($user->department, MedReconciliation::PRESCRIBER_DEPARTMENTS, true),
            403,
            'Only prescribing departments may apply medication reconciliation decisions.',
        );
    }
}
