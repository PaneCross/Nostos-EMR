<?php

// ─── CapitationController ─────────────────────────────────────────────────────
// Manages capitation records with HCC risk adjustment fields.
// Extends the existing FinanceController capitation endpoints with:
//   - Inertia page render
//   - JSON data endpoint for live widget refresh
//   - Bulk CSV import for monthly CMS remittance data
//
// Route list:
//   GET  /billing/capitation              → index()       — Inertia page
//   GET  /billing/capitation/data         → data()        — JSON KPIs + records
//   POST /billing/capitation              → store()       — create record
//   POST /billing/capitation/bulk-import  → bulkImport()  — CSV import
//
// CSV expected columns (in order, comma-delimited, first row = header):
//   participant_id OR medicare_id, month_year, total_capitation,
//   hcc_risk_score (optional), adjustment_type (optional)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CapitationRecord;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CapitationController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeFinance(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'it_admin']),
            403
        );
    }

    // ── Inertia Page ─────────────────────────────────────────────────────────

    /**
     * Render the Capitation management Inertia page.
     *
     * GET /billing/capitation
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeFinance($request);
        $tenantId  = $request->user()->tenant_id;
        $monthYear = now()->format('Y-m');

        $currentMonthTotal = CapitationRecord::forTenant($tenantId)
            ->forMonth($monthYear)
            ->sum('total_capitation');

        $participantCount = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        $avgRaf = CapitationRecord::forTenant($tenantId)
            ->forMonth($monthYear)
            ->whereNotNull('hcc_risk_score')
            ->avg('hcc_risk_score');

        $recentRecords = CapitationRecord::forTenant($tenantId)
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('month_year', 'desc')
            ->limit(100)
            ->get();

        return Inertia::render('Finance/Capitation', [
            'kpis' => [
                'current_month_total' => (float) $currentMonthTotal,
                'participant_count'   => $participantCount,
                'avg_raf_score'       => $avgRaf ? round((float) $avgRaf, 4) : null,
            ],
            'records'          => $recentRecords,
            'currentMonthYear' => $monthYear,
        ]);
    }

    /**
     * Return JSON data for the capitation widget (live refresh).
     *
     * GET /billing/capitation/data
     */
    public function data(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId  = $request->user()->tenant_id;
        $monthYear = $request->query('month_year', now()->format('Y-m'));

        $currentMonthTotal = CapitationRecord::forTenant($tenantId)
            ->forMonth($monthYear)
            ->sum('total_capitation');

        $avgRaf = CapitationRecord::forTenant($tenantId)
            ->forMonth($monthYear)
            ->whereNotNull('hcc_risk_score')
            ->avg('hcc_risk_score');

        $records = CapitationRecord::forTenant($tenantId)
            ->forMonth($monthYear)
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('participant_id')
            ->get();

        return response()->json([
            'kpis' => [
                'current_month_total' => (float) $currentMonthTotal,
                'avg_raf_score'       => $avgRaf ? round((float) $avgRaf, 4) : null,
            ],
            'records' => $records,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    /**
     * Create a capitation record for a participant + month.
     *
     * POST /billing/capitation
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        // Early cross-tenant isolation: reject before full validation so 403 is returned
        // when participant_id is provided but belongs to a different tenant.
        if ($pid = $request->input('participant_id')) {
            abort_if(
                !Participant::where('id', $pid)->where('tenant_id', $tenantId)->exists(),
                403
            );
        }

        $data = $request->validate([
            'participant_id'       => ['required', 'integer', 'exists:emr_participants,id'],
            'month_year'           => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'medicare_a_rate'      => ['required', 'numeric', 'min:0'],
            'medicare_b_rate'      => ['required', 'numeric', 'min:0'],
            'medicare_d_rate'      => ['required', 'numeric', 'min:0'],
            'medicaid_rate'        => ['required', 'numeric', 'min:0'],
            'total_capitation'     => ['required', 'numeric', 'min:0'],
            'eligibility_category' => ['nullable', 'string', 'max:100'],
            'hcc_risk_score'       => ['nullable', 'numeric', 'min:0'],
            'frailty_score'        => ['nullable', 'numeric', 'min:0'],
            'county_fips_code'     => ['nullable', 'string', 'size:5'],
            'adjustment_type'      => ['nullable', 'string', 'in:initial,mid_year,final'],
            'medicare_ab_rate'     => ['nullable', 'numeric', 'min:0'],
            'private_pay_rate'     => ['nullable', 'numeric', 'min:0'],
            'rate_effective_date'  => ['nullable', 'date'],
        ]);

        $record = CapitationRecord::create(array_merge($data, ['tenant_id' => $tenantId]));

        AuditLog::record(
            action: 'billing.capitation.create',
            resourceType: 'CapitationRecord',
            resourceId: $record->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: $data
        );

        return response()->json($record->load('participant:id,mrn,first_name,last_name'), 201);
    }

    // ── Bulk Import ───────────────────────────────────────────────────────────

    /**
     * Bulk import capitation records from a CSV file.
     * CSV columns: participant_id (or medicare_id), month_year, total_capitation,
     *              hcc_risk_score (optional), adjustment_type (optional)
     *
     * POST /billing/capitation/bulk-import
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        $request->validate([
            'csv_file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv', 'max:2048'],
        ]);

        $file    = $request->file('csv_file');
        $handle  = fopen($file->getPathname(), 'r');
        $headers = fgetcsv($handle); // skip header row

        if (!$headers) {
            fclose($handle);
            return response()->json(['error' => 'CSV file is empty or malformed.'], 422);
        }

        // Normalize header names
        $headers = array_map('strtolower', array_map('trim', $headers));

        $created = 0;
        $errors  = [];
        $row     = 1;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;
            $rowData = array_combine($headers, $line);

            // Resolve participant
            $participant = null;
            if (!empty($rowData['participant_id'])) {
                $participant = Participant::where('id', $rowData['participant_id'])
                    ->where('tenant_id', $tenantId)
                    ->first();
            } elseif (!empty($rowData['medicare_id'])) {
                $participant = Participant::where('medicare_id', $rowData['medicare_id'])
                    ->where('tenant_id', $tenantId)
                    ->first();
            }

            if (!$participant) {
                $errors[] = "Row {$row}: participant not found.";
                continue;
            }

            if (empty($rowData['month_year']) || !preg_match('/^\d{4}-\d{2}$/', $rowData['month_year'])) {
                $errors[] = "Row {$row}: invalid month_year format (expected YYYY-MM).";
                continue;
            }

            try {
                CapitationRecord::updateOrCreate(
                    ['participant_id' => $participant->id, 'month_year' => $rowData['month_year']],
                    [
                        'tenant_id'       => $tenantId,
                        'total_capitation'=> (float) ($rowData['total_capitation'] ?? 0),
                        'hcc_risk_score'  => isset($rowData['hcc_risk_score']) && $rowData['hcc_risk_score'] !== ''
                            ? (float) $rowData['hcc_risk_score']
                            : null,
                        'adjustment_type' => $rowData['adjustment_type'] ?? null,
                    ]
                );
                $created++;
            } catch (\Exception $e) {
                $errors[] = "Row {$row}: " . $e->getMessage();
            }
        }

        fclose($handle);

        AuditLog::record(
            action: 'billing.capitation.bulk_import',
            resourceType: 'CapitationRecord',
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: ['records_created' => $created, 'errors' => count($errors)]
        );

        return response()->json([
            'created' => $created,
            'errors'  => $errors,
        ], $created > 0 || empty($errors) ? 200 : 422);
    }
}
