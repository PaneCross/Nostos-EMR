<?php

// ─── RiskAdjustmentController ─────────────────────────────────────────────────
// REST API for CMS-HCC risk adjustment tracking.
//
// Route list:
//   GET  /billing/risk-adjustment                    → index()      — Inertia page (summary + gap table)
//   GET  /billing/risk-adjustment/data               → data()       — JSON KPIs + gaps (live refresh)
//   GET  /billing/risk-adjustment/participant/{id}   → participant() — Per-participant RAF detail + diagnosis list
//   POST /billing/risk-adjustment/recalculate/{id}   → recalculate() — Trigger RAF recalculation for one participant
//
// Department access: finance only (+ super_admin, it_admin).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Models\ParticipantRiskScore;
use App\Services\HccRiskScoringService;
use App\Services\RiskAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RiskAdjustmentController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeFinance(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'it_admin']),
            403
        );
    }

    // ── Inertia Page ─────────────────────────────────────────────────────────

    /**
     * Render the Risk Adjustment Inertia page.
     * Pre-loads org-wide gap summary and participant risk score index.
     *
     * GET /billing/risk-adjustment
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;
        $year     = (int) $request->query('year', now()->year);

        $gapSummary = (new HccRiskScoringService())->getOrgWideGapSummary($tenantId, $year);

        $riskScores = ParticipantRiskScore::forTenant($tenantId)
            ->forYear($year)
            ->with('participant:id,mrn,first_name,last_name')
            ->orderByDesc('risk_score')
            ->get();

        return Inertia::render('Finance/RiskAdjustment', [
            'gapSummary' => $gapSummary,
            'riskScores' => $riskScores,
            'year'       => $year,
        ]);
    }

    // ── JSON Data ─────────────────────────────────────────────────────────────

    /**
     * Return risk adjustment summary as JSON for live refresh.
     *
     * GET /billing/risk-adjustment/data
     */
    public function data(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;
        $year     = (int) $request->query('year', now()->year);

        $gapSummary = (new HccRiskScoringService())->getOrgWideGapSummary($tenantId, $year);

        $riskScores = ParticipantRiskScore::forTenant($tenantId)
            ->forYear($year)
            ->with('participant:id,mrn,first_name,last_name')
            ->orderByDesc('risk_score')
            ->get();

        return response()->json([
            'gap_summary' => $gapSummary,
            'risk_scores' => $riskScores,
            'year'        => $year,
        ]);
    }

    // ── Per-Participant Detail ─────────────────────────────────────────────────

    /**
     * Return the risk adjustment detail for a single participant:
     * RAF score, HCC categories captured, and diagnosis-level submission status.
     *
     * GET /billing/risk-adjustment/participant/{id}
     */
    public function participant(Request $request, int $id): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        $participant = Participant::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $year    = (int) $request->query('year', now()->year);
        $service = new RiskAdjustmentService(new HccRiskScoringService());

        $diagnosisData = $service->getDiagnosesForRiskSubmission($participant->id, $year);
        $riskScore     = ParticipantRiskScore::forTenant($tenantId)
            ->forYear($year)
            ->where('participant_id', $participant->id)
            ->first();

        $hccGaps = (new HccRiskScoringService())->findHccGaps($participant->id, $year);

        return response()->json([
            'participant'    => $participant->only(['id', 'mrn', 'first_name', 'last_name']),
            'year'           => $year,
            'risk_score'     => $riskScore,
            'diagnoses'      => $diagnosisData['diagnoses'],
            'hcc_gaps'       => $hccGaps,
        ]);
    }

    // ── Recalculate ───────────────────────────────────────────────────────────

    /**
     * Trigger a RAF recalculation for a single participant.
     * Reads their active problems, maps to HCC, upserts emr_participant_risk_scores.
     *
     * POST /billing/risk-adjustment/recalculate/{id}
     */
    public function recalculate(Request $request, int $id): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        $participant = Participant::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $year    = (int) $request->input('year', now()->year);
        $service = new RiskAdjustmentService(new HccRiskScoringService());
        $score   = $service->updateParticipantRiskScore($participant->id, $year);

        return response()->json([
            'message'    => 'RAF score recalculated.',
            'risk_score' => $score->fresh()->load('participant:id,mrn,first_name,last_name'),
        ]);
    }
}
