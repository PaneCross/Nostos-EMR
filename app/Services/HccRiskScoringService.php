<?php

// ─── HccRiskScoringService ────────────────────────────────────────────────────
// Maps participant ICD-10 diagnoses to CMS-HCC categories for risk adjustment.
//
// CMS uses the HCC risk adjustment model to calculate PACE capitation rates.
// Each participant's documented ICD-10 diagnoses map to HCC categories via
// emr_hcc_mappings. The sum of HCC RAF values (+ demographic factors) produces
// the participant's RAF score, which multiplies the county base rate.
//
// Revenue impact: one missed HCC-85 (Heart Failure) = ~$350/month lost capitation.
// This service identifies gaps between:
//   - Clinical diagnoses documented in emr_problems
//   - Diagnoses submitted in encounter data (emr_encounter_log.diagnosis_codes)
//
// Gap = diagnosis in emr_problems that has not appeared in any encounter's
//       diagnosis_codes within the current calendar year.
//
// Usage: called by RevenueIntegrityController to power the HCC gap analysis panel.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\EncounterLog;
use App\Models\HccMapping;
use App\Models\Participant;
use App\Models\Problem;
use Carbon\Carbon;

class HccRiskScoringService
{
    /**
     * Calculate the CMS-HCC risk score for a participant based on their
     * documented problems (ICD-10 codes in emr_problems).
     *
     * Only uses diagnoses that have corresponding HCC mappings in emr_hcc_mappings.
     * RAF value is a simplified calculation — production implementation requires
     * CMS demographic coefficient tables (age/sex/disability adjustments).
     *
     * @param  int  $participantId  Participant to score
     * @param  int  $year           Benefit year (e.g. 2025)
     * @return array{raf_score: float, hcc_categories: array, mapped_diagnoses: array}
     */
    public function calculateRafScore(int $participantId, int $year = 2025): array
    {
        $problems = Problem::where('participant_id', $participantId)
            ->whereNull('resolved_date')
            ->get(['icd10_code']);

        if ($problems->isEmpty()) {
            return ['raf_score' => 0.0, 'hcc_categories' => [], 'mapped_diagnoses' => []];
        }

        $codes = $problems->pluck('icd10_code')->toArray();

        $mappings = HccMapping::whereIn('icd10_code', $codes)
            ->forYear($year)
            ->whereNotNull('hcc_category')
            ->get();

        // HCC model uses hierarchy — only the highest RAF value per HCC category
        $byCategory = [];
        foreach ($mappings as $m) {
            $cat = $m->hcc_category;
            if (!isset($byCategory[$cat]) || $m->raf_value > $byCategory[$cat]['raf_value']) {
                $byCategory[$cat] = [
                    'hcc_category' => $cat,
                    'hcc_label'    => $m->hcc_label,
                    'raf_value'    => (float) $m->raf_value,
                    'icd10_code'   => $m->icd10_code,
                ];
            }
        }

        $rafScore = array_sum(array_column($byCategory, 'raf_value'));

        return [
            'raf_score'        => round($rafScore, 4),
            'hcc_categories'   => array_values($byCategory),
            'mapped_diagnoses' => $mappings->pluck('icd10_code')->toArray(),
        ];
    }

    /**
     * Identify HCC gaps for a participant — diagnoses in emr_problems that
     * have NOT been submitted in encounter data this calendar year.
     *
     * A gap = the diagnosis exists in emr_problems but has never appeared
     * in any EncounterLog.diagnosis_codes for the current year.
     * Each gap represents potential lost capitation revenue.
     *
     * @param  int  $participantId  Participant to scan
     * @param  int  $year           Calendar year to check
     * @return array  Array of gap objects: [{icd10_code, hcc_category, hcc_label, raf_value, estimated_monthly_impact}]
     */
    public function findHccGaps(int $participantId, int $year = 2025): array
    {
        $problems = Problem::where('participant_id', $participantId)
            ->whereNull('resolved_date')
            ->get(['icd10_code']);

        if ($problems->isEmpty()) {
            return [];
        }

        // Get all ICD-10 codes that have been submitted in encounters this year
        $yearStart = Carbon::createFromDate($year, 1, 1)->startOfYear();
        $yearEnd   = Carbon::createFromDate($year, 12, 31)->endOfYear();

        $submittedCodes = EncounterLog::where('participant_id', $participantId)
            ->whereIn('submission_status', ['submitted', 'accepted'])
            ->whereBetween('service_date', [$yearStart, $yearEnd])
            ->whereNotNull('diagnosis_codes')
            ->get(['diagnosis_codes'])
            ->flatMap(fn ($e) => $e->diagnosis_codes ?? [])
            ->map(fn ($c) => strtoupper(str_replace('.', '', $c)))
            ->unique()
            ->toArray();

        $gaps = [];
        foreach ($problems as $problem) {
            $normalizedCode = strtoupper(str_replace('.', '', $problem->icd10_code));
            if (in_array($normalizedCode, $submittedCodes)) {
                continue; // already submitted this year — not a gap
            }

            // Check if this code maps to an HCC
            $mapping = HccMapping::forCode($problem->icd10_code)->forYear($year)->first();
            if (!$mapping || !$mapping->hcc_category) {
                continue; // no HCC value — not a revenue gap
            }

            // Estimate monthly revenue impact: RAF value × avg county base rate (~$2,800/month)
            $estimatedMonthlyImpact = round((float) $mapping->raf_value * 2800.00, 2);

            $gaps[] = [
                'icd10_code'                => $problem->icd10_code,
                'hcc_category'             => $mapping->hcc_category,
                'hcc_label'                => $mapping->hcc_label,
                'raf_value'                => (float) $mapping->raf_value,
                'estimated_monthly_impact' => $estimatedMonthlyImpact,
            ];
        }

        // Sort by estimated revenue impact descending (highest gaps first)
        usort($gaps, fn ($a, $b) => $b['estimated_monthly_impact'] <=> $a['estimated_monthly_impact']);

        return $gaps;
    }

    /**
     * Generate an org-wide HCC gap summary for the revenue integrity dashboard.
     * Scans all enrolled participants and returns aggregate gap metrics.
     *
     * @param  int  $tenantId  Tenant to scan
     * @param  int  $year      Calendar year
     * @return array{total_participants: int, participants_with_gaps: int, total_gap_count: int, estimated_monthly_revenue_at_risk: float, top_gaps: array}
     */
    public function getOrgWideGapSummary(int $tenantId, int $year = 2025): array
    {
        $participants = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->get(['id']);

        $totalGaps            = 0;
        $participantsWithGaps = 0;
        $totalRevenueAtRisk   = 0.0;
        $gapFrequency         = [];

        foreach ($participants as $participant) {
            $gaps = $this->findHccGaps($participant->id, $year);
            if (!empty($gaps)) {
                $participantsWithGaps++;
                $totalGaps          += count($gaps);
                $totalRevenueAtRisk += array_sum(array_column($gaps, 'estimated_monthly_impact'));
                foreach ($gaps as $gap) {
                    $cat               = $gap['hcc_category'];
                    $gapFrequency[$cat] = ($gapFrequency[$cat] ?? 0) + 1;
                }
            }
        }

        // Top 5 most common gap categories
        arsort($gapFrequency);
        $topGaps = array_slice($gapFrequency, 0, 5, true);

        return [
            'total_participants'                 => $participants->count(),
            'participants_with_gaps'             => $participantsWithGaps,
            'total_gap_count'                    => $totalGaps,
            'estimated_monthly_revenue_at_risk'  => round($totalRevenueAtRisk, 2),
            'top_gaps'                           => $topGaps,
        ];
    }
}
