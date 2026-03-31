<?php

// ─── EnrollmentDashboardController ────────────────────────────────────────────
// JSON widget endpoints for the Enrollment department dashboard.
// All endpoints require the enrollment department (or super_admin).
//
// Routes (GET, all under /dashboards/enrollment/):
//   pipeline           — referral counts per pipeline status (Kanban column totals)
//   eligibility-pending — referrals awaiting eligibility verification, oldest first
//   disenrollments     — participants with upcoming disenrollment dates (within 30 days)
//   new-referrals      — new referrals received this calendar week
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\Referral;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EnrollmentDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    /** Abort 403 if the authenticated user is not enrollment or super_admin. */
    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'enrollment') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Referral pipeline counts per status column.
     * Clicking a status in the UI links to the Kanban board pre-filtered by that status.
     */
    public function pipeline(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        // Count per status, limited to pipeline statuses (exclude declined/withdrawn)
        $counts = Referral::forTenant($tenantId)
            ->active()
            ->get(['status'])
            ->groupBy('status')
            ->map(fn ($group) => $group->count());

        // Build ordered pipeline with counts, matching PIPELINE_STATUSES order
        $pipeline = [];
        foreach (Referral::PIPELINE_STATUSES as $status) {
            $pipeline[] = [
                'status'       => $status,
                'status_label' => Referral::STATUS_LABELS[$status] ?? $status,
                'count'        => $counts[$status] ?? 0,
            ];
        }

        $declined   = Referral::forTenant($tenantId)->where('status', 'declined')->count();
        $withdrawn  = Referral::forTenant($tenantId)->where('status', 'withdrawn')->count();

        return response()->json([
            'pipeline'          => $pipeline,
            'total_active'      => array_sum(array_column($pipeline, 'count')),
            'declined_this_month'  => Referral::forTenant($tenantId)
                ->where('status', 'declined')
                ->whereMonth('updated_at', now()->month)
                ->count(),
            'withdrawn_this_month' => Referral::forTenant($tenantId)
                ->where('status', 'withdrawn')
                ->whereMonth('updated_at', now()->month)
                ->count(),
        ]);
    }

    /**
     * Referrals awaiting eligibility verification (status = eligibility_pending).
     * Ordered oldest-first so long-pending cases surface first.
     * Medicare/Medicaid eligibility has CMS processing timelines.
     */
    public function eligibilityPending(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $referrals = Referral::forTenant($tenantId)
            ->where('status', 'eligibility_pending')
            ->with(['assignedTo:id,first_name,last_name'])
            ->orderBy('created_at', 'asc') // oldest first — longest-waiting cases surface
            ->limit(20)
            ->get()
            ->map(fn (Referral $r) => [
                'id'             => $r->id,
                'referred_name'  => $r->referred_by_name,
                'referral_date'  => $r->referral_date?->toDateString(),
                'days_pending'   => abs((int) now()->diffInDays($r->created_at)),
                'assigned_to'    => $r->assignedTo
                    ? $r->assignedTo->first_name . ' ' . $r->assignedTo->last_name
                    : null,
                'source'         => $r->sourceLabel(),
                'notes'          => $r->notes,
                'href'           => '/enrollment/referrals',
            ]);

        return response()->json([
            'referrals' => $referrals,
            'count'     => Referral::forTenant($tenantId)->where('status', 'eligibility_pending')->count(),
        ]);
    }

    /**
     * Participants with an upcoming disenrollment date (within the next 30 days).
     * Disenrollment requires CMS notification; enrollment team must prepare paperwork.
     */
    public function disenrollments(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $participants = Participant::where('tenant_id', $tenantId)
            ->whereNotNull('disenrollment_date')
            ->where('disenrollment_date', '>=', now()->toDateString())
            ->where('disenrollment_date', '<=', now()->addDays(30)->toDateString())
            ->orderBy('disenrollment_date')
            ->limit(20)
            ->get()
            ->map(fn (Participant $p) => [
                'id'                  => $p->id,
                'name'                => $p->first_name . ' ' . $p->last_name,
                'mrn'                 => $p->mrn,
                'enrollment_status'   => $p->enrollment_status,
                'disenrollment_date'  => $p->disenrollment_date?->toDateString(),
                'days_until'          => abs((int) now()->startOfDay()->diffInDays($p->disenrollment_date)),
                'disenrollment_reason'=> $p->disenrollment_reason,
                'href'                => "/participants/{$p->id}",
            ]);

        return response()->json([
            'participants' => $participants,
            'count'        => $participants->count(),
        ]);
    }

    /**
     * New referrals received this calendar week (Monday–today).
     * Enrollment team uses this for weekly census tracking.
     */
    public function newReferrals(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $weekStart = now()->startOfWeek(); // Monday

        $referrals = Referral::forTenant($tenantId)
            ->where('created_at', '>=', $weekStart)
            ->with(['assignedTo:id,first_name,last_name'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn (Referral $r) => [
                'id'            => $r->id,
                'referred_name' => $r->referred_by_name,
                'referral_date' => $r->referral_date?->toDateString(),
                'source'        => $r->sourceLabel(),
                'status'        => $r->status,
                'status_label'  => $r->statusLabel(),
                'assigned_to'   => $r->assignedTo
                    ? $r->assignedTo->first_name . ' ' . $r->assignedTo->last_name
                    : null,
                'created_at'    => $r->created_at?->diffForHumans(),
                'href'          => '/enrollment/referrals',
            ]);

        return response()->json([
            'referrals'   => $referrals,
            'week_count'  => $referrals->count(),
            'week_start'  => $weekStart->toDateString(),
        ]);
    }
}
