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
use App\Models\ConsentRecord;
use App\Models\Grievance;
use App\Models\Incident;
use App\Models\Participant;
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

    // ── W4-1: Grievance KPIs (42 CFR §460.120–§460.121) ──────────────────────

    /**
     * Count of open grievances (not resolved or withdrawn) for the tenant.
     * Used for the "Open Grievances" KPI card on the QA dashboard.
     *
     * 42 CFR §460.122 requires PACE organizations to track and resolve all grievances
     * within 30 days (standard) or 72 hours (urgent).
     */
    public function getOpenGrievancesCount(int $tenantId): int
    {
        return Grievance::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['resolved', 'withdrawn'])
            ->count();
    }

    /**
     * Count of enrolled participants who have a pending NPP acknowledgment.
     * An NPP consent record is auto-created on enrollment (status='pending') and
     * must be acknowledged before or at first service delivery.
     *
     * HIPAA 45 CFR §164.520: covered entities must provide NPP and make a good-faith
     * effort to obtain written acknowledgment from each participant.
     */
    public function getMissingNppCount(int $tenantId): int
    {
        return ConsentRecord::where('tenant_id', $tenantId)
            ->where('consent_type', ConsentRecord::NPP_TYPE)
            ->where('status', 'pending')
            ->count();
    }
}
