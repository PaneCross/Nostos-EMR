<?php

// ─── ReportsController ────────────────────────────────────────────────────────
// Reports landing page — tabbed catalog of available reports with CSV export links.
// Aggregates from existing controllers: Finance, QA, IDT data.
// Access: all authenticated departments (filtered by role)
//
// Routes:
//   GET /reports             — Inertia page (tabbed report catalog)
//   GET /reports/data        — JSON: summary counts for KPI row
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\ParticipantSiteTransfer;
use App\Models\Incident;
use App\Models\Sdr;
use App\Models\IdtMeeting;
use App\Models\EncounterLog;
use App\Models\CapitationRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    /**
     * GET /reports
     * Inertia reports landing page — catalog of available reports by category.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Build report catalog based on department access
        $reports = $this->buildCatalog($user);

        return Inertia::render('Reports/Index', [
            'reports'     => $reports,
            'department'  => $user->department,
            'canExport'   => in_array($user->department, [
                'finance', 'qa_compliance', 'it_admin', 'idt', 'enrollment', 'executive', 'super_admin'
            ]) || $user->role === 'super_admin',
        ]);
    }

    /**
     * GET /reports/data
     * JSON: KPI summary row displayed at the top of the reports page.
     */
    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        $tid  = $user->tenant_id;

        return response()->json([
            'kpis' => [
                'enrolled_participants' => Participant::where('tenant_id', $tid)
                    ->where('enrollment_status', 'enrolled')
                    ->count(),
                'open_incidents' => Incident::where('tenant_id', $tid)
                    ->whereNotIn('status', ['closed'])
                    ->count(),
                'overdue_sdrs' => Sdr::where('tenant_id', $tid)
                    ->where('status', 'open')
                    ->where('due_at', '<', now())
                    ->count(),
                'meetings_this_month' => IdtMeeting::where('tenant_id', $tid)
                    ->whereMonth('meeting_date', now()->month)
                    ->whereYear('meeting_date', now()->year)
                    ->count(),
            ],
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build the report catalog visible to the given user.
     * Each report has: id, title, description, category, export_url (nullable).
     */
    private function buildCatalog(mixed $user): array
    {
        $dept = $user->department;
        $isSA = $user->role === 'super_admin';

        $all = [
            // ── Census & Enrollment ───────────────────────────────────────────
            [
                'id'          => 'census',
                'title'       => 'Census Report',
                'description' => 'Monthly participant census by enrollment status, site, and age group.',
                'category'    => 'Enrollment',
                'depts'       => ['enrollment', 'finance', 'executive', 'it_admin', 'qa_compliance'],
                'export_url'  => null, // future: dedicated CSV endpoint
            ],
            [
                'id'          => 'disenrollments',
                'title'       => 'Disenrollment Summary',
                'description' => 'Disenrollment reasons and trends over the selected period.',
                'category'    => 'Enrollment',
                'depts'       => ['enrollment', 'finance', 'executive', 'it_admin', 'qa_compliance'],
                'export_url'  => null,
            ],
            // ── Quality & Compliance ──────────────────────────────────────────
            [
                'id'          => 'incidents_export',
                'title'       => 'Incident Log',
                'description' => 'All incidents with RCA status, severity, and resolution timeline.',
                'category'    => 'Quality',
                'depts'       => ['qa_compliance', 'it_admin', 'executive'],
                'export_url'  => '/qa/reports/export?type=incidents',
            ],
            [
                'id'          => 'unsigned_notes',
                'title'       => 'Unsigned Clinical Notes',
                'description' => 'Notes pending provider signature, grouped by department.',
                'category'    => 'Quality',
                'depts'       => ['qa_compliance', 'it_admin', 'primary_care', 'therapies', 'social_work'],
                'export_url'  => '/qa/reports/export?type=unsigned_notes',
            ],
            [
                'id'          => 'overdue_assessments',
                'title'       => 'Overdue Assessments',
                'description' => 'Assessments past their due date, sorted by days overdue.',
                'category'    => 'Quality',
                'depts'       => ['qa_compliance', 'it_admin', 'primary_care', 'therapies'],
                'export_url'  => '/qa/reports/export?type=overdue_assessments',
            ],
            // ── Finance & Billing ─────────────────────────────────────────────
            [
                'id'          => 'capitation_summary',
                'title'       => 'Capitation Summary',
                'description' => 'Monthly capitation payments across Part A, B, D, and Medicaid components.',
                'category'    => 'Finance',
                'depts'       => ['finance', 'executive', 'it_admin'],
                'export_url'  => '/finance/reports/export?type=capitation',
            ],
            [
                'id'          => 'encounter_log_export',
                'title'       => 'Encounter Log',
                'description' => 'All encounter records with service type, submission status, and billing codes.',
                'category'    => 'Finance',
                'depts'       => ['finance', 'executive', 'it_admin'],
                'export_url'  => '/finance/reports/export?type=encounters',
            ],
            [
                'id'          => 'auth_summary',
                'title'       => 'Authorization Summary',
                'description' => 'Active, expiring, and expired service authorizations.',
                'category'    => 'Finance',
                'depts'       => ['finance', 'it_admin'],
                'export_url'  => '/finance/reports/export?type=authorizations',
            ],
            // ── IDT & Clinical ─────────────────────────────────────────────
            [
                'id'          => 'sdr_compliance',
                'title'       => 'SDR Compliance Report',
                'description' => '72-hour SDR compliance rate by department and submission timeliness.',
                'category'    => 'Clinical',
                'depts'       => ['idt', 'qa_compliance', 'it_admin', 'executive'],
                'export_url'  => null,
            ],
            [
                'id'          => 'care_plan_status',
                'title'       => 'Care Plan Status',
                'description' => 'Care plan review schedule — upcoming reviews, overdue, and approval status.',
                'category'    => 'Clinical',
                'depts'       => ['idt', 'primary_care', 'qa_compliance', 'it_admin'],
                'export_url'  => null,
            ],
            // ── Audit & Administration ───────────────────────────────────────
            [
                'id'          => 'user_activity',
                'title'       => 'User Activity Audit',
                'description' => 'Login events, page access, and PHI access log for all users.',
                'category'    => 'Administration',
                'depts'       => ['it_admin'],
                'export_url'  => '/it-admin/audit/export',
            ],
        ];

        // Filter by department unless super_admin
        if (!$isSA) {
            $all = array_filter($all, fn ($r) => in_array($dept, $r['depts']));
        }

        return array_values($all);
    }

    // ─── W3-6: By PACE Site report ────────────────────────────────────────────

    /**
     * GET /reports/site-transfers
     * JSON: participants who have completed site transfers, with their transfer history.
     * Finance/QA/IT/Executive/SA access. Supports ?site_id= filter.
     */
    public function siteTransfers(Request $request): JsonResponse
    {
        $user = $request->user();
        $tid  = $user->tenant_id;

        $allowedDepts = ['finance', 'qa_compliance', 'it_admin', 'enrollment', 'executive'];
        if (!$user->isSuperAdmin() && !in_array($user->department, $allowedDepts, true)) {
            abort(403);
        }

        $query = ParticipantSiteTransfer::where('emr_participant_site_transfers.tenant_id', $tid)
            ->where('status', 'completed')
            ->with([
                'participant:id,tenant_id,first_name,last_name,mrn,site_id',
                'fromSite:id,name',
                'toSite:id,name',
            ])
            ->orderBy('effective_date', 'desc');

        if ($siteId = $request->input('site_id')) {
            $query->where(function ($q) use ($siteId) {
                $q->where('from_site_id', $siteId)
                  ->orWhere('to_site_id', $siteId);
            });
        }

        $transfers = $query->get()->map(fn ($t) => [
            'participant_id'   => $t->participant_id,
            'participant_name' => $t->participant
                ? $t->participant->first_name . ' ' . $t->participant->last_name
                : '-',
            'mrn'              => $t->participant?->mrn ?? '-',
            'from_site'        => $t->fromSite?->name ?? '-',
            'to_site'          => $t->toSite?->name ?? '-',
            'effective_date'   => $t->effective_date?->format('Y-m-d'),
            'transfer_reason'  => $t->transfer_reason,
        ]);

        // Group by participant: combine multiple transfers per participant
        $byParticipant = $transfers->groupBy('participant_id')->map(function ($rows) {
            $first = $rows->first();
            $allTransfers = $rows->map(fn ($r) => [
                'from'           => $r['from_site'],
                'to'             => $r['to_site'],
                'effective_date' => $r['effective_date'],
            ])->values()->toArray();
            return [
                'participant_id'   => $first['participant_id'],
                'participant_name' => $first['participant_name'],
                'mrn'              => $first['mrn'],
                'current_site'     => $rows->last()['to_site'],
                'prior_sites'      => $rows->pluck('from_site')->unique()->implode(', '),
                'transfer_dates'   => $rows->pluck('effective_date')->implode(', '),
                'transfers'        => $allTransfers,
                'transfer_count'   => count($allTransfers),
            ];
        })->values();

        // Sites list for filter dropdown
        $sites = DB::table('shared_sites')
            ->where('tenant_id', $tid)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'participants' => $byParticipant,
            'sites'        => $sites,
            'total'        => $byParticipant->count(),
        ]);
    }

    /**
     * GET /reports/site-transfers/export
     * CSV download of site transfer report.
     */
    public function siteTransfersExport(Request $request): StreamedResponse
    {
        $user = $request->user();
        $tid  = $user->tenant_id;

        $allowedDepts = ['finance', 'qa_compliance', 'it_admin', 'enrollment', 'executive'];
        if (!$user->isSuperAdmin() && !in_array($user->department, $allowedDepts, true)) {
            abort(403);
        }

        $transfers = ParticipantSiteTransfer::where('emr_participant_site_transfers.tenant_id', $tid)
            ->where('status', 'completed')
            ->with(['participant:id,first_name,last_name,mrn', 'fromSite:id,name', 'toSite:id,name'])
            ->orderBy('effective_date', 'desc')
            ->get();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="site-transfers-' . now()->format('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () use ($transfers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Participant Name', 'MRN', 'From Site', 'To Site', 'Effective Date', 'Reason']);
            foreach ($transfers as $t) {
                fputcsv($out, [
                    $t->participant ? $t->participant->first_name . ' ' . $t->participant->last_name : '-',
                    $t->participant?->mrn ?? '-',
                    $t->fromSite?->name ?? '-',
                    $t->toSite?->name ?? '-',
                    $t->effective_date?->format('Y-m-d') ?? '-',
                    $t->transfer_reason ?? '-',
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }
}
