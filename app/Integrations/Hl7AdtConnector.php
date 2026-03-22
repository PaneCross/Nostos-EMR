<?php

// ─── Hl7AdtConnector ──────────────────────────────────────────────────────────
// Handles inbound HL7 v2 ADT (Admit-Discharge-Transfer) messages from hospitals.
//
// Supported event types:
//   A01 — Admit: participant has been admitted to a facility
//   A03 — Discharge: participant has been discharged
//   A08 — Update: demographic/encounter information updated (audit only)
//
// Message flow:
//   1. POST /integrations/hl7/adt (IntegrationController)
//   2. Controller validates payload, calls Hl7AdtConnector::receive()
//   3. Connector logs to emr_integration_log (status=pending)
//   4. Connector dispatches ProcessHl7AdtJob (async, 'integrations' queue)
//   5. IntegrationController returns HTTP 202 Accepted immediately
//
// Unknown MRNs: logged as failed (graceful — PACE participant may not be registered yet).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Integrations;

use App\Jobs\ProcessHl7AdtJob;
use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Log;

class Hl7AdtConnector
{
    /**
     * Receive an HL7 ADT payload, log it, and dispatch async processing job.
     *
     * @param  array $payload  Validated request data (message_type, patient_mrn, event_datetime, facility?)
     * @param  int   $tenantId Tenant resolved from API key or request context
     * @return IntegrationLog  The newly created log entry
     */
    public static function receive(array $payload, int $tenantId): IntegrationLog
    {
        // Log every inbound message before dispatching — so even if dispatch fails,
        // there is a record that the message was received.
        $logEntry = IntegrationLog::create([
            'tenant_id'      => $tenantId,
            'connector_type' => 'hl7_adt',
            'direction'      => 'inbound',
            'raw_payload'    => $payload,
            'status'         => 'pending',
        ]);

        Log::info('[Hl7AdtConnector] Received ADT message', [
            'integration_log_id' => $logEntry->id,
            'message_type'       => $payload['message_type'],
            'patient_mrn'        => $payload['patient_mrn'],
            'tenant_id'          => $tenantId,
        ]);

        // Dispatch async so the sending system gets an immediate HTTP 202 ACK
        ProcessHl7AdtJob::dispatch($logEntry->id, $payload, $tenantId)
            ->onQueue('integrations');

        return $logEntry;
    }
}
