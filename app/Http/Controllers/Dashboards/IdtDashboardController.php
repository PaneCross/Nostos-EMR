<?php

// ─── IdtDashboardController ────────────────────────────────────────────────────
// JSON widget endpoints for the IDT / Care Coordination department dashboard.
// All endpoints require the idt department (or super_admin).
// IDT has cross-department visibility into SDRs and alerts.
//
// Routes (GET, all under /dashboards/idt/):
//   meetings          — today's IDT meetings with Start Meeting links
//   overdue-sdrs      — escalated SDRs grouped by originating department
//   care-plans        — care plans with review_due_date within 30 days
//   alerts            — last 24h alerts across all departments, all severities
//   idt-review-overdue — participants overdue for 6-month IDT reassessment (W4-5)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\CarePlan;
use App\Models\IdtMeeting;
use App\Models\Participant;
use App\Models\Sdr;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class IdtDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    /** Abort 403 if the authenticated user is not idt or super_admin. */
    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'idt') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Today's IDT meetings with meeting type, time, status, and facilitator.
     * If no meeting today, returns flag for the UI to show 'Schedule Meeting' CTA.
     */
    public function meetings(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $meetings = IdtMeeting::where('tenant_id', $tenantId)
            ->today()
            ->with(['facilitator:id,first_name,last_name', 'site:id,name'])
            ->orderBy('meeting_time')
            ->get()
            ->map(fn (IdtMeeting $m) => [
                'id'            => $m->id,
                'meeting_type'  => $m->meeting_type,
                'type_label'    => $m->typeLabel(),
                'meeting_date'  => $m->meeting_date?->toDateString(),
                'meeting_time'  => $m->meeting_time,
                'status'        => $m->status,
                'facilitator'   => $m->facilitator
                    ? $m->facilitator->first_name . ' ' . $m->facilitator->last_name
                    : null,
                'site'          => $m->site?->name,
                // Route used by 'Start Meeting' button — matches existing GET /idt/meetings/{id}
                'run_url'       => "/idt/meetings/{$m->id}",
                'href'          => "/idt/meetings/{$m->id}",
            ]);

        return response()->json([
            'meetings'             => $meetings,
            'count'                => $meetings->count(),
            'has_meeting_today'    => $meetings->isNotEmpty(),
        ]);
    }

    /**
     * Escalated SDRs across all departments (escalated=true, not completed/cancelled).
     * Grouped by assigned_department so IDT can follow up with each team.
     */
    public function overdueSdrs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $sdrs = Sdr::where('tenant_id', $tenantId)
            ->where('escalated', true)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['participant:id,first_name,last_name,mrn'])
            ->orderBy('due_at')
            ->limit(50)
            ->get()
            ->map(fn (Sdr $s) => [
                'id'                  => $s->id,
                'participant'         => $s->participant ? [
                    'id'   => $s->participant->id,
                    'name' => $s->participant->first_name . ' ' . $s->participant->last_name,
                    'mrn'  => $s->participant->mrn,
                ] : null,
                'request_type'        => $s->request_type,
                'type_label'          => Sdr::TYPE_LABELS[$s->request_type] ?? $s->request_type,
                'assigned_department' => $s->assigned_department,
                'status'              => $s->status,
                'priority'            => $s->priority,
                'due_at'              => $s->due_at?->toDateTimeString(),
                'hours_overdue'       => $s->due_at
                    ? abs((int) now()->diffInHours($s->due_at))
                    : null,
                'href'                => '/sdrs',
            ]);

        // Group by department for the IDT escalation view
        $grouped = $sdrs->groupBy('assigned_department')
            ->map(fn ($items, $dept) => [
                'department' => $dept,
                'count'      => $items->count(),
                'sdrs'       => $items->values(),
            ])
            ->values();

        return response()->json([
            'departments'  => $grouped,
            'total_count'  => $sdrs->count(),
        ]);
    }

    /**
     * Care plans whose review_due_date is within the next 30 days (due soon or overdue).
     * Active states: draft, under_review, approved (not archived).
     * IDT schedules care plan review meetings based on this list.
     */
    public function carePlans(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $plans = CarePlan::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['archived'])
            ->whereNotNull('review_due_date')
            ->where('review_due_date', '<=', now()->addDays(30)->toDateString())
            ->with(['participant:id,first_name,last_name,mrn'])
            ->orderBy('review_due_date')
            ->limit(25)
            ->get()
            ->map(fn (CarePlan $p) => [
                'id'              => $p->id,
                'participant'     => $p->participant ? [
                    'id'   => $p->participant->id,
                    'name' => $p->participant->first_name . ' ' . $p->participant->last_name,
                    'mrn'  => $p->participant->mrn,
                ] : null,
                'status'          => $p->status,
                'review_due_date' => $p->review_due_date?->toDateString(),
                'is_overdue'      => $p->review_due_date?->isPast() ?? false,
                'days_until_due'  => $p->review_due_date
                    ? (int) now()->startOfDay()->diffInDays($p->review_due_date, false)
                    : null,
                'href'            => $p->participant
                    ? "/participants/{$p->participant->id}?tab=careplan"
                    : '/participants',
            ]);

        return response()->json([
            'care_plans'    => $plans,
            'overdue_count' => $plans->where('is_overdue', true)->count(),
            'due_soon_count'=> $plans->where('is_overdue', false)->count(),
        ]);
    }

    /**
     * Cross-department alert feed: all active alerts created in the last 24 hours.
     * IDT monitors this to coordinate cross-discipline follow-up.
     */
    public function alerts(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $alerts = Alert::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('created_at', '>=', now()->subHours(24))
            ->with(['participant:id,first_name,last_name'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'desc')
            ->limit(25)
            ->get()
            ->map(fn (Alert $a) => [
                'id'               => $a->id,
                'title'            => $a->title,
                'message'          => $a->message,
                'severity'         => $a->severity,
                'alert_type'       => $a->alert_type,
                'type_label'       => $a->typeLabel(),
                'target_depts'     => $a->target_departments,
                'acknowledged'     => $a->isAcknowledged(),
                'participant'      => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                ] : null,
                'created_at'       => $a->created_at?->diffForHumans(),
                'href'             => $a->participant
                    ? "/participants/{$a->participant->id}"
                    : '/alerts',
            ]);

        return response()->json([
            'alerts'          => $alerts,
            'critical_count'  => $alerts->where('severity', 'critical')->count(),
        ]);
    }

    /**
     * GET /dashboards/idt/idt-review-overdue
     * Participants whose last IDT review was more than 180 days ago (or who have
     * never been reviewed and have been enrolled more than 180 days).
     * 42 CFR §460.104(c): reassessment at least every 6 months.
     * Used by IdtReviewFrequencyJob and the IDT department dashboard widget.
     */
    public function idtReviewOverdue(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        // Load all enrolled participants and use the model method to check overdue status.
        // We limit DB round-trips by pulling all enrolled participants and their latest review
        // in a single query via subquery, then filtering in PHP (participant counts are small).
        $overdue = Participant::forTenant($tenantId)
            ->where('enrollment_status', 'enrolled')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->with('site:id,name')
            ->get()
            ->filter(fn (Participant $p) => $p->idtReviewOverdue())
            ->map(fn (Participant $p) => [
                'id'               => $p->id,
                'name'             => $p->first_name . ' ' . $p->last_name,
                'mrn'              => $p->mrn,
                'site'             => $p->site?->name,
                'enrollment_date'  => $p->enrollment_date?->toDateString(),
                'last_reviewed_at' => $p->lastIdtReviewedAt()?->toDateString(),
                'days_overdue'     => $p->lastIdtReviewedAt()
                    ? (int) $p->lastIdtReviewedAt()->diffInDays(now()) - 180
                    : null,
                'href'             => "/participants/{$p->id}?tab=assessments",
            ])
            ->values();

        return response()->json([
            'participants'   => $overdue,
            'overdue_count'  => $overdue->count(),
        ]);
    }
}
