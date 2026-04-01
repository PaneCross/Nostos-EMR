<?php

// ─── QaDashboardController ────────────────────────────────────────────────────
// Powers the QA / Compliance Dashboard and compliance report endpoints.
//
// Route list:
//   GET /qa/dashboard                         → dashboard() (Inertia page)
//   GET /qa/compliance/unsigned-notes         → unsignedNotes()   (JSON)
//   GET /qa/compliance/overdue-assessments    → overdueAssessments() (JSON)
//   GET /qa/reports/export                    → exportCsv()  (CSV download)
//
// The dashboard page pre-loads the 6 KPI values server-side.
// Compliance tabs lazy-load their detail lists via dedicated JSON endpoints.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\BaaRecord;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\SraRecord;
use App\Services\QaMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class QaDashboardController extends Controller
{
    public function __construct(
        private readonly QaMetricsService $metrics,
    ) {}

    // ── Dashboard page ────────────────────────────────────────────────────────

    /**
     * Render the QA Dashboard Inertia page with 6 KPI values pre-loaded.
     * Incident list is passed as props (small dataset per tenant).
     *
     * GET /qa/dashboard
     */
    public function dashboard(Request $request): InertiaResponse
    {
        $tenantId = $request->user()->tenant_id;

        // Collect all 6 KPI metrics for the dashboard cards
        $kpis = [
            'sdr_compliance_rate'       => $this->metrics->getSdrComplianceRate($tenantId),
            'overdue_assessments_count' => $this->metrics->getOverdueAssessments($tenantId)->count(),
            'unsigned_notes_count'      => $this->metrics->getUnsignedNotesOlderThan($tenantId)->count(),
            'open_incidents_count'      => $this->metrics->getOpenIncidents($tenantId)->count(),
            'overdue_care_plans_count'  => $this->metrics->getCarePlansOverdue($tenantId)->count(),
            'hospitalizations_month'    => $this->metrics->getHospitalizationsThisMonth($tenantId),
            // W4-1: Grievance + consent KPIs (BLOCKER-02)
            'open_grievances_count'     => $this->metrics->getOpenGrievancesCount($tenantId),
            'missing_npp_count'         => $this->metrics->getMissingNppCount($tenantId),
        ];

        // Open incidents for the incident queue table (full load — typically <50)
        $openIncidents = Incident::forTenant($tenantId)
            ->open()
            ->with(['participant:id,mrn,first_name,last_name', 'reportedBy:id,first_name,last_name'])
            ->orderBy('occurred_at', 'desc')
            ->get();

        return Inertia::render('Qa/Dashboard', [
            'kpis'               => $kpis,
            'openIncidents'      => $openIncidents,
            'incidentTypes'      => Incident::TYPE_LABELS,
            'statuses'           => Incident::STATUS_LABELS,
            // W4-2: HIPAA security posture visible to QA / Compliance team
            'compliance_posture' => $this->buildCompliancePosture($tenantId),
        ]);
    }

    // ── Compliance detail endpoints ───────────────────────────────────────────

    /**
     * Return unsigned notes older than 24 hours for the tenant.
     * Used by the Unsigned Notes tab in the compliance section.
     *
     * GET /qa/compliance/unsigned-notes
     */
    public function unsignedNotes(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json(
            $this->metrics->getUnsignedNotesOlderThan($tenantId)->map(fn ($note) => [
                'id'             => $note->id,
                'note_type'      => $note->note_type,
                'participant'    => $note->participant ? [
                    'id'         => $note->participant->id,
                    'mrn'        => $note->participant->mrn,
                    'name'       => $note->participant->first_name . ' ' . $note->participant->last_name,
                ] : null,
                'author'         => $note->author ? $note->author->first_name . ' ' . $note->author->last_name : null,
                'department'     => $note->department,
                'created_at'     => $note->created_at->toIso8601String(),
                'hours_overdue'  => abs((int) now()->diffInHours($note->created_at)),
            ])
        );
    }

    /**
     * Return assessments past their next_due_date.
     * Used by the Overdue Assessments tab in the compliance section.
     *
     * GET /qa/compliance/overdue-assessments
     */
    public function overdueAssessments(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json(
            $this->metrics->getOverdueAssessments($tenantId)->map(fn ($a) => [
                'id'              => $a->id,
                'assessment_type' => $a->assessment_type,
                'participant'     => $a->participant ? [
                    'id'  => $a->participant->id,
                    'mrn' => $a->participant->mrn,
                    'name'=> $a->participant->first_name . ' ' . $a->participant->last_name,
                ] : null,
                'author'          => $a->author ? $a->author->first_name . ' ' . $a->author->last_name : null,
                'department'      => $a->department,
                'next_due_date'   => $a->next_due_date?->toDateString(),
                'days_overdue'    => abs((int) now()->diffInDays($a->next_due_date)),
            ])
        );
    }

    // ── Compliance posture (W4-2) ─────────────────────────────────────────────

    /**
     * Build a compact HIPAA security posture summary for the QA Dashboard.
     * Surfaces expired/expiring BAAs, SRA currency, and encryption config
     * so QA / Compliance staff can spot-check HIPAA posture without navigating
     * to the full Security & Compliance page (/it-admin/security).
     *
     * No PHI is included — all fields are counts or boolean flags.
     */
    private function buildCompliancePosture(int $tenantId): array
    {
        // BAA coverage — HIPAA 45 CFR §164.308(b)(1)
        $expiredBaaCount   = BaaRecord::forTenant($tenantId)->expired()->count();
        $expiringSoonCount = BaaRecord::forTenant($tenantId)->expiringSoon()->count();

        // SRA currency — HIPAA 45 CFR §164.308(a)(1): annual update required
        $latestSra  = SraRecord::forTenant($tenantId)
            ->completed()
            ->orderBy('sra_date', 'desc')
            ->first();
        // No completed SRA on record = overdue by definition
        $sraOverdue = $latestSra ? $latestSra->isOverdue() : true;

        // Encryption at rest — HIPAA 45 CFR §164.312(a)(2)(iv)
        $sessionEncrypted = config('session.encrypt', false) === true;
        $dbSslMode        = config('database.connections.pgsql.sslmode', 'prefer');
        $casts            = (new Participant())->getCasts();
        $fieldEncrypted   = isset($casts['medicare_id']) && $casts['medicare_id'] === 'encrypted';

        return [
            'expired_baa_count'   => $expiredBaaCount,
            'expiring_soon_count' => $expiringSoonCount,
            'sra_overdue'         => $sraOverdue,
            'session_encrypted'   => $sessionEncrypted,
            'db_ssl_enforced'     => $dbSslMode === 'require',
            'field_encryption'    => $fieldEncrypted,
            'latest_sra_date'     => $latestSra?->sra_date?->toDateString(),
        ];
    }

    // ── CSV Export ────────────────────────────────────────────────────────────

    /**
     * Export QA compliance data as CSV.
     * Includes: unsigned notes, overdue assessments, open incidents.
     *
     * GET /qa/reports/export
     */
    public function exportCsv(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;
        $type     = $request->query('type', 'incidents'); // incidents|unsigned_notes|overdue_assessments

        $rows    = [];
        $headers = [];

        if ($type === 'incidents') {
            $headers = ['ID', 'Type', 'Participant MRN', 'Participant Name', 'Occurred At',
                'Reported By', 'RCA Required', 'RCA Completed', 'Status', 'CMS Reportable'];

            $incidents = Incident::forTenant($tenantId)
                ->with(['participant:id,mrn,first_name,last_name', 'reportedBy:id,first_name,last_name'])
                ->orderBy('occurred_at', 'desc')
                ->get();

            foreach ($incidents as $inc) {
                $rows[] = [
                    $inc->id,
                    Incident::TYPE_LABELS[$inc->incident_type] ?? $inc->incident_type,
                    $inc->participant?->mrn ?? '',
                    $inc->participant ? $inc->participant->first_name . ' ' . $inc->participant->last_name : '',
                    $inc->occurred_at->format('Y-m-d H:i'),
                    $inc->reportedBy ? $inc->reportedBy->first_name . ' ' . $inc->reportedBy->last_name : '',
                    $inc->rca_required  ? 'Yes' : 'No',
                    $inc->rca_completed ? 'Yes' : 'No',
                    Incident::STATUS_LABELS[$inc->status] ?? $inc->status,
                    $inc->cms_reportable ? 'Yes' : 'No',
                ];
            }
        } elseif ($type === 'unsigned_notes') {
            $headers = ['Note ID', 'Type', 'Department', 'Participant MRN', 'Participant Name',
                'Author', 'Created At', 'Hours Overdue'];

            foreach ($this->metrics->getUnsignedNotesOlderThan($tenantId) as $note) {
                $rows[] = [
                    $note->id,
                    $note->note_type,
                    $note->department,
                    $note->participant?->mrn ?? '',
                    $note->participant ? $note->participant->first_name . ' ' . $note->participant->last_name : '',
                    $note->author ? $note->author->first_name . ' ' . $note->author->last_name : '',
                    $note->created_at->format('Y-m-d H:i'),
                    abs((int) now()->diffInHours($note->created_at)),
                ];
            }
        }

        // Build CSV output
        $csv  = implode(',', array_map(fn ($h) => '"' . $h . '"', $headers)) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
        }

        $filename = "qa_{$type}_" . now()->format('Y-m-d') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
