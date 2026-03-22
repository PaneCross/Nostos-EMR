<?php

// ─── IntegrationController ────────────────────────────────────────────────────
// Handles inbound integration payloads from external systems (HL7, Lab).
//
// Routes (PUBLIC — outside 'auth' session middleware, API-key authenticated):
//   POST /integrations/hl7/adt          → adtMessage()
//   POST /integrations/labs/result      → labResult()
//
// Authentication:
//   Requests must include X-Integration-Tenant header with a valid tenant_id.
//   For production, this would be an API key. For Phase 6D, tenant resolution
//   is done via X-Integration-Tenant header (simplified — upgrade in Phase 7).
//
// Design:
//   - Validate → log → dispatch job → return 202 immediately
//   - Processing happens asynchronously in the 'integrations' queue
//   - IT Admin can retry failed entries via POST /it-admin/integrations/{id}/retry
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Integrations\Hl7AdtConnector;
use App\Integrations\LabResultConnector;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    // ── HL7 ADT ───────────────────────────────────────────────────────────────

    /**
     * Receive an HL7 ADT message from an external hospital system.
     *
     * Required fields: message_type (A01|A03|A08), patient_mrn, event_datetime
     * Optional fields: facility, sending_facility, receiving_facility
     *
     * POST /integrations/hl7/adt
     */
    public function adtMessage(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenant($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Invalid or missing tenant identifier'], 401);
        }

        $validated = $request->validate([
            'message_type'   => ['required', 'string', 'in:A01,A03,A08'],
            'patient_mrn'    => ['required', 'string', 'max:50'],
            'event_datetime' => ['required', 'date'],
            'facility'       => ['nullable', 'string', 'max:200'],
        ]);

        $logEntry = Hl7AdtConnector::receive($validated, $tenantId);

        // HTTP 202: message received and queued (not yet processed)
        return response()->json([
            'received'           => true,
            'integration_log_id' => $logEntry->id,
            'status'             => 'queued',
        ], 202);
    }

    // ── Lab Results ───────────────────────────────────────────────────────────

    /**
     * Receive a lab result from an external laboratory system.
     *
     * Required fields: patient_mrn, test_code, test_name, value, unit, result_date
     * Optional fields: abnormal_flag (bool), reference_range
     *
     * POST /integrations/labs/result
     */
    public function labResult(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenant($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Invalid or missing tenant identifier'], 401);
        }

        $validated = $request->validate([
            'patient_mrn'   => ['required', 'string', 'max:50'],
            'test_code'     => ['required', 'string', 'max:50'],
            'test_name'     => ['required', 'string', 'max:200'],
            'value'         => ['required', 'string', 'max:50'],
            'unit'          => ['required', 'string', 'max:50'],
            'result_date'   => ['required', 'date'],
            'abnormal_flag' => ['nullable', 'boolean'],
        ]);

        $logEntry = LabResultConnector::receive($validated, $tenantId);

        return response()->json([
            'received'           => true,
            'integration_log_id' => $logEntry->id,
            'status'             => 'queued',
        ], 202);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve tenant_id from X-Integration-Tenant header.
     * Returns null if the tenant doesn't exist (invalid).
     */
    private function resolveTenant(Request $request): ?int
    {
        $tenantId = $request->header('X-Integration-Tenant');
        if (! $tenantId || ! is_numeric($tenantId)) {
            return null;
        }

        // Verify the tenant exists
        if (! Tenant::where('id', $tenantId)->exists()) {
            return null;
        }

        return (int) $tenantId;
    }
}
