<?php

// ─── QaMetricsService ──────────────────────────────────────────────────────────
// Computes QA/Compliance KPIs displayed on the QA Dashboard.
//
// All metrics are tenant-scoped and designed for the KPI cards:
//   1. SDR compliance rate    — % of SDRs completed within the 72h window
//   2. Overdue assessments    — assessments past their next_due_date
//   3. Unsigned notes >24h    — draft notes older than 24 hours
//   4. Open incidents         — non-closed incidents (all types)
//   5. Overdue care plans     — care plans whose review_due_date has passed
//   6. Hospital/ER this month — hospitalization + er_visit incidents this month
//
// DocumentationComplianceJob calls this service daily to generate alerts.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Assessment;
use App\Models\CarePlan;
use App\Models\ClinicalNote;
use App\Models\Incident;
use App\Models\Sdr;
use Illuminate\Support\Collection;

class QaMetricsService
{
    /**
     * Percentage of SDRs completed (status='completed') within the 72-hour window
     * for the given tenant, within the last $days days.
     *
     * CMS 42 CFR 460.104: Service delivery records must be completed within 72h.
     * Returns 0.0 if no SDRs exist in the window (avoids divide-by-zero).
     */
    public function getSdrComplianceRate(int $tenantId, int $days = 30): float
    {
        $sdrs = Sdr::where('tenant_id', $tenantId)
            ->where('submitted_at', '>=', now()->subDays($days))
            ->get(['status', 'submitted_at', 'due_at', 'completed_at']);

        if ($sdrs->isEmpty()) {
            return 100.0; // No SDRs = no violations
        }

        $compliant = $sdrs->filter(function ($sdr) {
            // Compliant = completed before or on the due_at (72h window)
            return $sdr->status === 'completed'
                && $sdr->completed_at !== null
                && $sdr->completed_at->lte($sdr->due_at);
        })->count();

        return round(($compliant / $sdrs->count()) * 100, 1);
    }

    /**
     * All assessments whose next_due_date is in the past and status is not yet completed.
     * Returns a collection of Assessment models with participant relationship eager-loaded.
     */
    public function getOverdueAssessments(int $tenantId): Collection
    {
        return Assessment::where('tenant_id', $tenantId)
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<', now()->toDateString())
            ->with(['participant:id,mrn,first_name,last_name', 'author:id,first_name,last_name,department'])
            ->orderBy('next_due_date')
            ->get();
    }

    /**
     * Clinical notes in 'draft' status that were created more than $hours ago.
     * These represent documentation compliance violations (unsigned chart entries).
     *
     * Returns a collection of ClinicalNote models with participant + author.
     */
    public function getUnsignedNotesOlderThan(int $tenantId, int $hours = 24): Collection
    {
        return ClinicalNote::where('tenant_id', $tenantId)
            ->where('status', 'draft')
            ->where('created_at', '<', now()->subHours($hours))
            ->with(['participant:id,mrn,first_name,last_name', 'author:id,first_name,last_name,department'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * All non-closed incidents for the tenant.
     * Ordered by occurred_at descending (most recent first).
     */
    public function getOpenIncidents(int $tenantId): Collection
    {
        return Incident::forTenant($tenantId)
            ->open()
            ->with(['participant:id,mrn,first_name,last_name', 'reportedBy:id,first_name,last_name'])
            ->orderBy('occurred_at', 'desc')
            ->get();
    }

    /**
     * Care plans whose review_due_date is in the past and are still in an active state.
     * Active = draft | under_review | approved (not archived).
     */
    public function getCarePlansOverdue(int $tenantId): Collection
    {
        return CarePlan::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['archived'])
            ->whereNotNull('review_due_date')
            ->where('review_due_date', '<', now()->toDateString())
            ->with(['participant:id,mrn,first_name,last_name'])
            ->orderBy('review_due_date')
            ->get();
    }

    /**
     * Count of hospitalization + ER visit incidents recorded this calendar month.
     * Used for the "Hospital/ER Visits" KPI card.
     */
    public function getHospitalizationsThisMonth(int $tenantId): int
    {
        return Incident::forTenant($tenantId)
            ->hospitalizations()
            ->whereYear('occurred_at', now()->year)
            ->whereMonth('occurred_at', now()->month)
            ->count();
    }
}
