<?php

// ─── Migration: emr_appointments ──────────────────────────────────────────────
// Participant appointment scheduling across all PACE service types.
//
// Appointment types cover all care delivery modalities in a PACE program:
//   clinic_visit        — PCP/NP visit at the PACE center
//   therapy_pt/ot/st    — Physical, Occupational, Speech therapy
//   social_work         — Social work counseling/assessment
//   behavioral_health   — Behavioral/mental health visit
//   dietary_consult     — Dietitian consultation
//   home_visit          — Any discipline visiting participant at home
//   external_referral   — Community specialist referral
//   specialist          — Contracted specialist visit (at PACE or offsite)
//   lab / imaging       — Diagnostics
//   activities          — Day center group/individual activity
//   telehealth          — Video/phone visit (any discipline)
//   day_center_attendance — Standard day center attendance day (blocks slot)
//
// Status lifecycle:
//   scheduled → confirmed → completed
//   scheduled / confirmed → cancelled (requires reason)
//   scheduled / confirmed → no_show
//
// Conflict detection:
//   ConflictDetectionService prevents overlapping appointments for the same
//   participant. Cancelled appointments are excluded from conflict checks.
//
// transport_request_id: references a transport.transport_requests record.
//   NOT a foreign-key constraint (cross-app reference, transport tables are
//   read-only from here). Populated via TransportBridgeService when transport
//   is arranged for this appointment.
//
// Soft deletes preserve historical records for audit trail.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_appointments', function (Blueprint $table) {
            $table->id();

            // ── Scope ─────────────────────────────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();
            $table->foreignId('site_id')
                ->constrained('shared_sites')
                ->restrictOnDelete();

            // ── Classification ────────────────────────────────────────────────
            $table->enum('appointment_type', [
                'clinic_visit',
                'therapy_pt',
                'therapy_ot',
                'therapy_st',
                'social_work',
                'behavioral_health',
                'dietary_consult',
                'home_visit',
                'external_referral',
                'specialist',
                'lab',
                'imaging',
                'activities',
                'telehealth',
                'day_center_attendance',
            ]);

            // ── Scheduling ────────────────────────────────────────────────────
            $table->foreignId('provider_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->foreignId('location_id')
                ->nullable()
                ->constrained('emr_locations')
                ->nullOnDelete();

            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');

            // ── Status ────────────────────────────────────────────────────────
            $table->enum('status', [
                'scheduled',
                'confirmed',
                'completed',
                'cancelled',
                'no_show',
            ])->default('scheduled');

            // ── Transport linkage ─────────────────────────────────────────────
            // transport_required: flag set by scheduler at booking time
            // transport_request_id: populated via TransportBridgeService once
            // a transport request is created in the transport app.
            // No FK constraint — cross-app reference (transport tables read-only).
            $table->boolean('transport_required')->default(false);
            $table->unsignedBigInteger('transport_request_id')->nullable();

            // ── Notes ─────────────────────────────────────────────────────────
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();   // Required when status=cancelled

            // ── Audit ─────────────────────────────────────────────────────────
            $table->foreignId('created_by_user_id')
                ->constrained('shared_users')
                ->restrictOnDelete();

            $table->softDeletes();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            // Primary conflict-detection query: participant + time range overlap
            $table->index(['participant_id', 'scheduled_start'], 'appts_participant_start_idx');
            // Provider calendar view
            $table->index(['provider_user_id', 'scheduled_start'], 'appts_provider_start_idx');
            // Department-level schedule dashboard
            $table->index(['tenant_id', 'status', 'scheduled_start'], 'appts_tenant_status_start_idx');
            // Transport linkage lookup
            $table->index('transport_request_id', 'appts_transport_req_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_appointments');
    }
};
