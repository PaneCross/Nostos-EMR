<?php

// ─── RevenueIntegrityService ──────────────────────────────────────────────────
// Calculates revenue integrity KPIs for the Finance department dashboard.
// Powers the /billing/revenue-integrity Inertia page.
//
// KPIs computed:
//   1. Current month capitation total vs prior month (% change)
//   2. Encounter submission rate (% submitted within 30/60/90 days of service)
//   3. Rejection rate (% rejected, last 90 days)
//   4. TrOOP tracking (participants near/at catastrophic Part D threshold)
//   5. HOS-M completion rate (% with current-year survey completed)
//   6. Encounter completeness (% with diagnosis codes populated)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\CapitationRecord;
use App\Models\EncounterLog;
use App\Models\HosMSurvey;
use App\Models\Participant;
use App\Models\PdeRecord;
use Carbon\Carbon;

class RevenueIntegrityService
{
    /**
     * Return all KPIs needed to render the revenue integrity dashboard.
     *
     * @param  int  $tenantId  Tenant to compute KPIs for
     * @return array{capitation: array, submission: array, rejection: array, troop: array, hos_m: array, encounter_completeness: array}
     */
    public function getDashboardKpis(int $tenantId): array
    {
        $now          = Carbon::now();
        $currentMonth = $now->format('Y-m');
        $priorMonth   = $now->copy()->subMonth()->format('Y-m');

        return [
            'capitation'             => $this->capitationKpi($tenantId, $currentMonth, $priorMonth),
            'submission'             => $this->submissionKpi($tenantId),
            'rejection'              => $this->rejectionKpi($tenantId),
            'troop'                  => $this->troopKpi($tenantId),
            'hos_m'                  => $this->hosMKpi($tenantId),
            'encounter_completeness' => $this->encounterCompletenessKpi($tenantId),
        ];
    }

    /**
     * Capitation total: current vs prior month and percent change.
     *
     * @param  int    $tenantId  Tenant
     * @param  string $current   Current month in 'Y-m' format
     * @param  string $prior     Prior month in 'Y-m' format
     * @return array{current_month: string, current_total: float, prior_total: float, pct_change: float}
     */
    private function capitationKpi(int $tenantId, string $current, string $prior): array
    {
        $currentTotal = CapitationRecord::forTenant($tenantId)->forMonth($current)->sum('total_capitation');
        $priorTotal   = CapitationRecord::forTenant($tenantId)->forMonth($prior)->sum('total_capitation');
        $change       = $priorTotal > 0
            ? round((($currentTotal - $priorTotal) / $priorTotal) * 100, 1)
            : 0;

        return [
            'current_month' => $current,
            'current_total' => (float) $currentTotal,
            'prior_total'   => (float) $priorTotal,
            'pct_change'    => $change,
        ];
    }

    /**
     * Encounter submission timeliness: % submitted within 30/60/90 days of service.
     *
     * @param  int  $tenantId  Tenant
     * @return array{total: int, rate_30: float, rate_60: float, rate_90: float, pending: int}
     */
    private function submissionKpi(int $tenantId): array
    {
        $cutoff90   = Carbon::now()->subDays(90);
        $encounters = EncounterLog::forTenant($tenantId)
            ->where('service_date', '>=', $cutoff90)
            ->get(['service_date', 'submission_status', 'submitted_at']);

        $total = $encounters->count();
        if ($total === 0) {
            return ['total' => 0, 'rate_30' => 0, 'rate_60' => 0, 'rate_90' => 0, 'pending' => 0];
        }

        $submittedWithin = function (int $days) use ($encounters) {
            return $encounters->filter(function ($e) use ($days) {
                if (!$e->submitted_at) {
                    return false;
                }
                return abs(Carbon::parse($e->service_date)->diffInDays(Carbon::parse($e->submitted_at))) <= $days;
            })->count();
        };

        $pending = $encounters->where('submission_status', 'pending')->count();

        return [
            'total'   => $total,
            'rate_30' => round(($submittedWithin(30) / $total) * 100, 1),
            'rate_60' => round(($submittedWithin(60) / $total) * 100, 1),
            'rate_90' => round(($submittedWithin(90) / $total) * 100, 1),
            'pending' => $pending,
        ];
    }

    /**
     * Rejection rate for encounters in the last 90 days.
     *
     * @param  int  $tenantId  Tenant
     * @return array{total: int, rejected: int, rejection_rate: float, top_reasons: array}
     */
    private function rejectionKpi(int $tenantId): array
    {
        $cutoff    = Carbon::now()->subDays(90);
        $submitted = EncounterLog::forTenant($tenantId)
            ->where('service_date', '>=', $cutoff)
            ->whereIn('submission_status', ['accepted', 'rejected'])
            ->get(['submission_status', 'rejection_reason']);

        $total    = $submitted->count();
        $rejected = $submitted->where('submission_status', 'rejected')->count();
        $rate     = $total > 0 ? round(($rejected / $total) * 100, 1) : 0;

        // Group rejection reasons for drill-down
        $reasons = $submitted->where('submission_status', 'rejected')
            ->groupBy('rejection_reason')
            ->map->count()
            ->sortDesc()
            ->take(5)
            ->toArray();

        return [
            'total'          => $total,
            'rejected'       => $rejected,
            'rejection_rate' => $rate,
            'top_reasons'    => $reasons,
        ];
    }

    /**
     * TrOOP accumulation: participants near/at Part D catastrophic threshold.
     *
     * @param  int  $tenantId  Tenant
     * @return array{threshold: float, at_catastrophic: int, near_catastrophic: int, total_tracked: int}
     */
    private function troopKpi(int $tenantId): array
    {
        $year      = Carbon::now()->year;
        $yearStart = Carbon::createFromDate($year, 1, 1)->startOfDay();

        // Group TrOOP by participant for current year
        $troopByParticipant = PdeRecord::where('tenant_id', $tenantId)
            ->where('dispense_date', '>=', $yearStart)
            ->groupBy('participant_id')
            ->selectRaw('participant_id, SUM(troop_amount) as ytd_troop')
            ->get();

        $threshold        = PdeRecord::TROOP_CATASTROPHIC_THRESHOLD;
        $atCatastrophic   = $troopByParticipant->where('ytd_troop', '>=', $threshold)->count();
        $nearCatastrophic = $troopByParticipant
            ->where('ytd_troop', '>=', $threshold * 0.8)
            ->where('ytd_troop', '<', $threshold)
            ->count();

        return [
            'threshold'         => $threshold,
            'at_catastrophic'   => $atCatastrophic,
            'near_catastrophic' => $nearCatastrophic,
            'total_tracked'     => $troopByParticipant->count(),
        ];
    }

    /**
     * HOS-M survey completion rate for current year.
     *
     * @param  int  $tenantId  Tenant
     * @return array{year: int, enrolled: int, completed: int, submitted_to_cms: int, completion_rate: float, submission_rate: float}
     */
    private function hosMKpi(int $tenantId): array
    {
        $year      = Carbon::now()->year;
        $enrolled  = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();
        $completed = HosMSurvey::forTenant($tenantId)->forYear($year)->where('completed', true)->count();
        $submitted = HosMSurvey::forTenant($tenantId)->forYear($year)->where('submitted_to_cms', true)->count();

        return [
            'year'             => $year,
            'enrolled'         => $enrolled,
            'completed'        => $completed,
            'submitted_to_cms' => $submitted,
            'completion_rate'  => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0,
            'submission_rate'  => $enrolled > 0 ? round(($submitted / $enrolled) * 100, 1) : 0,
        ];
    }

    /**
     * Encounter completeness: % of encounters with diagnosis codes populated.
     * A complete encounter has at least one diagnosis code AND a procedure code.
     *
     * @param  int  $tenantId  Tenant
     * @return array{total: int, complete: int, rate: float}
     */
    private function encounterCompletenessKpi(int $tenantId): array
    {
        $total = EncounterLog::forTenant($tenantId)->count();
        if ($total === 0) {
            return ['total' => 0, 'complete' => 0, 'rate' => 0];
        }

        $complete = EncounterLog::forTenant($tenantId)
            ->whereRaw("jsonb_array_length(COALESCE(diagnosis_codes, '[]'::jsonb)) > 0")
            ->whereNotNull('procedure_code')
            ->count();

        return [
            'total'    => $total,
            'complete' => $complete,
            'rate'     => round(($complete / $total) * 100, 1),
        ];
    }
}
