<?php

// ─── Migration: emr_transport_requests ────────────────────────────────────────
// EMR-side transport request records.
//
// These are the EMR's own transport request records — distinct from the transport
// app's transport_trips table. Relationship:
//   emr_transport_requests → (bridge) → transport_trips (transport app)
//   transport_trip_id stores the ID in the transport app once bridged.
//
// When transport mode = 'broker', requests route to vendor dispatch instead.
//
// mobility_flags_snapshot (JSONB): captures active transport flags at request time.
// This snapshot ensures the run sheet shows the flags that were active WHEN the
// trip was requested, not just the current flags (which may have changed).
//
// Status lifecycle:
//   requested → scheduled → dispatched → en_route → arrived → completed
//   requested/scheduled/dispatched → cancelled
//   en_route/arrived → no_show
//
// trip_type:
//   to_center    — participant coming to PACE center for scheduled service
//   from_center  — participant going home after day at center
//   external_appt — trip to external appointment (specialist, dialysis, etc.)
//   will_call    — participant will call when ready (return trip)
//   add_on       — unscheduled same-day add-on request
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_transport_requests', function (Blueprint $table) {
            $table->id();

            // ── Tenant scope ───────────────────────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();
            $table->foreignId('appointment_id')
                ->nullable()
                ->constrained('emr_appointments')
                ->nullOnDelete();

            // ── Requester ─────────────────────────────────────────────────────
            $table->foreignId('requesting_user_id')
                ->constrained('shared_users')
                ->cascadeOnDelete();
            $table->string('requesting_department', 50);

            // ── Trip classification ───────────────────────────────────────────
            $table->enum('trip_type', [
                'to_center',
                'from_center',
                'external_appt',
                'will_call',
                'add_on',
            ]);

            // ── Locations ─────────────────────────────────────────────────────
            $table->foreignId('pickup_location_id')
                ->constrained('emr_locations')
                ->cascadeOnDelete();
            $table->foreignId('dropoff_location_id')
                ->constrained('emr_locations')
                ->cascadeOnDelete();

            // ── Timing ────────────────────────────────────────────────────────
            $table->timestamp('requested_pickup_time');
            $table->timestamp('scheduled_pickup_time')->nullable();
            $table->timestamp('actual_pickup_time')->nullable();
            $table->timestamp('actual_dropoff_time')->nullable();

            // ── Instructions + mobility snapshot ──────────────────────────────
            $table->text('special_instructions')->nullable();

            // Snapshot of active transport flags at time of request.
            // Stored as JSONB so run sheets reflect flags as-requested,
            // independent of any subsequent flag changes on the participant.
            $table->jsonb('mobility_flags_snapshot')->default('[]');

            // ── Status ────────────────────────────────────────────────────────
            $table->enum('status', [
                'requested',
                'scheduled',
                'dispatched',
                'en_route',
                'arrived',
                'completed',
                'no_show',
                'cancelled',
            ])->default('requested');

            // ── Transport app bridge ───────────────────────────────────────────
            // transport_trip_id: written by TransportBridgeService after bridging.
            // No FK — cross-app reference to transport_trips table.
            $table->unsignedBigInteger('transport_trip_id')->nullable()->index();
            $table->string('driver_notes')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'requested_pickup_time'], 'transport_req_participant_time_idx');
            $table->index(['tenant_id', 'status', 'requested_pickup_time'], 'transport_req_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_transport_requests');
    }
};
