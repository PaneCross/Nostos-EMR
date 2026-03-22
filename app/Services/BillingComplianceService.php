<?php

// ─── BillingComplianceService ─────────────────────────────────────────────────
// Computes the billing compliance checklist for the Finance department.
// Powers GET /billing/compliance-checklist (Finance/ComplianceChecklist.tsx).
//
// Five checklist categories (per CMS PACE billing requirements):
//   1. Encounter Data       — completeness, diagnosis codes, pending queue
//   2. Risk Adjustment      — RAF scores current, HCC gaps identified, stale records
//   3. Capitation           — current-month records present, RAF reconciliation
//   4. HPMS                 — enrollment file submissions, pending items
//   5. Part D / PDE         — PDE records present, TrOOP alerts, near-threshold count
//
// Each check returns: {label, status: 'pass'|'warn'|'fail', value, detail}
// Status rules:
//   pass = within acceptable thresholds
//   warn = approaching a compliance threshold (action recommended)
//   fail = threshold exceeded or required data missing (action required)
//
// Phase 9C — Part C (Billing Compliance Checklist)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\CapitationRecord;
use App\Models\EncounterLog;
use App\Models\HpmsSubmission;
use App\Models\Participant;
use App\Models\ParticipantRiskScore;
use App\Models\PdeRecord;
use Carbon\Carbon;

class BillingComplianceService
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Compute all 5 checklist categories and return the full compliance report.
     *
     * @param  int  $tenantId  Tenant to compute checklist for
     * @return array{
     *     generated_at: string,
     *     overall_status: 'pass'|'warn'|'fail',
     *     categories: array
     * }
     */
    public function getChecklist(int $tenantId): array
    {
        $categories = [
            'encounter_data'   => $this->encounterDataChecks($tenantId),
            'risk_adjustment'  => $this->riskAdjustmentChecks($tenantId),
            'capitation'       => $this->capitationChecks($tenantId),
            'hpms'             => $this->hpmsChecks($tenantId),
            'part_d'           => $this->partDChecks($tenantId),
        ];

        // Overall status = worst status across all checks
        $allStatuses = collect($categories)
            ->flatMap(fn ($cat) => collect($cat['checks'])->pluck('status'))
            ->toArray();

        $overallStatus = in_array('fail', $allStatuses) ? 'fail'
            : (in_array('warn', $allStatuses) ? 'warn' : 'pass');

        return [
            'generated_at'   => now()->toIso8601String(),
            'overall_status' => $overallStatus,
            'categories'     => $categories,
        ];
    }

    // ── Category 1: Encounter Data ────────────────────────────────────────────

    /**
     * Checks for encounter data completeness.
     * CMS requires 100% of encounters to have diagnosis codes within 30 days of service.
     */
    private function encounterDataChecks(int $tenantId): array
    {
        $cutoff90 = Carbon::now()->subDays(90);

        // Check 1: Encounters missing diagnosis codes
        $totalEncounters = EncounterLog::forTenant($tenantId)
            ->where('created_at', '>=', $cutoff90)
            ->count();

        $missingDx = EncounterLog::forTenant($tenantId)
            ->where('created_at', '>=', $cutoff90)
            ->where(function ($q) {
                $q->whereNull('diagnosis_codes')
                  ->orWhereRaw("jsonb_array_length(COALESCE(diagnosis_codes, '[]'::jsonb)) = 0");
            })
            ->count();

        $dxRate = $totalEncounters > 0 ? round((1 - $missingDx / $totalEncounters) * 100, 1) : 100;

        // Check 2: Pending encounters older than 30 days (should have been submitted)
        $stalePending = EncounterLog::forTenant($tenantId)
            ->where('submission_status', 'pending')
            ->where('service_date', '<', Carbon::now()->subDays(30))
            ->count();

        // Check 3: Rejected encounters requiring resubmission
        $rejected = EncounterLog::forTenant($tenantId)
            ->where('submission_status', 'rejected')
            ->count();

        return [
            'label'  => 'Encounter Data',
            'checks' => [
                [
                    'label'  => 'Diagnosis code capture rate (last 90 days)',
                    'status' => $dxRate >= 95 ? 'pass' : ($dxRate >= 80 ? 'warn' : 'fail'),
                    'value'  => "{$dxRate}%",
                    'detail' => "{$missingDx} of {$totalEncounters} encounters missing diagnosis codes",
                ],
                [
                    'label'  => 'Stale pending encounters (>30 days unsubmitted)',
                    'status' => $stalePending === 0 ? 'pass' : ($stalePending <= 5 ? 'warn' : 'fail'),
                    'value'  => (string) $stalePending,
                    'detail' => "{$stalePending} encounters pending more than 30 days past service date",
                ],
                [
                    'label'  => 'Rejected encounters awaiting resubmission',
                    'status' => $rejected === 0 ? 'pass' : ($rejected <= 3 ? 'warn' : 'fail'),
                    'value'  => (string) $rejected,
                    'detail' => "{$rejected} encounters rejected by CMS — review and resubmit",
                ],
            ],
        ];
    }

    // ── Category 2: Risk Adjustment ───────────────────────────────────────────

    /**
     * Checks for RAF score currency and HCC gap documentation.
     * RAF scores more than 90 days old may not reflect current clinical diagnoses.
     */
    private function riskAdjustmentChecks(int $tenantId): array
    {
        $currentYear  = now()->year;
        $enrolled     = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        // How many enrolled participants have a risk score for this payment year
        $scored = ParticipantRiskScore::forTenant($tenantId)
            ->forYear($currentYear)
            ->count();

        $coverageRate = $enrolled > 0 ? round(($scored / $enrolled) * 100, 1) : 100;

        // Risk scores not updated in the last 90 days (stale)
        $staleScores = ParticipantRiskScore::forTenant($tenantId)
            ->forYear($currentYear)
            ->where('updated_at', '<', now()->subDays(90))
            ->count();

        // Participants with a risk score below expected PACE average (potential under-documentation)
        // PACE average RAF is typically 1.2–2.5; below 1.0 suggests possible missed diagnoses
        $lowRaf = ParticipantRiskScore::forTenant($tenantId)
            ->forYear($currentYear)
            ->where('risk_score', '<', 1.0)
            ->count();

        return [
            'label'  => 'Risk Adjustment (HCC)',
            'checks' => [
                [
                    'label'  => 'Risk score coverage for current payment year',
                    'status' => $coverageRate >= 90 ? 'pass' : ($coverageRate >= 70 ? 'warn' : 'fail'),
                    'value'  => "{$coverageRate}%",
                    'detail' => "{$scored} of {$enrolled} enrolled participants have a {$currentYear} RAF score",
                ],
                [
                    'label'  => 'Stale risk scores (not updated in 90+ days)',
                    'status' => $staleScores === 0 ? 'pass' : ($staleScores <= 5 ? 'warn' : 'fail'),
                    'value'  => (string) $staleScores,
                    'detail' => "{$staleScores} risk scores have not been recalculated in 90 days",
                ],
                [
                    'label'  => 'Participants with RAF score below 1.0 (possible under-documentation)',
                    'status' => $lowRaf === 0 ? 'pass' : ($lowRaf <= 3 ? 'warn' : 'fail'),
                    'value'  => (string) $lowRaf,
                    'detail' => "{$lowRaf} participants have RAF scores below 1.0 — review for missed HCC diagnoses",
                ],
            ],
        ];
    }

    // ── Category 3: Capitation ────────────────────────────────────────────────

    /**
     * Checks for monthly capitation record completeness.
     * Every enrolled participant should have a capitation record each month.
     */
    private function capitationChecks(int $tenantId): array
    {
        $monthYear = now()->format('Y-m');
        $enrolled  = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        // Capitation records for current month
        $capRecords = CapitationRecord::forTenant($tenantId)
            ->forMonth($monthYear)
            ->count();

        $missingRecords = max(0, $enrolled - $capRecords);

        // Records missing HCC risk score (needed for rate reconciliation)
        $missingRaf = CapitationRecord::forTenant($tenantId)
            ->forMonth($monthYear)
            ->whereNull('hcc_risk_score')
            ->count();

        $rafCoverageRate = $capRecords > 0 ? round((1 - $missingRaf / $capRecords) * 100, 1) : 0;

        return [
            'label'  => 'Capitation Records',
            'checks' => [
                [
                    'label'  => "Capitation records for {$monthYear}",
                    'status' => $missingRecords === 0 ? 'pass' : ($missingRecords <= 3 ? 'warn' : 'fail'),
                    'value'  => "{$capRecords} / {$enrolled}",
                    'detail' => $missingRecords === 0
                        ? "All {$enrolled} enrolled participants have {$monthYear} capitation records"
                        : "{$missingRecords} enrolled participants missing capitation records for {$monthYear}",
                ],
                [
                    'label'  => 'Capitation records with RAF score populated',
                    'status' => $rafCoverageRate >= 90 ? 'pass' : ($rafCoverageRate >= 70 ? 'warn' : 'fail'),
                    'value'  => "{$rafCoverageRate}%",
                    'detail' => "{$missingRaf} capitation records missing HCC risk score — needed for CMS rate reconciliation",
                ],
            ],
        ];
    }

    // ── Category 4: HPMS ─────────────────────────────────────────────────────

    /**
     * Checks for HPMS enrollment file submission compliance.
     * CMS requires monthly enrollment/disenrollment file submission.
     */
    private function hpmsChecks(int $tenantId): array
    {
        $currentMonthLabel = now()->format('Y-m');
        $priorMonthLabel   = now()->subMonth()->format('Y-m');

        // Filter by period_start within the given month (period_start is a date column)
        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd   = now()->endOfMonth();
        $priorMonthStart   = now()->subMonth()->startOfMonth();
        $priorMonthEnd     = now()->subMonth()->endOfMonth();

        // Most recent HPMS enrollment/disenrollment submission for current month
        $currentSubmission = HpmsSubmission::forTenant($tenantId)
            ->whereIn('submission_type', ['enrollment', 'disenrollment'])
            ->whereBetween('period_start', [$currentMonthStart, $currentMonthEnd])
            ->latest()
            ->first();

        // Prior month submission (should be accepted by now)
        $priorSubmission = HpmsSubmission::forTenant($tenantId)
            ->whereIn('submission_type', ['enrollment', 'disenrollment'])
            ->whereBetween('period_start', [$priorMonthStart, $priorMonthEnd])
            ->latest()
            ->first();

        // Use 'status' column (not 'submission_status' — see HpmsSubmission model)
        $currentStatus = $currentSubmission?->status ?? 'not_submitted';
        $priorStatus   = $priorSubmission?->status ?? 'not_submitted';

        return [
            'label'  => 'HPMS Submissions',
            'checks' => [
                [
                    'label'  => "HPMS submission for {$currentMonthLabel}",
                    'status' => $currentStatus === 'submitted' ? 'pass'
                        : ($currentStatus === 'draft' ? 'warn' : 'fail'),
                    'value'  => ucfirst($currentStatus),
                    'detail' => $currentSubmission
                        ? "Submitted: {$currentSubmission->submitted_at?->format('Y-m-d')}"
                        : "No HPMS submission found for {$currentMonthLabel}",
                ],
                [
                    'label'  => "Prior month HPMS submitted ({$priorMonthLabel})",
                    'status' => $priorStatus === 'submitted' ? 'pass'
                        : ($priorStatus === 'draft' ? 'warn' : 'fail'),
                    'value'  => ucfirst($priorStatus),
                    'detail' => $priorSubmission
                        ? "Status: {$priorStatus}"
                        : "No HPMS submission found for {$priorMonthLabel}",
                ],
            ],
        ];
    }

    // ── Category 5: Part D / PDE ──────────────────────────────────────────────

    /**
     * Checks for Part D PDE tracking compliance.
     * CMS requires monthly PDE submission for all dispensed prescriptions.
     */
    private function partDChecks(int $tenantId): array
    {
        $currentMonth = now()->format('Y-m');
        $yearStart    = now()->startOfYear();

        // PDE records created this month (any = good; 0 for a month that had pharmacy activity = warn)
        $pdeThisMonth = PdeRecord::where('tenant_id', $tenantId)
            ->whereBetween('dispense_date', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ])
            ->count();

        $pendingPde = PdeRecord::where('tenant_id', $tenantId)
            ->where('submission_status', 'pending')
            ->count();

        // TrOOP: participants at or near Part D catastrophic threshold this year
        $threshold = PdeRecord::TROOP_CATASTROPHIC_THRESHOLD;

        $troopByParticipant = PdeRecord::where('tenant_id', $tenantId)
            ->where('dispense_date', '>=', $yearStart)
            ->groupBy('participant_id')
            ->selectRaw('participant_id, SUM(troop_amount) as ytd_troop')
            ->get();

        $atThreshold   = $troopByParticipant->where('ytd_troop', '>=', $threshold)->count();
        $nearThreshold = $troopByParticipant
            ->where('ytd_troop', '>=', $threshold * 0.8)
            ->where('ytd_troop', '<', $threshold)
            ->count();

        return [
            'label'  => 'Part D (PDE Tracking)',
            'checks' => [
                [
                    'label'  => "PDE records for {$currentMonth}",
                    'status' => $pdeThisMonth > 0 ? 'pass' : 'warn',
                    'value'  => (string) $pdeThisMonth,
                    'detail' => $pdeThisMonth > 0
                        ? "{$pdeThisMonth} PDE records for current month"
                        : "No PDE records found for {$currentMonth} — verify pharmacy activity",
                ],
                [
                    'label'  => 'Pending PDE submissions',
                    'status' => $pendingPde === 0 ? 'pass' : ($pendingPde <= 10 ? 'warn' : 'fail'),
                    'value'  => (string) $pendingPde,
                    'detail' => "{$pendingPde} PDE records awaiting CMS MARx submission",
                ],
                [
                    'label'  => 'TrOOP alerts (at or near catastrophic threshold)',
                    'status' => ($atThreshold + $nearThreshold) === 0 ? 'pass'
                        : ($atThreshold === 0 ? 'warn' : 'fail'),
                    'value'  => "{$atThreshold} at / {$nearThreshold} near",
                    'detail' => "{$atThreshold} participants at catastrophic threshold (\${$threshold}); {$nearThreshold} near (≥80%)",
                ],
            ],
        ];
    }
}
