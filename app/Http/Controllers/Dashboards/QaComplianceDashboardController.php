<?php

// ─── QaComplianceDashboardController ──────────────────────────────────────────
// JSON widget endpoints for the QA / Compliance department live dashboard.
// Delegates to QaMetricsService for all KPI computations.
// All endpoints require qa_compliance department (or super_admin).
// This is distinct from QaDashboardController (which serves the full Inertia
// QA dashboard at /qa/dashboard with tabs and CSV export).
//
// Routes (GET, all under /dashboards/qa-compliance/):
//   metrics     — all 6 QaMetricsService KPIs in a single response
//   incidents   — open incidents list (non-closed, ordered by severity)
//   docs        — unsigned notes >24h + overdue assessments summary
//   care-plans  — overdue care plans list
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\CarePlan;
use App\Models\ClinicalNote;
use App\Models\Incident;
use App\Services\QaMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class QaComplianceDashboardController extends Controller
{
    public function __construct(private readonly QaMetricsService $qaMetrics) {}

    // ── Department guard ──────────────────────────────────────────────────────

    /** Abort 403 if the authenticated user is not qa_compliance or super_admin. */
    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'qa_compliance') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * All 6 QA KPI metrics in a single widget response.
     * Delegates to QaMetricsService for consistency with the full QA dashboard.
     * Used to populate the KPI card row on the dept dashboard landing page.
     */
    public function metrics(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        return response()->json([
            'sdr_compliance_rate'      => $this->qaMetrics->getSdrComplianceRate($tenantId),
            'overdue_assessments_count'=> $this->qaMetrics->getOverdueAssessments($tenantId)->count(),
            'unsigned_notes_count'     => $this->qaMetrics->getUnsignedNotesOlderThan($tenantId)->count(),
            'open_incidents_count'     => $this->qaMetrics->getOpenIncidents($tenantId)->count(),
            'overdue_care_plans_count' => $this->qaMetrics->getCarePlansOverdue($tenantId)->count(),
            'hospitalizations_count'   => $this->qaMetrics->getHospitalizationsThisMonth($tenantId),
        ]);
    }

    /**
     * Open incidents (all non-closed statuses) ordered by severity.
     * Returns up to 15 incidents for the QA dashboard incident queue widget.
     */
    public function incidents(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $incidents = Incident::forTenant($tenantId)
            ->open()
            ->with(['participant:id,first_name,last_name,mrn', 'reportedBy:id,first_name,last_name'])
            ->orderByRaw("CASE status
                WHEN 'rca_in_progress' THEN 0
                WHEN 'under_review' THEN 1
                ELSE 2 END")
            ->orderBy('occurred_at', 'desc')
            ->limit(15)
            ->get()
            ->map(fn (Incident $i) => [
                'id'              => $i->id,
                'participant'     => $i->participant ? [
                    'id'   => $i->participant->id,
                    'name' => $i->participant->first_name . ' ' . $i->participant->last_name,
                    'mrn'  => $i->participant->mrn,
                ] : null,
                'incident_type'   => $i->incident_type,
                'status'          => $i->status,
                'rca_required'    => $i->rca_required,
                'rca_completed'   => $i->rca_completed,
                'occurred_at'     => $i->occurred_at?->toDateString(),
                'reported_by'     => $i->reportedBy
                    ? $i->reportedBy->first_name . ' ' . $i->reportedBy->last_name
                    : null,
                'href'            => '/qa/dashboard',
            ]);

        return response()->json([
            'incidents'         => $incidents,
            'open_count'        => Incident::forTenant($tenantId)->open()->count(),
            'rca_pending_count' => Incident::forTenant($tenantId)
                ->open()
                ->where('rca_required', true)
                ->where('rca_completed', false)
                ->count(),
        ]);
    }

    /**
     * Documentation compliance summary:
     *   - Unsigned clinical notes older than 24 hours (by department)
     *   - Overdue assessments (past next_due_date)
     * Quick-link to the full compliance detail on /qa/dashboard.
     */
    public function docs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $unsignedNotes = $this->qaMetrics->getUnsignedNotesOlderThan($tenantId)
            ->take(10)
            ->map(fn (ClinicalNote $n) => [
                'id'          => $n->id,
                'participant' => $n->participant ? [
                    'id'   => $n->participant->id,
                    'name' => $n->participant->first_name . ' ' . $n->participant->last_name,
                ] : null,
                'department'  => $n->department,
                'note_type'   => $n->note_type,
                'hours_old'   => abs((int) now()->diffInHours($n->created_at)),
                'href'        => $n->participant
                    ? "/participants/{$n->participant->id}?tab=chart"
                    : '/clinical/notes',
            ]);

        // Group unsigned notes by department for QA team follow-up
        $byDepartment = $unsignedNotes->groupBy('department')
            ->map(fn ($items) => $items->count());

        $overdueAssessments = $this->qaMetrics->getOverdueAssessments($tenantId)
            ->take(10)
            ->map(fn (Assessment $a) => [
                'id'              => $a->id,
                'participant'     => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                ] : null,
                'assessment_type' => $a->assessment_type,
                'next_due_date'   => $a->next_due_date?->toDateString(),
                'days_overdue'    => abs((int) now()->diffInDays($a->next_due_date)),
                'href'            => $a->participant
                    ? "/participants/{$a->participant->id}?tab=assessments"
                    : '/participants',
            ]);

        return response()->json([
            'unsigned_notes'       => $unsignedNotes->values(),
            'unsigned_count'       => $this->qaMetrics->getUnsignedNotesOlderThan($tenantId)->count(),
            'notes_by_department'  => $byDepartment,
            'overdue_assessments'  => $overdueAssessments->values(),
            'overdue_assess_count' => $this->qaMetrics->getOverdueAssessments($tenantId)->count(),
        ]);
    }

    /**
     * Overdue care plans (review_due_date in the past, status not archived).
     * QA monitors these for CMS compliance — care plans must be reviewed per schedule.
     */
    public function carePlans(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $plans = $this->qaMetrics->getCarePlansOverdue($tenantId)
            ->take(15)
            ->map(fn (CarePlan $p) => [
                'id'              => $p->id,
                'participant'     => $p->participant ? [
                    'id'   => $p->participant->id,
                    'name' => $p->participant->first_name . ' ' . $p->participant->last_name,
                    'mrn'  => $p->participant->mrn,
                ] : null,
                'status'          => $p->status,
                'review_due_date' => $p->review_due_date?->toDateString(),
                'days_overdue'    => abs((int) now()->diffInDays($p->review_due_date)),
                'href'            => $p->participant
                    ? "/participants/{$p->participant->id}?tab=careplan"
                    : '/participants',
            ]);

        return response()->json([
            'care_plans'    => $plans->values(),
            'overdue_count' => $this->qaMetrics->getCarePlansOverdue($tenantId)->count(),
        ]);
    }
}
