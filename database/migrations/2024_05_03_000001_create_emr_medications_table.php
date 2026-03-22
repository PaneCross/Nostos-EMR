<?php

// ─── Migration: emr_medications ───────────────────────────────────────────────
// Active medication list for PACE participants.
//
// Each row represents ONE medication order for a participant. A medication
// is "active" while status = 'active' or 'prn'. Discontinuation sets
// status = 'discontinued' and populates discontinued_reason.
//
// The eMAR (emr_emar_records) references medication_id to generate daily MAR rows.
// MedicationScheduleService::generateDailyMar() reads all active meds each
// midnight and creates scheduled emr_emar_records for the coming day.
//
// DrugInteractionService::checkInteractions() is called on store/update to
// cross-reference emr_drug_interactions_reference and populate
// emr_drug_interaction_alerts for any new interactions found.
//
// Status lifecycle:
//   active → discontinued (by clinician)
//   active → on_hold (temporary, e.g., pre-procedure)
//   prn → discontinued
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_medications', function (Blueprint $table) {
            $table->id();

            // ── Scope ─────────────────────────────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // ── Drug identity ─────────────────────────────────────────────────
            $table->string('drug_name', 200);
            $table->string('rxnorm_code', 20)->nullable();  // RxNorm concept ID for interop

            // ── Dosing ────────────────────────────────────────────────────────
            $table->decimal('dose', 8, 3)->nullable();
            $table->enum('dose_unit', [
                'mg', 'mcg', 'ml', 'units', 'tab', 'cap', 'patch', 'drop',
            ])->nullable();
            $table->enum('route', [
                'oral', 'IV', 'IM', 'subcut', 'topical', 'inhaled',
                'sublingual', 'rectal', 'nasal', 'optic', 'otic',
            ])->nullable();
            $table->enum('frequency', [
                'daily', 'BID', 'TID', 'QID', 'Q4H', 'Q6H', 'Q8H', 'Q12H',
                'PRN', 'weekly', 'monthly', 'once',
            ])->nullable();

            // ── PRN details ───────────────────────────────────────────────────
            // PRN = "as needed" — eMAR does NOT pre-schedule PRN doses.
            // is_prn drives MedicationScheduleService to skip MAR pre-generation.
            $table->boolean('is_prn')->default(false);
            $table->string('prn_indication', 300)->nullable();  // e.g., "for pain > 5/10"

            // ── Prescriber ────────────────────────────────────────────────────
            $table->foreignId('prescribing_provider_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->date('prescribed_date')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();

            // ── Discontinuation ────────────────────────────────────────────────
            $table->text('discontinued_reason')->nullable();
            $table->enum('status', [
                'active',
                'discontinued',
                'on_hold',
                'prn',               // PRN-only orders carry their own status
            ])->default('active');

            // ── Controlled substance tracking ─────────────────────────────────
            // DEA Schedule II-V. Witness required on eMAR for Schedule II/III.
            $table->boolean('is_controlled')->default(false);
            $table->enum('controlled_schedule', ['II', 'III', 'IV', 'V'])->nullable();

            // ── Pharmacy / refill ─────────────────────────────────────────────
            $table->tinyInteger('refills_remaining')->nullable();
            $table->date('last_filled_date')->nullable();
            $table->text('pharmacy_notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'status'], 'med_participant_status_idx');
            $table->index(['tenant_id', 'drug_name'],   'med_tenant_drug_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_medications');
    }
};
