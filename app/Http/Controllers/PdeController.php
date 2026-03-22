<?php

// ─── PdeController ────────────────────────────────────────────────────────────
// REST API for Part D Prescription Drug Event (PDE) records.
//
// Route list:
//   GET  /billing/pde        → index()  — paginated PDE list with filters
//   POST /billing/pde        → store()  — create PDE record
//   GET  /billing/pde/troop  → troop()  — TrOOP summary per participant for current year
//
// Department access: finance only (+ super_admin, it_admin).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\PdeRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PdeController extends Controller
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

    // ── PDE Index ─────────────────────────────────────────────────────────────

    /**
     * Paginated list of PDE records for the tenant.
     * Filters: ?participant_id= ?submission_status= ?from= ?to=
     *
     * GET /billing/pde
     */
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $this->authorizeFinance($request);

        // Browser navigation (direct URL or Inertia SPA nav): Accept header is text/html.
        // Axios data-fetch from the mounted component: Accept is application/json.
        if (!$request->wantsJson() || $request->header('X-Inertia')) {
            return Inertia::render('Finance/Pde');
        }

        // Axios data-fetch from the mounted React component → return JSON list.
        $tenantId = $request->user()->tenant_id;

        $query = PdeRecord::forTenant($tenantId)
            ->with(['participant:id,mrn,first_name,last_name'])
            ->orderBy('dispense_date', 'desc');

        if ($pid = $request->query('participant_id')) {
            $query->where('participant_id', $pid);
        }
        if ($status = $request->query('submission_status')) {
            $query->where('submission_status', $status);
        }
        if ($from = $request->query('from')) {
            $query->where('dispense_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('dispense_date', '<=', $to);
        }

        $paginated = $query->paginate(100);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    // ── PDE Store ─────────────────────────────────────────────────────────────

    /**
     * Create a PDE record for a dispensing event.
     * Validates that participant belongs to the requesting tenant.
     *
     * POST /billing/pde
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'participant_id'    => ['required', 'integer', 'exists:emr_participants,id'],
            'medication_id'     => ['nullable', 'integer', 'exists:emr_medications,id'],
            'drug_name'         => ['required', 'string', 'max:200'],
            'ndc_code'          => ['nullable', 'string', 'max:11'],
            'dispense_date'     => ['required', 'date'],
            'days_supply'       => ['required', 'integer', 'min:1', 'max:365'],
            'quantity_dispensed'=> ['required', 'numeric', 'min:0.001'],
            'ingredient_cost'   => ['required', 'numeric', 'min:0'],
            'dispensing_fee'    => ['required', 'numeric', 'min:0'],
            'patient_pay'       => ['required', 'numeric', 'min:0'],
            'troop_amount'      => ['required', 'numeric', 'min:0'],
            'pharmacy_npi'      => ['nullable', 'string', 'max:10'],
            'prescriber_npi'    => ['nullable', 'string', 'max:10'],
        ]);

        abort_if(
            !Participant::where('id', $data['participant_id'])
                ->where('tenant_id', $tenantId)
                ->exists(),
            403
        );

        $pde = PdeRecord::create(array_merge($data, [
            'tenant_id'         => $tenantId,
            'submission_status' => 'pending',
        ]));

        AuditLog::record(
            action: 'billing.pde.create',
            resourceType: 'PdeRecord',
            resourceId: $pde->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: $data
        );

        return response()->json($pde->load('participant:id,mrn,first_name,last_name'), 201);
    }

    // ── TrOOP Summary ─────────────────────────────────────────────────────────

    /**
     * Return year-to-date TrOOP accumulation per participant for the current year.
     * Highlights participants at or near the catastrophic threshold ($7,400).
     *
     * GET /billing/pde/troop
     */
    public function troop(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId  = $request->user()->tenant_id;
        $year      = (int) $request->query('year', now()->year);
        $yearStart = Carbon::createFromDate($year, 1, 1)->startOfDay();

        $troopSummary = PdeRecord::where('tenant_id', $tenantId)
            ->where('dispense_date', '>=', $yearStart)
            ->groupBy('participant_id')
            ->selectRaw('participant_id, SUM(troop_amount) as ytd_troop, COUNT(*) as record_count')
            ->with('participant:id,mrn,first_name,last_name')
            ->orderByRaw('ytd_troop DESC')
            ->get();

        $threshold = PdeRecord::TROOP_CATASTROPHIC_THRESHOLD;

        return response()->json([
            'year'      => $year,
            'threshold' => $threshold,
            'summary'   => $troopSummary->map(fn ($row) => [
                'participant_id'    => $row->participant_id,
                'participant'       => $row->participant,
                'ytd_troop'         => (float) $row->ytd_troop,
                'record_count'      => (int) $row->record_count,
                'near_threshold'    => $row->ytd_troop >= ($threshold * 0.8) && $row->ytd_troop < $threshold,
                'at_threshold'      => $row->ytd_troop >= $threshold,
            ]),
        ]);
    }
}
