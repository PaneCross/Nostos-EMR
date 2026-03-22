<?php

// ─── LabResultConnector ───────────────────────────────────────────────────────
// Handles inbound lab result messages from external laboratory systems.
//
// Flow:
//   1. POST /integrations/labs/result (IntegrationController)
//   2. Controller validates payload, calls LabResultConnector::receive()
//   3. Connector logs to emr_integration_log (status=pending)
//   4. Connector dispatches ProcessLabResultJob (async, 'integrations' queue)
//   5. IntegrationController returns HTTP 202 Accepted immediately
//
// Abnormal results: creates an alert targeting primary_care for review.
// Unknown MRNs: logged as failed gracefully (participant may not be registered).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Integrations;

use App\Jobs\ProcessLabResultJob;
use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Log;

class LabResultConnector
{
    /**
     * Receive a lab result payload, log it, and dispatch async processing job.
     *
     * @param  array $payload  Validated request data (patient_mrn, test_code, test_name, value, unit, abnormal_flag, result_date)
     * @param  int   $tenantId Tenant resolved from API key or request context
     * @return IntegrationLog  The newly created log entry
     */
    public static function receive(array $payload, int $tenantId): IntegrationLog
    {
        // Log before dispatching so there is always a receipt record
        $logEntry = IntegrationLog::create([
            'tenant_id'      => $tenantId,
            'connector_type' => 'lab_results',
            'direction'      => 'inbound',
            'raw_payload'    => $payload,
            'status'         => 'pending',
        ]);

        Log::info('[LabResultConnector] Received lab result', [
            'integration_log_id' => $logEntry->id,
            'patient_mrn'        => $payload['patient_mrn'],
            'test_code'          => $payload['test_code'] ?? null,
            'abnormal_flag'      => $payload['abnormal_flag'] ?? false,
            'tenant_id'          => $tenantId,
        ]);

        ProcessLabResultJob::dispatch($logEntry->id, $payload, $tenantId)
            ->onQueue('integrations');

        return $logEntry;
    }
}
