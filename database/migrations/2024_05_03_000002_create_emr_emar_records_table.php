<?php

// ─── Migration: emr_emar_records ──────────────────────────────────────────────
// Electronic Medication Administration Record (eMAR).
// APPEND-ONLY — no soft deletes. Each row is one scheduled or PRN dose event.
//
// Row lifecycle:
//   MedicationScheduleService creates a row at midnight with status='scheduled'
//   and scheduled_time = the dose window for that day.
//   A nurse opens the eMAR grid and marks each dose:
//     given         — administered; administered_at and administered_by filled
//     refused       — participant declined; reason_not_given required
//     held          — held per MD order; reason_not_given required
//     not_available — drug not in stock; reason_not_given required
//     late          — scheduled window passed; set by LateMarDetectionJob
//     missed        — nurse marked missed after window closed
//
// Controlled substance administrations require witness_user_id (enforced in
// MedicationController::recordAdministration()).
//
// PRN doses: created on-demand (not pre-scheduled) via a separate endpoint
// POST /participants/{id}/medications/{med}/prn-dose.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_emar_records', function (Blueprint $table) {
            $table->id();

            // ── Scope ─────────────────────────────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('medication_id')
                ->constrained('emr_medications')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // ── Timing ────────────────────────────────────────────────────────
            $table->timestamp('scheduled_time');             // When the dose was due
            $table->timestamp('administered_at')->nullable(); // When actually given

            // ── Administration ────────────────────────────────────────────────
            $table->foreignId('administered_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();

            $table->enum('status', [
                'scheduled',      // Pre-generated, not yet administered
                'given',          // Successfully administered
                'refused',        // Participant declined
                'held',           // Held per order / clinical decision
                'not_available',  // Drug not available in facility
                'late',           // Scheduled window passed (set by LateMarDetectionJob)
                'missed',         // Nurse marked missed after window closed
            ])->default('scheduled');

            // Actual dose/route may differ from ordered (e.g., dose adjustment)
            $table->string('dose_given', 50)->nullable();
            $table->string('route_given', 50)->nullable();

            $table->text('reason_not_given')->nullable();  // Required for refused/held/not_available

            // Witness required for DEA Schedule II/III controlled substances
            $table->foreignId('witness_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            // Append-only: use useCurrent for created_at, omit updated_at
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'scheduled_time'], 'emar_participant_time_idx');
            $table->index(['medication_id', 'scheduled_time'],  'emar_medication_time_idx');
            $table->index(['tenant_id', 'status'],              'emar_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_emar_records');
    }
};
