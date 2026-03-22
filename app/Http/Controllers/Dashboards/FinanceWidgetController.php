<?php

// ─── FinanceWidgetController ───────────────────────────────────────────────────
// JSON widget endpoints for the Finance department live dashboard.
// Distinct from FinanceDashboardController (which serves the full /finance/dashboard
// Inertia page with capitation tables). This controller serves the 4 quick-glance
// widgets that appear on the /dashboard/finance dept landing page.
//
// All endpoints require the finance department (or super_admin).
//
// Routes (GET, all under /dashboards/finance/):
//   capitation          — current month total vs prior month comparison
//   authorizations      — active authorizations expiring within 30 days
//   enrollment-changes  — enrolled vs disenrolled participant counts this month
//   encounters          — encounter log total count (pending export)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Authorization;
use App\Models\CapitationRecord;
use App\Models\EncounterLog;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FinanceWidgetController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    /** Abort 403 if the authenticated user is not finance or super_admin. */
    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'finance') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Current month capitation total compared to the prior month.
     * PACE billing = monthly capitation per enrolled participant.
     * month_year format: 'YYYY-MM' (e.g. '2026-03').
     */
    public function capitation(): JsonResponse
    {
        $this->requireDept();
        $tenantId    = Auth::user()->tenant_id;
        $currentMonth = now()->format('Y-m');
        $priorMonth   = now()->subMonth()->format('Y-m');

        $currentTotal = CapitationRecord::where('tenant_id', $tenantId)
            ->where('month_year', $currentMonth)
            ->sum('total_capitation');

        $priorTotal = CapitationRecord::where('tenant_id', $tenantId)
            ->where('month_year', $priorMonth)
            ->sum('total_capitation');

        $currentCount = CapitationRecord::where('tenant_id', $tenantId)
            ->where('month_year', $currentMonth)
            ->count();

        // Month-over-month change (positive = increase, negative = decrease)
        $change = $priorTotal > 0
            ? round((($currentTotal - $priorTotal) / $priorTotal) * 100, 1)
            : null;

        return response()->json([
            'current_month'       => $currentMonth,
            'current_total'       => (float) $currentTotal,
            'current_participant_count' => $currentCount,
            'prior_month'         => $priorMonth,
            'prior_total'         => (float) $priorTotal,
            'change_percent'      => $change,
        ]);
    }

    /**
     * Active authorizations expiring within 30 days.
     * Finance must initiate renewal process before expiration to avoid billing gaps.
     * Ordered by expiration date ascending (soonest first).
     */
    public function authorizations(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $expiringAuths = Authorization::where('tenant_id', $tenantId)
            ->expiringWithin(30)
            ->with(['participant:id,first_name,last_name,mrn'])
            ->orderBy('authorized_end')
            ->limit(20)
            ->get()
            ->map(fn (Authorization $a) => [
                'id'               => $a->id,
                'participant'      => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                    'mrn'  => $a->participant->mrn,
                ] : null,
                'service_type'     => $a->service_type,
                'service_label'    => Authorization::SERVICE_TYPES[$a->service_type] ?? $a->service_type,
                'authorized_end'   => $a->authorized_end?->toDateString(),
                'days_until_expiry'=> $a->daysUntilExpiry(),
            ]);

        return response()->json([
            'authorizations'       => $expiringAuths,
            'expiring_count'       => Authorization::where('tenant_id', $tenantId)->expiringWithin(30)->count(),
            'expiring_this_week'   => Authorization::where('tenant_id', $tenantId)->expiringWithin(7)->count(),
        ]);
    }

    /**
     * Enrollment change counts for the current calendar month.
     * Enrolled = participants whose enrollment_date is this month.
     * Disenrolled = participants whose disenrollment_date is this month.
     */
    public function enrollmentChanges(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $enrolledCount = Participant::where('tenant_id', $tenantId)
            ->whereMonth('enrollment_date', now()->month)
            ->whereYear('enrollment_date', now()->year)
            ->count();

        $disenrolledCount = Participant::where('tenant_id', $tenantId)
            ->whereNotNull('disenrollment_date')
            ->whereMonth('disenrollment_date', now()->month)
            ->whereYear('disenrollment_date', now()->year)
            ->count();

        // Current enrolled census
        $totalEnrolled = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        return response()->json([
            'enrolled_this_month'    => $enrolledCount,
            'disenrolled_this_month' => $disenrolledCount,
            'total_enrolled'         => $totalEnrolled,
            'net_change'             => $enrolledCount - $disenrolledCount,
        ]);
    }

    /**
     * Encounter log pending export: total count of encounter records for this tenant.
     * Finance team exports these for CMS encounter data submission.
     */
    public function encounters(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $totalEncounters = EncounterLog::where('tenant_id', $tenantId)->count();

        $thisMonthEncounters = EncounterLog::where('tenant_id', $tenantId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        // Group this month's encounters by service_type for the breakdown
        $byType = EncounterLog::where('tenant_id', $tenantId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->get(['service_type'])
            ->groupBy('service_type')
            ->map(fn ($g) => $g->count())
            ->sortDesc()
            ->take(5);

        return response()->json([
            'total_encounters'       => $totalEncounters,
            'this_month_encounters'  => $thisMonthEncounters,
            'by_service_type'        => $byType,
        ]);
    }
}
