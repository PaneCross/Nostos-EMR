<?php

// ─── StateMedicaidConfigController ────────────────────────────────────────────
// Manages per-tenant state Medicaid encounter submission configuration.
//
// Route list:
//   GET    /it-admin/state-config          → index()   — Inertia page + JSON list
//   POST   /it-admin/state-config          → store()   — create config for a state
//   PUT    /it-admin/state-config/{config} → update()  — update existing config
//   DELETE /it-admin/state-config/{config} → destroy() — deactivate (soft-disable)
//
// Department access: it_admin only (+ super_admin).
// Finance can VIEW configs; only IT Admin can CREATE/UPDATE/DELETE.
//
// DEBT-038: State Medicaid encounter submission — configuration framework.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\StateMedicaidConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class StateMedicaidConfigController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /** IT Admin and super_admin can manage state configs. Finance can read (via index). */
    private function authorizeItAdmin(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && $user->department !== 'it_admin',
            403
        );
    }

    /** Finance, IT Admin, and super_admin can view state configs. */
    private function authorizeView(Request $request): void
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
     * Render the State Medicaid Config management Inertia page.
     *
     * GET /it-admin/state-config
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeView($request);
        $tenantId = $request->user()->tenant_id;

        $configs = StateMedicaidConfig::forTenant($tenantId)
            ->orderBy('state_code')
            ->get();

        return Inertia::render('ItAdmin/StateConfig', [
            'configs'           => $configs,
            'submissionFormats' => StateMedicaidConfig::SUBMISSION_FORMAT_LABELS,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    /**
     * Create a new state Medicaid configuration.
     *
     * POST /it-admin/state-config
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'state_code'            => [
                'required', 'string', 'size:2',
                Rule::unique('emr_state_medicaid_configs')->where('tenant_id', $tenantId),
            ],
            'state_name'            => ['required', 'string', 'max:100'],
            'submission_format'     => ['required', Rule::in(StateMedicaidConfig::SUBMISSION_FORMATS)],
            'companion_guide_notes' => ['nullable', 'string', 'max:5000'],
            'submission_endpoint'   => ['nullable', 'url', 'max:500'],
            'clearinghouse_name'    => ['nullable', 'string', 'max:200'],
            'days_to_submit'        => ['required', 'integer', 'min:1', 'max:365'],
            'effective_date'        => ['required', 'date'],
            'contact_name'          => ['nullable', 'string', 'max:200'],
            'contact_phone'         => ['nullable', 'string', 'max:20'],
            'contact_email'         => ['nullable', 'email', 'max:200'],
            'is_active'             => ['boolean'],
        ]);

        $config = StateMedicaidConfig::create(array_merge($data, [
            'tenant_id' => $tenantId,
            'state_code'=> strtoupper($data['state_code']),
        ]));

        AuditLog::record(
            action: 'state_medicaid_config.create',
            resourceType: 'StateMedicaidConfig',
            resourceId: $config->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: $data
        );

        return response()->json($config, 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * Update an existing state Medicaid configuration.
     *
     * PUT /it-admin/state-config/{config}
     */
    public function update(Request $request, StateMedicaidConfig $config): JsonResponse
    {
        $this->authorizeItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        abort_if($config->tenant_id !== $tenantId, 403);

        $old  = $config->toArray();
        $data = $request->validate([
            'submission_format'     => ['nullable', Rule::in(StateMedicaidConfig::SUBMISSION_FORMATS)],
            'companion_guide_notes' => ['nullable', 'string', 'max:5000'],
            'submission_endpoint'   => ['nullable', 'url', 'max:500'],
            'clearinghouse_name'    => ['nullable', 'string', 'max:200'],
            'days_to_submit'        => ['nullable', 'integer', 'min:1', 'max:365'],
            'effective_date'        => ['nullable', 'date'],
            'contact_name'          => ['nullable', 'string', 'max:200'],
            'contact_phone'         => ['nullable', 'string', 'max:20'],
            'contact_email'         => ['nullable', 'email', 'max:200'],
            'is_active'             => ['boolean'],
            'state_name'            => ['nullable', 'string', 'max:100'],
        ]);

        $config->update($data);

        AuditLog::record(
            action: 'state_medicaid_config.update',
            resourceType: 'StateMedicaidConfig',
            resourceId: $config->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            oldValues: $old,
            newValues: $data
        );

        return response()->json($config->fresh());
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    /**
     * Deactivate a state Medicaid configuration (soft-disable, not physical delete).
     * The config history is preserved for audit purposes.
     *
     * DELETE /it-admin/state-config/{config}
     */
    public function destroy(Request $request, StateMedicaidConfig $config): JsonResponse
    {
        $this->authorizeItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        abort_if($config->tenant_id !== $tenantId, 403);

        $config->update(['is_active' => false]);

        AuditLog::record(
            action: 'state_medicaid_config.deactivate',
            resourceType: 'StateMedicaidConfig',
            resourceId: $config->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: ['is_active' => false]
        );

        return response()->json(['message' => 'Configuration deactivated.']);
    }
}
