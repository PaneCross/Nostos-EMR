<?php

// ─── RevenueIntegrityController ───────────────────────────────────────────────
// Powers the Revenue Integrity Dashboard for the Finance department.
//
// Route list:
//   GET /billing/revenue-integrity       → index()  — Inertia page
//   GET /billing/revenue-integrity/data  → data()   — JSON KPIs + HCC gap summary
//
// Combines RevenueIntegrityService (6 KPIs) with HccRiskScoringService
// (org-wide HCC gap analysis) into a single dashboard.
//
// Department access: finance only (+ super_admin, it_admin).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\EncounterLog;
use App\Models\Participant;
use App\Services\HccRiskScoringService;
use App\Services\RevenueIntegrityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RevenueIntegrityController extends Controller
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Flatten the nested KPI structure from RevenueIntegrityService into the
     * flat shape expected by Finance/RevenueIntegrity.tsx.
     */
    private function flattenKpis(array $raw): array
    {
        return [
            'capitation_total'       => $raw['capitation']['current_total'] ?? 0,
            'submission_rate_30d'    => $raw['submission']['rate_30'] ?? 0,
            'rejection_rate'         => $raw['rejection']['rejection_rate'] ?? 0,
            'troop_alerts'           => ($raw['troop']['at_catastrophic'] ?? 0) + ($raw['troop']['near_catastrophic'] ?? 0),
            'hos_m_completion_rate'  => $raw['hos_m']['completion_rate'] ?? 0,
            'encounter_completeness' => $raw['encounter_completeness']['rate'] ?? 0,
        ];
    }

    /**
     * Build the HCC gap list — per-participant gap objects with participant info.
     * Returns up to 50 gaps sorted by estimated monthly revenue impact (desc).
     */
    private function buildGaps(int $tenantId, int $year): array
    {
        $hccService   = new HccRiskScoringService();
        $participants = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->get(['id', 'mrn', 'first_name', 'last_name']);

        $gaps = [];
        foreach ($participants as $p) {
            foreach ($hccService->findHccGaps($p->id, $year) as $gap) {
                $gaps[] = array_merge($gap, [
                    'participant_id'   => $p->id,
                    'participant_name' => $p->first_name . ' ' . $p->last_name,
                    'mrn'              => $p->mrn,
                ]);
            }
        }

        usort($gaps, fn ($a, $b) => $b['estimated_monthly_impact'] <=> $a['estimated_monthly_impact']);

        return array_slice($gaps, 0, 50);
    }

    /**
     * Build the pending encounters list — encounters missing required 837P fields.
     * Returns up to 50 pending encounters with a missing_fields diagnostic array.
     */
    private function buildPending(int $tenantId): array
    {
        return EncounterLog::forTenant($tenantId)
            ->where('submission_status', 'pending')
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('service_date', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($e) {
                $missing = [];
                if (empty($e->diagnosis_codes))       $missing[] = 'diagnosis_codes';
                if (empty($e->billing_provider_npi))  $missing[] = 'billing_provider_npi';
                if (empty($e->procedure_code))        $missing[] = 'procedure_code';

                return [
                    'id'               => $e->id,
                    'participant_name' => $e->participant
                        ? $e->participant->first_name . ' ' . $e->participant->last_name
                        : 'Unknown',
                    'service_date'  => $e->service_date,
                    'service_type'  => $e->service_type,
                    'missing_fields'=> $missing,
                ];
            })
            ->toArray();
    }

    // ── Inertia Page ─────────────────────────────────────────────────────────

    /**
     * Render the Revenue Integrity Dashboard Inertia page.
     * Passes kpis, gaps, and pending in the shape Finance/RevenueIntegrity.tsx expects.
     *
     * GET /billing/revenue-integrity
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;
        $year     = (int) $request->query('year', now()->year);

        $rawKpis = (new RevenueIntegrityService())->getDashboardKpis($tenantId);

        return Inertia::render('Finance/RevenueIntegrity', [
            'kpis'    => $this->flattenKpis($rawKpis),
            'gaps'    => $this->buildGaps($tenantId, $year),
            'pending' => $this->buildPending($tenantId),
        ]);
    }

    // ── JSON Data Endpoint ────────────────────────────────────────────────────

    /**
     * Return revenue integrity data as JSON for live dashboard refresh.
     * Returns the same shape as index() so the frontend refresh callback works.
     *
     * GET /billing/revenue-integrity/data
     */
    public function data(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;
        $year     = (int) $request->query('year', now()->year);

        $rawKpis = (new RevenueIntegrityService())->getDashboardKpis($tenantId);

        return response()->json([
            'kpis'    => $this->flattenKpis($rawKpis),
            'gaps'    => $this->buildGaps($tenantId, $year),
            'pending' => $this->buildPending($tenantId),
        ]);
    }
}
