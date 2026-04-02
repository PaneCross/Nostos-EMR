<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── emr_lab_result_components ───────────────────────────────────────────────
// Individual analyte results within a lab panel. Maps to HL7 OBX segments.
// A single lab result (emr_lab_results) may have many components — e.g., a CBC
// panel includes WBC, RBC, Hgb, Hct, MCV, MCH, MCHC, Platelets, etc.
//
// The abnormal_flag enum mirrors HL7 OBX-8 interpretation codes.
// No SoftDeletes: components are immutable once stored (never edited).
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_lab_result_components', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('lab_result_id');

            $table->string('component_name', 200);
            $table->string('component_code', 50)->nullable();   // LOINC or local code

            // Value stored as string to accommodate both numeric ("12.5") and text ("Positive")
            $table->string('value', 100);

            $table->string('unit', 50)->nullable();
            $table->string('reference_range', 100)->nullable();

            // HL7 OBX-8 interpretation codes
            $table->string('abnormal_flag', 30)->nullable();
            // Valid: normal, low, high, critical_low, critical_high, abnormal

            $table->timestamps();

            // ── Constraints ────────────────────────────────────────────────────
            $table->foreign('lab_result_id')->references('id')->on('emr_lab_results')->cascadeOnDelete();

            $table->index('lab_result_id');
        });

        DB::statement("ALTER TABLE emr_lab_result_components ADD CONSTRAINT emr_lab_result_components_flag_check CHECK (abnormal_flag IS NULL OR abnormal_flag IN ('normal','low','high','critical_low','critical_high','abnormal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_lab_result_components');
    }
};
