<?php

// ─── WebhookController ────────────────────────────────────────────────────────
// Handles incoming webhooks from the Nostos transport application.
//
// Route: POST /integrations/transport/status-webhook  (PUBLIC — no auth middleware)
//
// Security:
//   - HMAC-SHA256 signature validated via X-Transport-Signature header.
//   - Invalid signature → 403 + audit log entry (security event).
//   - Valid signature → immediately ACK with 200, then queue the job.
//
// Why public route + HMAC?
//   The transport app sends webhooks server-to-server. There's no authenticated
//   session to validate against. HMAC-SHA256 with a shared secret provides
//   equivalent integrity guarantees without requiring the transport app to
//   manage EMR session tokens.
//
// Why queue the job?
//   Transport apps have short webhook timeouts (≤5s). Queuing decouples the
//   EMR processing time from the transport app's ACK deadline.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Jobs\ProcessTransportStatusWebhookJob;
use App\Models\AuditLog;
use App\Services\TransportBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private readonly TransportBridgeService $bridge,
    ) {}

    /**
     * Receive and validate a status update webhook from the transport app.
     *
     * Expected payload:
     *   { transport_trip_id: int, status: string, scheduled_pickup_time?: string,
     *     actual_pickup_time?: string, actual_dropoff_time?: string, driver_notes?: string }
     *
     * Expected header: X-Transport-Signature: sha256=<hmac-sha256>
     */
    public function transportStatus(Request $request): JsonResponse
    {
        $rawBody  = $request->getContent();
        $signature = $request->header('X-Transport-Signature', '');

        // ── HMAC validation (fail-closed) ─────────────────────────────────────
        if (! $this->bridge->validateWebhookSignature($rawBody, $signature)) {
            Log::warning('[WebhookController] Invalid transport webhook signature', [
                'ip'        => $request->ip(),
                'signature' => $signature,
            ]);

            // Audit log the invalid attempt as a security event
            AuditLog::record(
                action:       'transport_webhook.invalid_signature',
                tenantId:     null,
                userId:       null,
                resourceType: 'webhook',
                resourceId:   null,
                description:  'Transport webhook rejected: invalid HMAC signature',
                newValues:    ['ip' => $request->ip()],
            );

            return response()->json(['error' => 'invalid_signature'], 403);
        }

        // ── Parse and validate payload ─────────────────────────────────────────
        $payload = $request->json()->all();

        if (empty($payload['transport_trip_id']) || empty($payload['status'])) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }

        // ── ACK immediately, process asynchronously ────────────────────────────
        // The job handles all DB writes; the transport app gets an immediate 200.
        ProcessTransportStatusWebhookJob::dispatch(
            transportTripId: (int) $payload['transport_trip_id'],
            newStatus:        $payload['status'],
            payload:          $payload,
        );

        return response()->json(['received' => true]);
    }
}
