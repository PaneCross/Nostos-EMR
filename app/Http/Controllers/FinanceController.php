<?php

// ─── FinanceController ────────────────────────────────────────────────────────
// REST API for Finance entities: Capitation Records, Encounter Log, Authorizations.
//
// Route list:
//   GET  /finance/capitation                  → capitationIndex()  (JSON)
//   POST /finance/capitation                  → capitationStore()  (JSON 201)
//   GET  /finance/encounters                  → encounterIndex()   (JSON)
//   POST /finance/encounters                  → encounterStore()   (JSON 201)
//   GET  /finance/authorizations              → authIndex()        (JSON)
//   POST /finance/authorizations              → authStore()        (JSON 201)
//   PUT  /finance/authorizations/{id}         → authUpdate()       (JSON 200)
//
// All routes require the 'finance' department (enforced in route middleware).
// AuditLog entries are created for every mutation.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Authorization;
use App\Models\CapitationRecord;
use App\Models\EncounterLog;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    // ── Capitation ────────────────────────────────────────────────────────────

    /**
     * List capitation records for the tenant.
     * Optional: ?month_year=YYYY-MM, ?participant_id=
     *
     * GET /finance/capitation
     */
    public function capitationIndex(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $query = CapitationRecord::forTenant($tenantId)
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('month_year', 'desc')
            ->orderBy('participant_id');

        if ($monthYear = $request->query('month_year')) {
            $query->forMonth($monthYear);
        }

        if ($participantId = $request->query('participant_id')) {
            $query->where('participant_id', $participantId);
        }

        return response()->json($query->paginate(100));
    }

    /**
     * Create a capitation record for a participant + month.
     * One record per participant per month_year (enforced by DB unique constraint).
     *
     * POST /finance/capitation
     */
    public function capitationStore(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'participant_id'       => ['required', 'integer', 'exists:emr_participants,id'],
            'month_year'           => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'medicare_a_rate'      => ['required', 'numeric', 'min:0'],
            'medicare_b_rate'      => ['required', 'numeric', 'min:0'],
            'medicare_d_rate'      => ['required', 'numeric', 'min:0'],
            'medicaid_rate'        => ['required', 'numeric', 'min:0'],
            'total_capitation'     => ['required', 'numeric', 'min:0'],
            'eligibility_category' => ['nullable', 'string', 'max:100'],
            'recorded_at'          => ['nullable', 'date'],
        ]);

        // Verify participant belongs to this tenant
        abort_if(
            !Participant::where('id', $data['participant_id'])
                ->where('tenant_id', $tenantId)
                ->exists(),
            403
        );

        $record = CapitationRecord::create(array_merge($data, ['tenant_id' => $tenantId]));

        AuditLog::record(
            action: 'finance.capitation.created',
            resourceType: 'CapitationRecord',
            resourceId: $record->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: $data
        );

        return response()->json($record->load('participant:id,mrn,first_name,last_name'), 201);
    }

    // ── Encounter Log ─────────────────────────────────────────────────────────

    /**
     * List encounter log entries for the tenant.
     * Optional: ?participant_id=, ?service_type=, ?from=YYYY-MM-DD, ?to=YYYY-MM-DD
     *
     * GET /finance/encounters
     */
    public function encounterIndex(Request $request): JsonResponse
    {
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
        if ($from = $request->query('from')) {
            $query->where('service_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('service_date', '<=', $to);
        }

        return response()->json($query->paginate(100));
    }

    /**
     * Log a new encounter for a participant.
     *
     * POST /finance/encounters
     */
    public function encounterStore(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'participant_id'   => ['required', 'integer', 'exists:emr_participants,id'],
            'service_date'     => ['required', 'date'],
            'service_type'     => ['required', Rule::in(array_keys(EncounterLog::SERVICE_TYPES))],
            'procedure_code'   => ['nullable', 'string', 'max:20'],
            'provider_user_id' => ['nullable', 'integer', 'exists:shared_users,id'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if(
            !Participant::where('id', $data['participant_id'])
                ->where('tenant_id', $tenantId)
                ->exists(),
            403
        );

        $encounter = EncounterLog::create(array_merge($data, [
            'tenant_id'          => $tenantId,
            'created_by_user_id' => $request->user()->id,
        ]));

        AuditLog::record(
            action: 'finance.encounter.logged',
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

    // ── Authorizations ────────────────────────────────────────────────────────

    /**
     * List service authorizations for the tenant.
     * Optional: ?participant_id=, ?status=, ?expiring_days=N
     *
     * GET /finance/authorizations
     */
    public function authIndex(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $query = Authorization::forTenant($tenantId)
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('authorized_end', 'asc');

        if ($pid = $request->query('participant_id')) {
            $query->where('participant_id', $pid);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($days = $request->query('expiring_days')) {
            $query->expiringWithin((int) $days);
        }

        return response()->json($query->paginate(100));
    }

    /**
     * Create a new service authorization.
     *
     * POST /finance/authorizations
     */
    public function authStore(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'participant_id'   => ['required', 'integer', 'exists:emr_participants,id'],
            'service_type'     => ['required', Rule::in(array_keys(Authorization::SERVICE_TYPES))],
            'authorized_units' => ['nullable', 'integer', 'min:1'],
            'authorized_start' => ['required', 'date'],
            'authorized_end'   => ['required', 'date', 'after:authorized_start'],
            'status'           => ['nullable', Rule::in(Authorization::STATUSES)],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);

        abort_if(
            !Participant::where('id', $data['participant_id'])
                ->where('tenant_id', $tenantId)
                ->exists(),
            403
        );

        $auth = Authorization::create(array_merge($data, ['tenant_id' => $tenantId]));

        AuditLog::record(
            action: 'finance.authorization.created',
            resourceType: 'Authorization',
            resourceId: $auth->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: $data
        );

        return response()->json($auth->load('participant:id,mrn,first_name,last_name'), 201);
    }

    /**
     * Update an authorization (e.g. cancel it, extend dates, update status).
     *
     * PUT /finance/authorizations/{authorization}
     */
    public function authUpdate(Request $request, Authorization $authorization): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        abort_if($authorization->tenant_id !== $tenantId, 403);

        $old = $authorization->only(['status', 'authorized_end', 'notes']);

        $data = $request->validate([
            'service_type'     => ['sometimes', Rule::in(array_keys(Authorization::SERVICE_TYPES))],
            'authorized_units' => ['nullable', 'integer', 'min:1'],
            'authorized_start' => ['sometimes', 'date'],
            'authorized_end'   => ['sometimes', 'date'],
            'status'           => ['sometimes', Rule::in(Authorization::STATUSES)],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);

        $authorization->update($data);

        AuditLog::record(
            action: 'finance.authorization.updated',
            resourceType: 'Authorization',
            resourceId: $authorization->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            oldValues: $old,
            newValues: $data
        );

        return response()->json($authorization->fresh()->load('participant:id,mrn,first_name,last_name'));
    }
}
