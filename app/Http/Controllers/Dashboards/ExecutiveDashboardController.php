<?php

// ─── ExecutiveDashboardController ────────────────────────────────────────────
// Phase 10B — JSON widget endpoints for the Executive dashboard.
//
// All 4 endpoints return pure JSON (not Inertia). They are loaded in parallel
// by ExecutiveDashboard.tsx via Promise.all on component mount.
//
// Access control:
//   - executive department: own tenant data only
//   - role='super_admin' or department='super_admin': all tenants (cross-tenant)
//
// Routes (all GET, under /dashboards/executive/):
//   GET /dashboards/executive/org-overview      — org-wide participant + enrollment stats
//   GET /dashboards/executive/site-comparison   — per-site participant + care-plan counts
//   GET /dashboards/executive/financial-overview — capitation totals per site
//   GET /dashboards/executive/sites-list        — all tenant sites for the site switcher
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Models\CapitationRecord;
use App\Models\Participant;
use App\Models\Referral;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ExecutiveDashboardController
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Restrict to same tenant unless user is a global admin (role or dept SA). */
    private function tenantId(): int
    {
        return Auth::user()->tenant_id;
    }

    private function requireAccess(): void
    {
        $user = Auth::user();
        $allowed = $user->isExecutive() || $user->isSuperAdmin() || $user->isDeptSuperAdmin();
        if (! $allowed) {
            abort(403, 'Executive dashboard access required.');
        }
    }

    // ── Widgets ───────────────────────────────────────────────────────────────

    /**
     * Org overview: total enrolled participants, pending enrollment, recent discharges.
     * Scoped to the user's tenant (executives) or all tenants (SA).
     */
    public function orgOverview(): JsonResponse
    {
        $this->requireAccess();
        $tenantId = $this->tenantId();

        $enrolled = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        $pending = Referral::where('tenant_id', $tenantId)
            ->whereIn('status', ['intake_scheduled', 'intake_in_progress', 'intake_complete',
                'eligibility_pending', 'pending_enrollment'])
            ->count();

        $recentReferrals = Referral::where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $sites = Site::where('tenant_id', $tenantId)->where('is_active', true)->count();

        return response()->json([
            'enrolled'          => $enrolled,
            'pending_enrollment' => $pending,
            'new_referrals_30d' => $recentReferrals,
            'active_sites'      => $sites,
        ]);
    }

    /**
     * Site comparison: per-site participant count, enrollment ratio, and open care plans.
     */
    public function siteComparison(): JsonResponse
    {
        $this->requireAccess();
        $tenantId = $this->tenantId();

        $sites = Site::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get(['id', 'name']);

        $data = $sites->map(function (Site $site) {
            $enrolled = Participant::where('site_id', $site->id)
                ->where('enrollment_status', 'enrolled')
                ->count();

            $carePlans = DB::table('emr_care_plans')
                ->where('site_id', $site->id)
                ->whereIn('status', ['draft', 'under_review', 'active'])
                ->count();

            return [
                'site_id'        => $site->id,
                'site_name'      => $site->name,
                'enrolled'       => $enrolled,
                'active_care_plans' => $carePlans,
            ];
        });

        return response()->json(['sites' => $data]);
    }

    /**
     * Financial overview: current month capitation totals per site.
     */
    public function financialOverview(): JsonResponse
    {
        $this->requireAccess();
        $tenantId = $this->tenantId();
        $monthYear = now()->format('Y-m');

        // Capitation totals by participant's site
        $records = DB::table('emr_capitation_records as cr')
            ->join('emr_participants as p', 'p.id', '=', 'cr.participant_id')
            ->join('shared_sites as s', 's.id', '=', 'p.site_id')
            ->where('cr.tenant_id', $tenantId)
            ->where('cr.month_year', $monthYear)
            ->whereNull('cr.deleted_at')
            ->groupBy('s.id', 's.name')
            ->selectRaw('s.id as site_id, s.name as site_name, COUNT(*) as participant_count, SUM(cr.total_capitation) as total_capitation')
            ->get();

        $grandTotal = $records->sum('total_capitation');

        return response()->json([
            'month_year'  => $monthYear,
            'grand_total' => round((float) $grandTotal, 2),
            'by_site'     => $records->map(fn ($r) => [
                'site_id'           => $r->site_id,
                'site_name'         => $r->site_name,
                'participant_count' => $r->participant_count,
                'total_capitation'  => round((float) $r->total_capitation, 2),
            ]),
        ]);
    }

    /**
     * Sites list: all tenant sites for the site-switcher cards widget.
     * Returns participant counts and whether a site is the current active site.
     */
    public function sitesList(): JsonResponse
    {
        $this->requireAccess();
        $tenantId = $this->tenantId();
        $activeSiteId = session('active_site_id') ?? Auth::user()->site_id;

        $sites = Site::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $data = $sites->map(function (Site $site) use ($activeSiteId) {
            $enrolled = Participant::where('site_id', $site->id)
                ->where('enrollment_status', 'enrolled')
                ->count();

            return [
                'id'        => $site->id,
                'name'      => $site->name,
                'city'      => $site->city,
                'state'     => $site->state,
                'enrolled'  => $enrolled,
                'is_active' => $site->id === $activeSiteId,
            ];
        });

        return response()->json(['sites' => $data]);
    }
}
