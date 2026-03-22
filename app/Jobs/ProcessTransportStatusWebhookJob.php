<?php

// ─── ProcessTransportStatusWebhookJob ─────────────────────────────────────────
// Queued job that processes an incoming status update from the transport app.
//
// Flow:
//   1. Transport app POSTs to /integrations/transport/status-webhook
//   2. WebhookController validates HMAC-SHA256 signature
//   3. WebhookController immediately returns HTTP 200 (webhook ACK)
//   4. This job is dispatched to the 'transport-webhooks' queue
//   5. Job calls TransportBridgeService::updateTripStatus() to sync status
//   6. If new status is 'no_show', creates a clinical alert for primary_care
//
// Queuing the job decouples slow EMR processing from the transport app's
// webhook timeout (typically 5s). The transport app gets an immediate ACK.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\TransportRequest;
use App\Services\AlertService;
use App\Services\TransportBridgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTransportStatusWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int    $transportTripId,
        public readonly string $newStatus,
        public readonly array  $payload,
    ) {
        $this->onQueue('transport-webhooks');
    }

    public function handle(TransportBridgeService $bridge, AlertService $alertService): void
    {
        Log::info('[ProcessTransportStatusWebhookJob] Processing status update', [
            'transport_trip_id' => $this->transportTripId,
            'new_status'        => $this->newStatus,
        ]);

        // Sync the status + timing data back to emr_transport_requests
        $bridge->updateTripStatus($this->transportTripId, $this->newStatus, $this->payload);

        // If the transport app reports a no-show, create a clinical alert
        if ($this->newStatus === 'no_show') {
            $this->handleNoShow($alertService);
        }
    }

    /**
     * When the driver marks a participant as a no-show, alert primary_care so
     * they can follow up (wellness check, reschedule, update appointment record).
     */
    private function handleNoShow(AlertService $alertService): void
    {
        // Find the transport request to get tenant context for the alert
        $transportRequest = TransportRequest::where('transport_trip_id', $this->transportTripId)
            ->with('participant:id,first_name,last_name,mrn,tenant_id')
            ->first();

        if (! $transportRequest?->participant) {
            return;
        }

        $participant = $transportRequest->participant;
        $name        = trim("{$participant->first_name} {$participant->last_name}");

        // Alert uses array-style API (AlertService::create(array $data))
        $alertService->create([
            'tenant_id'          => $participant->tenant_id,
            'participant_id'     => $participant->id,
            'source_module'      => 'transport',
            'alert_type'         => 'info',
            'title'              => "Transport No-Show — {$name}",
            'message'            => "Participant {$name} (MRN: {$participant->mrn}) was marked as a no-show "
                                  . "by the transport driver. Please follow up.",
            'severity'           => 'warning',
            'target_departments' => ['primary_care'],
            'created_by_system'  => true,
        ]);

        AuditLog::record(
            action:       'transport.no_show_alert_created',
            tenantId:     $participant->tenant_id,
            userId:       null,
            resourceType: 'transport_request',
            resourceId:   $transportRequest->id,
            description:  "No-show alert created for {$name}",
        );
    }
}
