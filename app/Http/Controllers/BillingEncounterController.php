<?php

// ─── BillingEncounterController ───────────────────────────────────────────────
// REST API for the encounter submission queue — the billing-side view of
// EncounterLog records, enhanced with 837P fields for CMS submission.
//
// Route list:
//   GET  /billing/encounters                → index()   — paginated list w/ filters
//   POST /billing/encounters                → store()   — create encounter with 837P fields
//   PUT  /billing/encounters/{encounter}    → update()  — update (pending only)
//   POST /billing/encounters/batch          → batch()   — create EDI 837P batch
//
// Department access: finance only (+ super_admin, it_admin).
// AuditLog::record() called on every mutation.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EncounterLog;
use App\Models\Participant;
use App\Services\Edi837PBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BillingEncounterController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Guard: only finance, it_admin, or super_admin users may access billing.
     * Aborts with 403 if the requesting user is not authorized.
     */
    private function authorizeFinance(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'it_admin']),
            403
        );
    }

    // ── Encounter Index ───────────────────────────────────────────────────────

    /**
     * Paginated list of encounters with billing context.
     * Filters: ?participant_id= ?service_type= ?submission_status= ?date_from= ?date_to=
     *
     * GET /billing/encounters
     */
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $this->authorizeFinance($request);

        // Browser navigation (direct URL or Inertia SPA nav): Accept header is text/html.
        // Axios data-fetch from the mounted component: Accept is application/json.
        // wantsJson() correctly distinguishes the two cases.
        if (!$request->wantsJson() || $request->header('X-Inertia')) {
            return Inertia::render('Finance/Encounters');
        }

        // Axios data-fetch from the mounted React component → return JSON list.
        $tenantId = $request->user()->tenant_id;

        $query = EncounterLog::forTenant($tenantId)
            ->with(['participant:id,mrn,first_name,last_name', 'provider:id,first_name,last_name'])
            ->orderBy('service_date', 'desc');

        if ($pid = $request->query('participant_id')) {
            $query->where('participant_id', $pid);
        }
        if ($type = $request->query('service_type')) {
            $query->where('service_type', $type);
        }
        if ($status = $request->query('submission_status')) {
            $query->where('submission_status', $status);
        }
        if ($from = $request->query('date_from')) {
            $query->where('service_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->where('service_date', '<=', $to);
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

    // ── Encounter Store ───────────────────────────────────────────────────────

    /**
     * Create a new encounter with optional 837P billing fields.
     * diagnosis_codes must be a JSON array of valid ICD-10 strings.
     *
     * POST /billing/encounters
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'participant_id'         => ['required', 'integer', 'exists:emr_participants,id'],
            'service_date'           => ['required', 'date'],
            'service_type'           => ['required', Rule::in(array_keys(EncounterLog::SERVICE_TYPES))],
            'procedure_code'         => ['nullable', 'string', 'max:20'],
            'provider_user_id'       => ['nullable', 'integer', 'exists:shared_users,id'],
            'notes'                  => ['nullable', 'string', 'max:1000'],
            // 837P fields
            'billing_provider_npi'   => ['nullable', 'string', 'max:10'],
            'rendering_provider_npi' => ['nullable', 'string', 'max:10'],
            'service_facility_npi'   => ['nullable', 'string', 'max:10'],
            'diagnosis_codes'        => ['nullable', 'array'],
            'diagnosis_codes.*'      => ['string', 'max:10'],
            'procedure_modifier'     => ['nullable', 'string', 'max:10'],
            'place_of_service_code'  => ['nullable', 'string', 'max:2', Rule::in(array_keys(EncounterLog::PLACE_OF_SERVICE_CODES))],
            'units'                  => ['nullable', 'numeric', 'min:0.01'],
            'charge_amount'          => ['nullable', 'numeric', 'min:0'],
            'claim_type'             => ['nullable', Rule::in(EncounterLog::CLAIM_TYPES)],
        ]);

        // Tenant isolation: verify participant belongs to this tenant
        abort_if(
            !Participant::where('id', $data['participant_id'])
                ->where('tenant_id', $tenantId)
                ->exists(),
            403
        );

        $encounter = EncounterLog::create(array_merge($data, [
            'tenant_id'          => $tenantId,
            'created_by_user_id' => $request->user()->id,
            'submission_status'  => 'pending',
        ]));

        AuditLog::record(
            action: 'billing.encounter.create',
            resourceType: 'EncounterLog',
            resourceId: $encounter->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: $data
        );

        return response()->json(
            $encounter->load(['participant:id,mrn,first_name,last_name', 'provider:id,first_name,last_name']),
            201
        );
    }

    // ── Encounter Update ──────────────────────────────────────────────────────

    /**
     * Update an encounter — only allowed when submission_status = 'pending'.
     * Submitted or accepted encounters are immutable.
     *
     * PUT /billing/encounters/{encounter}
     */
    public function update(Request $request, EncounterLog $encounter): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        // Tenant isolation check
        abort_if($encounter->tenant_id !== $tenantId, 403);

        // Only pending encounters may be updated
        abort_if(
            $encounter->submission_status !== 'pending',
            409,
            'Encounter cannot be updated after submission. Current status: ' . $encounter->submission_status
        );

        $old = $encounter->only([
            'billing_provider_npi', 'diagnosis_codes', 'charge_amount', 'claim_type',
        ]);

        $data = $request->validate([
            'billing_provider_npi'   => ['nullable', 'string', 'max:10'],
            'rendering_provider_npi' => ['nullable', 'string', 'max:10'],
            'service_facility_npi'   => ['nullable', 'string', 'max:10'],
            'diagnosis_codes'        => ['nullable', 'array'],
            'diagnosis_codes.*'      => ['string', 'max:10'],
            'procedure_code'         => ['nullable', 'string', 'max:20'],
            'procedure_modifier'     => ['nullable', 'string', 'max:10'],
            'place_of_service_code'  => ['nullable', 'string', 'max:2'],
            'units'                  => ['nullable', 'numeric', 'min:0.01'],
            'charge_amount'          => ['nullable', 'numeric', 'min:0'],
            'claim_type'             => ['nullable', Rule::in(EncounterLog::CLAIM_TYPES)],
            'notes'                  => ['nullable', 'string', 'max:1000'],
        ]);

        $encounter->update($data);

        AuditLog::record(
            action: 'billing.encounter.update',
            resourceType: 'EncounterLog',
            resourceId: $encounter->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            oldValues: $old,
            newValues: $data
        );

        return response()->json(
            $encounter->fresh()->load(['participant:id,mrn,first_name,last_name'])
        );
    }

    // ── EDI Batch Creation ────────────────────────────────────────────────────

    /**
     * Create an EDI 837P batch from selected pending encounters.
     * Calls Edi837PBuilderService to generate the X12 file.
     *
     * POST /billing/encounters/batch
     * Body: { encounter_ids: [1, 2, 3, ...] }
     */
    public function batch(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'encounter_ids'   => ['required', 'array', 'min:1'],
            'encounter_ids.*' => ['integer'],
        ]);

        try {
            $service = new Edi837PBuilderService();
            $batch   = $service->generateEncounterBatch(
                $tenantId,
                $data['encounter_ids'],
                $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        AuditLog::record(
            action: 'edi_batch.create',
            resourceType: 'EdiBatch',
            resourceId: $batch->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: [
                'record_count'        => $batch->record_count,
                'total_charge_amount' => $batch->total_charge_amount,
                'encounter_ids'       => $data['encounter_ids'],
            ]
        );

        // Return batch without file_content (too large for API response)
        return response()->json($batch->only([
            'id', 'tenant_id', 'batch_type', 'file_name', 'record_count',
            'total_charge_amount', 'status', 'created_at',
        ]), 201);
    }
}
