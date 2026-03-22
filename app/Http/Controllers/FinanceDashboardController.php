<?php

// ─── FinanceDashboardController ───────────────────────────────────────────────
// Powers the Finance Dashboard (Inertia page) and CSV export for Finance staff.
//
// Route list:
//   GET /finance/dashboard             → dashboard()   (Inertia page)
//   GET /finance/reports/export        → exportCsv()   (CSV download)
//
// Dashboard KPIs (server-side):
//   1. Total capitation this month      — sum of total_capitation for current YYYY-MM
//   2. Authorizations expiring 30d      — count of active auths ending within 30 days
//   3. Encounters this month            — count of encounter_log rows for current month
//   4. Active participants              — count of enrolled participants in tenant
//
// Access: finance department only (enforced in route group by CheckDepartmentAccess).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Authorization;
use App\Models\CapitationRecord;
use App\Models\EncounterLog;
use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FinanceDashboardController extends Controller
{
    // ── Dashboard page ────────────────────────────────────────────────────────

    /**
     * Render the Finance Dashboard Inertia page with KPI cards pre-loaded.
     *
     * GET /finance/dashboard
     */
    public function dashboard(Request $request): InertiaResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $monthYear = now()->format('Y-m'); // current YYYY-MM for capitation lookup

        // KPI 1: Total CMS capitation this month (sum all participants for the tenant)
        $capitationThisMonth = CapitationRecord::forTenant($tenantId)
            ->forMonth($monthYear)
            ->sum('total_capitation');

        // KPI 2: Authorizations expiring within 30 days (needs renewal action)
        $authsExpiring = Authorization::forTenant($tenantId)
            ->expiringWithin(30)
            ->count();

        // KPI 3: Encounters logged this month (billable encounters)
        $encountersThisMonth = EncounterLog::forTenant($tenantId)
            ->whereYear('service_date', now()->year)
            ->whereMonth('service_date', now()->month)
            ->count();

        // KPI 4: Active enrolled participants in this tenant
        $activeParticipants = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        // Recent capitation records (last 3 months) for the summary table
        $recentCapitation = CapitationRecord::forTenant($tenantId)
            ->orderBy('month_year', 'desc')
            ->limit(60) // up to 2 months × ~30 participants
            ->get(['month_year', 'total_capitation', 'eligibility_category', 'participant_id'])
            ->groupBy('month_year')
            ->map(fn ($rows) => [
                'month_year'       => $rows->first()->month_year,
                'total'            => $rows->sum('total_capitation'),
                'participant_count'=> $rows->count(),
            ])
            ->values();

        // Authorizations expiring soon — full list for the table
        $expiringAuths = Authorization::forTenant($tenantId)
            ->expiringWithin(30)
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('authorized_end')
            ->get();

        return Inertia::render('Finance/Dashboard', [
            'kpis' => [
                'capitation_this_month' => (float) $capitationThisMonth,
                'auths_expiring_30d'    => $authsExpiring,
                'encounters_this_month' => $encountersThisMonth,
                'active_participants'   => $activeParticipants,
            ],
            'recentCapitation'  => $recentCapitation,
            'expiringAuths'     => $expiringAuths,
            'currentMonthYear'  => $monthYear,
            'serviceTypeLabels' => Authorization::SERVICE_TYPES,
        ]);
    }

    // ── CSV Export ────────────────────────────────────────────────────────────

    /**
     * Export Finance data as CSV.
     * ?type=capitation|encounters|authorizations
     *
     * GET /finance/reports/export
     */
    public function exportCsv(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;
        $type     = $request->query('type', 'encounters');

        $rows    = [];
        $headers = [];

        if ($type === 'capitation') {
            $headers = ['Month', 'Participant ID', 'Medicare A', 'Medicare B',
                        'Medicare D', 'Medicaid', 'Total', 'Eligibility Category', 'Recorded At'];

            CapitationRecord::forTenant($tenantId)
                ->orderBy('month_year', 'desc')
                ->orderBy('participant_id')
                ->each(function ($rec) use (&$rows) {
                    $rows[] = [
                        $rec->month_year,
                        $rec->participant_id,
                        $rec->medicare_a_rate,
                        $rec->medicare_b_rate,
                        $rec->medicare_d_rate,
                        $rec->medicaid_rate,
                        $rec->total_capitation,
                        $rec->eligibility_category ?? '',
                        $rec->recorded_at?->format('Y-m-d') ?? '',
                    ];
                });
        } elseif ($type === 'encounters') {
            $headers = ['ID', 'Service Date', 'Participant ID', 'Service Type',
                        'Procedure Code', 'Provider ID', 'Notes'];

            EncounterLog::forTenant($tenantId)
                ->orderBy('service_date', 'desc')
                ->each(function ($enc) use (&$rows) {
                    $rows[] = [
                        $enc->id,
                        $enc->service_date->format('Y-m-d'),
                        $enc->participant_id,
                        EncounterLog::SERVICE_TYPES[$enc->service_type] ?? $enc->service_type,
                        $enc->procedure_code ?? '',
                        $enc->provider_user_id ?? '',
                        $enc->notes ?? '',
                    ];
                });
        } elseif ($type === 'authorizations') {
            $headers = ['ID', 'Participant ID', 'Service Type', 'Units',
                        'Start Date', 'End Date', 'Status', 'Notes'];

            Authorization::forTenant($tenantId)
                ->orderBy('authorized_end', 'asc')
                ->each(function ($auth) use (&$rows) {
                    $rows[] = [
                        $auth->id,
                        $auth->participant_id,
                        Authorization::SERVICE_TYPES[$auth->service_type] ?? $auth->service_type,
                        $auth->authorized_units ?? '',
                        $auth->authorized_start->format('Y-m-d'),
                        $auth->authorized_end->format('Y-m-d'),
                        $auth->status,
                        $auth->notes ?? '',
                    ];
                });
        }

        // Build RFC 4180-compliant CSV
        $csv = implode(',', array_map(fn ($h) => '"' . $h . '"', $headers)) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(
                fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"',
                $row
            )) . "\n";
        }

        $filename = "finance_{$type}_" . now()->format('Y-m-d') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
