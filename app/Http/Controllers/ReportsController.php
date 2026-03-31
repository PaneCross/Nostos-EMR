<?php

// ─── ReportsController ────────────────────────────────────────────────────────
// Reports landing page — tabbed catalog of available reports with CSV export links.
// Aggregates from existing controllers: Finance, QA, IDT data.
// Access: all authenticated departments (filtered by role)
//
// Routes:
//   GET /reports             — Inertia page (tabbed report catalog)
//   GET /reports/data        — JSON: summary counts for KPI row
//   GET /reports/export      — CSV download (?type=census|disenrollments|sdr_compliance|care_plan_status)
//   GET /reports/site-transfers        — JSON: participants with completed site transfers
//   GET /reports/site-transfers/export — CSV download of site transfer report
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
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
                'export_url'  => '/reports/export?type=census',
            ],
            [
                'id'          => 'disenrollments',
                'title'       => 'Disenrollment Summary',
                'description' => 'Disenrollment reasons and trends over the selected period.',
                'category'    => 'Enrollment',
                'depts'       => ['enrollment', 'finance', 'executive', 'it_admin', 'qa_compliance'],
                'export_url'  => '/reports/export?type=disenrollments',
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
                'export_url'  => '/reports/export?type=sdr_compliance',
            ],
            [
                'id'          => 'care_plan_status',
                'title'       => 'Care Plan Status',
                'description' => 'Care plan review schedule — upcoming reviews, overdue, and approval status.',
                'category'    => 'Clinical',
                'depts'       => ['idt', 'primary_care', 'qa_compliance', 'it_admin'],
                'export_url'  => '/reports/export?type=care_plan_status',
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

    // ─── W3-8: General CSV exports ────────────────────────────────────────────

    /**
     * GET /reports/export?type=census|disenrollments|sdr_compliance|care_plan_status
     * CSV download for the four enrollment/clinical reports that previously had no export.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $tid  = $user->tenant_id;
        $type = $request->input('type', '');

        $allowedExportDepts = [
            'finance', 'qa_compliance', 'it_admin', 'idt', 'enrollment', 'executive', 'super_admin',
        ];
        if (!$user->isSuperAdmin() && !in_array($user->department, $allowedExportDepts, true)) {
            abort(403);
        }

        return match ($type) {
            'census'           => $this->exportCensus($tid),
            'disenrollments'   => $this->exportDisenrollments($tid),
            'sdr_compliance'   => $this->exportSdrCompliance($tid),
            'care_plan_status' => $this->exportCarePlanStatus($tid),
            default            => abort(400, 'Unknown report type.'),
        };
    }

    /** CSV: all enrolled/disenrolled participants with demographics + status. */
    private function exportCensus(int $tid): StreamedResponse
    {
        $participants = Participant::where('tenant_id', $tid)
            ->with('site:id,name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return response()->stream(function () use ($participants) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Last Name', 'First Name', 'MRN', 'DOB', 'Enrollment Status',
                'Site', 'Enrollment Date', 'Disenrollment Date', 'Disenrollment Reason',
            ]);
            foreach ($participants as $p) {
                fputcsv($out, [
                    $p->last_name,
                    $p->first_name,
                    $p->mrn,
                    $p->dob?->format('Y-m-d') ?? '-',
                    $p->enrollment_status,
                    $p->site?->name ?? '-',
                    $p->enrollment_date?->format('Y-m-d') ?? '-',
                    $p->disenrollment_date?->format('Y-m-d') ?? '-',
                    $p->disenrollment_reason ?? '-',
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="census-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /** CSV: all disenrolled participants with reason and timeline. */
    private function exportDisenrollments(int $tid): StreamedResponse
    {
        $participants = Participant::where('tenant_id', $tid)
            ->whereIn('enrollment_status', ['disenrolled', 'deceased', 'withdrawn'])
            ->whereNotNull('disenrollment_date')
            ->with('site:id,name')
            ->orderByDesc('disenrollment_date')
            ->get();

        return response()->stream(function () use ($participants) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Last Name', 'First Name', 'MRN', 'DOB',
                'Site', 'Enrollment Date', 'Disenrollment Date', 'Reason',
            ]);
            foreach ($participants as $p) {
                fputcsv($out, [
                    $p->last_name,
                    $p->first_name,
                    $p->mrn,
                    $p->dob?->format('Y-m-d') ?? '-',
                    $p->site?->name ?? '-',
                    $p->enrollment_date?->format('Y-m-d') ?? '-',
                    $p->disenrollment_date?->format('Y-m-d') ?? '-',
                    $p->disenrollment_reason ?? '-',
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="disenrollments-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /** CSV: all SDRs with department, submission time, due time, and 72h compliance flag. */
    private function exportSdrCompliance(int $tid): StreamedResponse
    {
        $sdrs = Sdr::where('tenant_id', $tid)
            ->with([
                'participant:id,first_name,last_name,mrn',
                'requestingUser:id,first_name,last_name',
            ])
            ->orderByDesc('submitted_at')
            ->get();

        return response()->stream(function () use ($sdrs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Participant', 'MRN', 'Request Type', 'Department',
                'Priority', 'Status', 'Submitted At', 'Due At',
                '72h Compliant', 'Requested By',
            ]);
            foreach ($sdrs as $sdr) {
                $compliant = $sdr->submitted_at && $sdr->due_at
                    ? ($sdr->submitted_at->lte($sdr->due_at) ? 'Yes' : 'No')
                    : '-';
                fputcsv($out, [
                    $sdr->participant
                        ? $sdr->participant->first_name . ' ' . $sdr->participant->last_name
                        : '-',
                    $sdr->participant?->mrn ?? '-',
                    $sdr->request_type ?? '-',
                    $sdr->assigned_department ?? '-',
                    $sdr->priority ?? '-',
                    $sdr->status,
                    $sdr->submitted_at?->format('Y-m-d H:i') ?? '-',
                    $sdr->due_at?->format('Y-m-d H:i') ?? '-',
                    $compliant,
                    $sdr->requestingUser
                        ? $sdr->requestingUser->first_name . ' ' . $sdr->requestingUser->last_name
                        : 'System',
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sdr-compliance-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /** CSV: all active care plans with review schedule, status, and days until/since review. */
    private function exportCarePlanStatus(int $tid): StreamedResponse
    {
        $plans = CarePlan::where('tenant_id', $tid)
            ->whereIn('status', ['active', 'under_review', 'draft'])
            ->with('participant:id,first_name,last_name,mrn')
            ->orderBy('review_due_date')
            ->get();

        return response()->stream(function () use ($plans) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Participant', 'MRN', 'Plan Status', 'Version',
                'Effective Date', 'Review Due Date', 'Days Until Review',
                'Approved By', 'Approved At',
            ]);
            foreach ($plans as $plan) {
                $daysUntil = $plan->review_due_date
                    ? (int) now()->diffInDays($plan->review_due_date, false)
                    : null;
                fputcsv($out, [
                    $plan->participant
                        ? $plan->participant->first_name . ' ' . $plan->participant->last_name
                        : '-',
                    $plan->participant?->mrn ?? '-',
                    $plan->status,
                    $plan->version ?? '1',
                    $plan->effective_date?->format('Y-m-d') ?? '-',
                    $plan->review_due_date?->format('Y-m-d') ?? '-',
                    $daysUntil !== null ? $daysUntil : '-',
                    $plan->approved_by_user_id ? 'Yes' : 'No',
                    $plan->approved_at?->format('Y-m-d') ?? '-',
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="care-plan-status-' . now()->format('Y-m-d') . '.csv"',
        ]);
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
