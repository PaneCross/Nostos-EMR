<?php

// ─── TransportBridgeService ────────────────────────────────────────────────────
// Cross-app bridge between NostosEMR and the Nostos transport application.
//
// Architecture:
//   transport_* tables are owned by the transport app (read-only from EMR).
//   All writes to transport data go through this service via DB::table() only —
//   no Eloquent models exist for transport tables in the EMR codebase.
//
// Workflow for a transport request:
//   1. EMR creates emr_transport_requests record (TransportRequest model)
//   2. createTripRequest(TransportRequest) writes to transport_trips
//   3. transport_trip_id stored on the TransportRequest for cross-reference
//   4. Transport app processes the trip; sends status webhooks back
//   5. WebhookController dispatches ProcessTransportStatusWebhookJob
//   6. Job calls updateTripStatus() to sync status back to emr_transport_requests
//
// Failure handling:
//   All methods wrap in try/catch — if transport tables are unavailable, a
//   warning is logged and execution continues. Transport outages must NOT
//   break EMR clinical workflows.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\ParticipantFlag;
use App\Models\TransportRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TransportBridgeService
{
    private const AUDIT_RESOURCE = 'transport_bridge';

    /**
     * Sync a participant's core record to the transport system.
     * Gracefully logs a warning if transport tables are unavailable.
     */
    public function syncParticipant(Participant $ppt): void
    {
        try {
            // Nested DB::transaction() uses a savepoint so that a missing transport table
            // rolls back only this operation, not the caller's outer transaction.
            DB::transaction(function () use ($ppt) {
                $data = $this->buildParticipantPayload($ppt);
                DB::table('transport_participants')->updateOrInsert(
                    ['emr_participant_id' => $ppt->id],
                    $data
                );
                $this->auditBridge('sync_participant', $ppt->id, [
                    'mrn'    => $ppt->mrn,
                    'status' => 'synced',
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('[TransportBridge] syncParticipant failed — transport unavailable', [
                'participant_id' => $ppt->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync mobility/behavioral flags to the transport system.
     * Only sends flags relevant to transport (wheelchair, stretcher, oxygen, behavioral).
     */
    public function syncFlags(Participant $ppt): void
    {
        try {
            DB::transaction(function () use ($ppt) {
                $transportFlags = $ppt->activeFlags()
                    ->whereIn('flag_type', ParticipantFlag::TRANSPORT_FLAGS)
                    ->get()
                    ->map(fn ($f) => ['type' => $f->flag_type, 'severity' => $f->severity])
                    ->values()
                    ->toArray();

                DB::table('transport_participant_flags')
                    ->where('emr_participant_id', $ppt->id)
                    ->delete();

                foreach ($transportFlags as $flag) {
                    DB::table('transport_participant_flags')->insert([
                        'emr_participant_id' => $ppt->id,
                        'flag_type'          => $flag['type'],
                        'severity'           => $flag['severity'],
                        'synced_at'          => now(),
                    ]);
                }

                $this->auditBridge('sync_flags', $ppt->id, [
                    'flags_synced' => count($transportFlags),
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('[TransportBridge] syncFlags failed — transport unavailable', [
                'participant_id' => $ppt->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    // ─── Trip request management ──────────────────────────────────────────────

    /**
     * Write a transport request to the transport app's transport_trips table.
     * On success, stores transport_trip_id back on the emr_transport_requests record.
     * Returns the transport trip ID, or null if the transport app is unavailable.
     *
     * The mobility_flags_snapshot is already stored on the TransportRequest model —
     * this method sends the pickup/dropoff/time data to the transport side.
     */
    public function createTripRequest(TransportRequest $request): ?int
    {
        try {
            $tripId = (int) DB::transaction(function () use ($request) {
                return DB::table('transport_trips')->insertGetId([
                    'emr_participant_id'   => $request->participant_id,
                    'emr_transport_req_id' => $request->id,
                    'pickup_address'       => $request->pickupLocation?->fullAddress(),
                    'dropoff_address'      => $request->dropoffLocation?->fullAddress(),
                    'scheduled_at'         => $request->requested_pickup_time,
                    'trip_type'            => $request->trip_type,
                    'notes'                => $request->special_instructions,
                    'status'               => 'requested',
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            });

            // Store the cross-app reference back on the EMR record
            $request->update([
                'transport_trip_id' => $tripId,
                'last_synced_at'    => now(),
            ]);

            $this->auditBridge('create_trip', $request->participant_id, [
                'transport_request_id' => $request->id,
                'transport_trip_id'    => $tripId,
            ]);

            return $tripId;
        } catch (Throwable $e) {
            Log::warning('[TransportBridge] createTripRequest failed — transport unavailable', [
                'transport_request_id' => $request->id,
                'error'                => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Sync a status update from the transport app back to the EMR.
     * Called by ProcessTransportStatusWebhookJob after HMAC validation.
     *
     * $data may include: status, scheduled_pickup_time, actual_pickup_time,
     * actual_dropoff_time, driver_notes.
     */
    public function updateTripStatus(int $transportTripId, string $newStatus, array $data = []): void
    {
        try {
            // Find the EMR-side record by transport_trip_id (cross-app reference)
            $request = TransportRequest::where('transport_trip_id', $transportTripId)->first();

            if (! $request) {
                Log::warning('[TransportBridge] updateTripStatus — no matching TransportRequest', [
                    'transport_trip_id' => $transportTripId,
                    'new_status'        => $newStatus,
                ]);
                return;
            }

            $updatePayload = array_filter([
                'status'                => $newStatus,
                'scheduled_pickup_time' => $data['scheduled_pickup_time'] ?? null,
                'actual_pickup_time'    => $data['actual_pickup_time'] ?? null,
                'actual_dropoff_time'   => $data['actual_dropoff_time'] ?? null,
                'driver_notes'          => $data['driver_notes'] ?? null,
                'last_synced_at'        => now(),
            ], fn ($v) => $v !== null);

            $request->update($updatePayload);

            $this->auditBridge('status_update', $request->participant_id, [
                'transport_request_id' => $request->id,
                'transport_trip_id'    => $transportTripId,
                'new_status'           => $newStatus,
            ]);
        } catch (Throwable $e) {
            Log::error('[TransportBridge] updateTripStatus failed', [
                'transport_trip_id' => $transportTripId,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel a trip in both the EMR and the transport app.
     * Accepts a TransportRequest model (Phase 5B) or a legacy string trip ID.
     */
    public function cancelTrip(TransportRequest|string $requestOrTripId, string $reason): void
    {
        if ($requestOrTripId instanceof TransportRequest) {
            $transportTripId = $requestOrTripId->transport_trip_id;
            $participantId   = $requestOrTripId->participant_id;
            // Cancel the EMR-side record
            $requestOrTripId->cancel($reason);
        } else {
            // Legacy string-form call (pre-Phase 5B callers)
            $transportTripId = $requestOrTripId;
            $participantId   = null;
        }

        // Cancel in the transport app (best-effort)
        if ($transportTripId) {
            try {
                DB::transaction(function () use ($transportTripId, $reason) {
                    DB::table('transport_trips')
                        ->where('id', $transportTripId)
                        ->update([
                            'status'        => 'cancelled',
                            'cancel_reason' => $reason,
                            'updated_at'    => now(),
                        ]);
                });
            } catch (Throwable $e) {
                Log::warning('[TransportBridge] cancelTrip (transport app) failed — transport unavailable', [
                    'trip_id' => $transportTripId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->auditBridge('cancel_trip', $participantId, [
            'transport_trip_id' => $transportTripId,
            'reason'            => $reason,
        ]);
    }

    // ─── HMAC validation ──────────────────────────────────────────────────────

    /**
     * Validate the HMAC-SHA256 signature on an incoming transport webhook.
     * The transport app signs the raw request body with the shared secret
     * (config('services.transport.webhook_secret')) and sends it in
     * X-Transport-Signature header.
     *
     * Returns true if the signature is valid, false otherwise.
     * Fails closed: returns false if no secret is configured.
     */
    public function validateWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = config('services.transport.webhook_secret');

        if (! $secret) {
            Log::error('[TransportBridge] webhook_secret not configured — all webhooks rejected');
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        // hash_equals prevents timing-based attacks
        return hash_equals($expected, $signature);
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    private function buildParticipantPayload(Participant $ppt): array
    {
        return [
            'emr_participant_id' => $ppt->id,
            'tenant_id'          => $ppt->tenant_id,
            'site_id'            => $ppt->site_id,
            'mrn'                => $ppt->mrn,
            'first_name'         => $ppt->first_name,
            'last_name'          => $ppt->last_name,
            'preferred_name'     => $ppt->preferred_name,
            'dob'                => $ppt->dob?->toDateString(),
            'is_active'          => $ppt->is_active,
            'synced_at'          => now(),
        ];
    }

    private function auditBridge(string $action, ?int $participantId, array $context = []): void
    {
        AuditLog::record(
            action:       "transport_bridge.{$action}",
            tenantId:     null,
            userId:       null,
            resourceType: self::AUDIT_RESOURCE,
            resourceId:   $participantId,
            description:  "Transport bridge: {$action}",
            newValues:    $context,
        );
    }
}
