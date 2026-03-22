<?php

// ─── RiskAdjustmentService ────────────────────────────────────────────────────
// Manages CMS-HCC risk adjustment data lifecycle for PACE participants.
//
// Responsibilities:
//   1. getDiagnosesForRiskSubmission()  — returns active ICD-10 codes ready to be
//      submitted in encounter data for CMS risk scoring
//   2. getRiskAdjustmentGaps()          — tenant-wide HCC gap summary (delegates
//      per-participant scoring to HccRiskScoringService)
//   3. updateParticipantRiskScore()     — upserts emr_participant_risk_scores after
//      calculating RAF from emr_problems or importing from CMS file
//
// Note: the heavy ICD-10→HCC mapping and gap-finding logic lives in
// HccRiskScoringService to keep responsibilities separated.
//
// Revenue context: for a 200-participant PACE site, even a 5% HCC gap rate
// (~10 participants missing one HCC each) can represent $35,000/month in lost
// capitation. This service drives the Revenue Integrity dashboard alerts.
//
// Phase 9C — Part A (Risk Adjustment Tracking)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\EncounterLog;
use App\Models\Participant;
use App\Models\ParticipantRiskScore;
use App\Models\Problem;
use Carbon\Carbon;

class RiskAdjustmentService
{
    public function __construct(
        private HccRiskScoringService $scoringService
    ) {}

    // ── Diagnosis Retrieval ───────────────────────────────────────────────────

    /**
     * Get the ICD-10 codes from a participant's active problems that should be
     * included in encounter data for CMS risk submission in the given service year.
     *
     * Returns only codes that:
     *   - Are active (resolved_date IS NULL)
     *   - Have a corresponding HCC mapping (only HCC-bearing codes affect RAF)
     *   - Have NOT already been submitted in at least one accepted encounter
     *     this year (reduces redundancy — CMS only needs one submission per year
     *     to credit the HCC)
     *
     * @param  int  $participantId  Participant whose diagnoses to retrieve
     * @param  int  $serviceYear    Calendar year for submission window (default: current year)
     * @return array{
     *     participant_id: int,
     *     service_year: int,
     *     diagnoses: array<array{icd10_code: string, hcc_category: string, hcc_label: string, raf_value: float, already_submitted: bool}>
     * }
     */
    public function getDiagnosesForRiskSubmission(int $participantId, int $serviceYear = 0): array
    {
        if ($serviceYear === 0) {
            $serviceYear = now()->year;
        }

        $yearStart = Carbon::createFromDate($serviceYear, 1, 1)->startOfDay();
        $yearEnd   = Carbon::createFromDate($serviceYear, 12, 31)->endOfDay();

        // Active problem ICD-10 codes for this participant
        $activeCodes = Problem::where('participant_id', $participantId)
            ->whereNull('resolved_date')
            ->pluck('icd10_code')
            ->toArray();

        if (empty($activeCodes)) {
            return [
                'participant_id' => $participantId,
                'service_year'   => $serviceYear,
                'diagnoses'      => [],
            ];
        }

        // ICD-10 codes already in submitted/accepted encounters this year
        $submittedCodes = EncounterLog::where('participant_id', $participantId)
            ->whereIn('submission_status', ['submitted', 'accepted'])
            ->whereBetween('service_date', [$yearStart, $yearEnd])
            ->whereNotNull('diagnosis_codes')
            ->get(['diagnosis_codes'])
            ->flatMap(fn ($e) => $e->diagnosis_codes ?? [])
            ->map(fn ($c) => strtoupper(str_replace('.', '', $c)))
            ->unique()
            ->toArray();

        // Map each active code to its HCC data (using the scoring service)
        $rafData  = $this->scoringService->calculateRafScore($participantId, $serviceYear);
        $mappedBy = collect($rafData['hcc_categories'])->keyBy('icd10_code');

        $diagnoses = [];
        foreach ($activeCodes as $code) {
            $mapping         = $mappedBy->get($code);
            $normalizedCode  = strtoupper(str_replace('.', '', $code));
            $alreadySubmitted = in_array($normalizedCode, $submittedCodes);

            $diagnoses[] = [
                'icd10_code'       => $code,
                'hcc_category'     => $mapping['hcc_category'] ?? null,
                'hcc_label'        => $mapping['hcc_label'] ?? null,
                'raf_value'        => $mapping ? (float) $mapping['raf_value'] : 0.0,
                'already_submitted' => $alreadySubmitted,
            ];
        }

        // Sort: unsubmitted HCC-bearing codes first, then alphabetically
        usort($diagnoses, function ($a, $b) {
            $aPriority = (!$a['already_submitted'] && $a['hcc_category']) ? 0 : 1;
            $bPriority = (!$b['already_submitted'] && $b['hcc_category']) ? 0 : 1;
            return $aPriority !== $bPriority
                ? $aPriority <=> $bPriority
                : $a['icd10_code'] <=> $b['icd10_code'];
        });

        return [
            'participant_id' => $participantId,
            'service_year'   => $serviceYear,
            'diagnoses'      => $diagnoses,
        ];
    }

    // ── Tenant-Wide Gap Summary ───────────────────────────────────────────────

    /**
     * Get the org-wide HCC gap summary for the risk adjustment dashboard widget.
     * Delegates per-participant scoring to HccRiskScoringService::getOrgWideGapSummary().
     *
     * @param  int  $tenantId  Tenant to scan
     * @return array  See HccRiskScoringService::getOrgWideGapSummary() for shape
     */
    public function getRiskAdjustmentGaps(int $tenantId): array
    {
        return $this->scoringService->getOrgWideGapSummary($tenantId, now()->year);
    }

    // ── Score Upsert ──────────────────────────────────────────────────────────

    /**
     * Calculate and upsert a ParticipantRiskScore record for the given participant
     * and payment year using locally computed HCC data (score_source='calculated').
     *
     * Called by RiskAdjustmentController after a user requests a recalculation,
     * or triggered automatically when encounter diagnosis_codes are updated.
     *
     * @param  int  $participantId  Participant to score
     * @param  int  $paymentYear    CMS payment year (defaults to current year)
     * @return ParticipantRiskScore  The upserted record
     */
    public function updateParticipantRiskScore(int $participantId, int $paymentYear = 0): ParticipantRiskScore
    {
        if ($paymentYear === 0) {
            $paymentYear = now()->year;
        }

        $participant = Participant::findOrFail($participantId);
        $rafData     = $this->scoringService->calculateRafScore($participantId, $paymentYear);

        $categories = array_column($rafData['hcc_categories'], 'hcc_category');

        // Get encounter submission counts for the year
        $yearStart = Carbon::createFromDate($paymentYear, 1, 1)->startOfDay();
        $yearEnd   = Carbon::createFromDate($paymentYear, 12, 31)->endOfDay();

        $allDiagnosisCodes = EncounterLog::where('participant_id', $participantId)
            ->whereBetween('service_date', [$yearStart, $yearEnd])
            ->whereNotNull('diagnosis_codes')
            ->get(['diagnosis_codes', 'submission_status'])
            ->flatMap(fn ($e) => $e->diagnosis_codes ?? [])
            ->unique();

        $submittedCodes = EncounterLog::where('participant_id', $participantId)
            ->whereIn('submission_status', ['submitted', 'accepted'])
            ->whereBetween('service_date', [$yearStart, $yearEnd])
            ->whereNotNull('diagnosis_codes')
            ->get(['diagnosis_codes'])
            ->flatMap(fn ($e) => $e->diagnosis_codes ?? [])
            ->unique();

        return ParticipantRiskScore::updateOrCreate(
            [
                'participant_id' => $participantId,
                'payment_year'   => $paymentYear,
            ],
            [
                'tenant_id'           => $participant->tenant_id,
                'risk_score'          => $rafData['raf_score'],
                'hcc_categories'      => $categories,
                'diagnoses_submitted' => $allDiagnosisCodes->count(),
                'diagnoses_accepted'  => $submittedCodes->count(),
                'score_source'        => 'calculated',
                'effective_date'      => Carbon::createFromDate($paymentYear, 1, 1),
            ]
        );
    }
}
