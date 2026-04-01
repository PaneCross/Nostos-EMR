<?php

// ─── Migration: add blood_glucose_timing to emr_vitals (W4-4) ────────────────
// QW-02: Blood glucose already exists as 'blood_glucose' (smallint, mg/dL).
// This migration adds the timing context (fasting, post_meal_2h, etc.) needed
// to clinically interpret the value. Without timing, a reading of 140 mg/dL
// could be normal (post-meal) or pre-diabetic (fasting).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_vitals', function (Blueprint $table) {
            $table->string('blood_glucose_timing', 20)->nullable()->after('blood_glucose');
        });

        DB::statement("ALTER TABLE emr_vitals ADD CONSTRAINT emr_vitals_blood_glucose_timing_check
            CHECK (blood_glucose_timing IS NULL OR blood_glucose_timing IN (
                'fasting', 'post_meal_2h', 'random', 'pre_meal'
            ))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_vitals DROP CONSTRAINT IF EXISTS emr_vitals_blood_glucose_timing_check');
        Schema::table('emr_vitals', function (Blueprint $table) {
            $table->dropColumn('blood_glucose_timing');
        });
    }
};
