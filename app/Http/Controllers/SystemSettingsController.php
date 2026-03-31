<?php

// ─── SystemSettingsController ─────────────────────────────────────────────────
// Tenant-level system settings: PACE contract info, HIPAA config, integration
// parameters, and site management.  Read-only for all; write requires it_admin.
//
// Routes:
//   GET   /admin/settings      — Inertia settings page
//   PUT   /admin/settings      — Save tenant settings (it_admin only)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\StateMedicaidConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    /**
     * GET /admin/settings
     * Renders the system settings Inertia page.
     */
    public function index(Request $request): Response
    {
        $user   = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        // Current HIPAA session timeout (minutes) from config
        $sessionLifetime = config('session.lifetime', 120);

        // State Medicaid configs for this tenant
        $medicaidConfigs = StateMedicaidConfig::where('tenant_id', $user->tenant_id)
            ->orderBy('state_code')
            ->get(['id', 'state_code', 'submission_format', 'is_active']);

        return Inertia::render('ItAdmin/SystemSettings', [
            'tenant'          => $tenant ? [
                'id'              => $tenant->id,
                'name'            => $tenant->name,
                'slug'            => $tenant->slug,
                'pace_contract'   => $tenant->cms_contract_id ?? null,
                'state'           => $tenant->state ?? null,
                'timezone'        => $tenant->timezone ?? 'America/New_York',
                'hipaa_timeout'   => $sessionLifetime,
            ] : null,
            'medicaidConfigs' => $medicaidConfigs,
            'canEdit'         => in_array($user->department, ['it_admin'])
                                  || $user->role === 'super_admin',
            'integrationStatus' => $this->buildIntegrationStatus(),
        ]);
    }

    /**
     * PUT /admin/settings
     * Save tenant-level settings. it_admin only.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(
            $user->department === 'it_admin' || $user->role === 'super_admin',
            403,
            'Only IT Admin can update system settings.'
        );

        $validated = $request->validate([
            'pace_contract' => ['nullable', 'string', 'max:50'],
            'state'         => ['nullable', 'string', 'size:2'],
            'timezone'      => ['nullable', 'string', 'max:50'],
        ]);

        // Persist to tenant record — only fields that exist on the model
        $tenant = Tenant::find($user->tenant_id);
        if ($tenant) {
            $fillable = array_intersect_key($validated, array_flip($tenant->getFillable()));
            if (!empty($fillable)) {
                $tenant->update($fillable);
            }
        }

        AuditLog::record(
            action: 'system_settings.update',
            resourceType: 'Tenant',
            resourceId: $user->tenant_id,
            userId: $user->id,
            tenantId: $user->tenant_id,
            newValues: $validated,
        );

        return back()->with('success', 'Settings saved.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns a summary of integration connectivity statuses.
     * Real health-check logic can be added here as integrations go live.
     */
    private function buildIntegrationStatus(): array
    {
        return [
            [
                'name'        => 'HL7 ADT Inbound',
                'description' => 'Receives admit/discharge/transfer events from hospital systems.',
                'status'      => 'configured',
                'endpoint'    => '/integrations/hl7/adt',
            ],
            [
                'name'        => 'Lab Results Inbound',
                'description' => 'Receives lab result messages from reference laboratories.',
                'status'      => 'configured',
                'endpoint'    => '/integrations/labs/result',
            ],
            [
                'name'        => 'Nostos Transport',
                'description' => 'Bidirectional trip scheduling and status sync with Nostos Transport.',
                'status'      => 'pending',
                'endpoint'    => null,
            ],
            [
                'name'        => 'EDI Clearinghouse (837P)',
                'description' => 'Electronic claim submission to CMS via clearinghouse.',
                'status'      => 'pending',
                'endpoint'    => null,
            ],
            [
                'name'        => 'FHIR R4 API',
                'description' => 'Outbound FHIR API for EHR interoperability and ONC compliance.',
                'status'      => 'configured',
                'endpoint'    => '/fhir/R4',
            ],
        ];
    }
}
