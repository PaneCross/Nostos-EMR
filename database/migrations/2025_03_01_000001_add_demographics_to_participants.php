<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Migration 85 — Participant Demographics Expansion (W4-3) ─────────────────
// Adds race/ethnicity (OMB two-question format, required for HEDIS + CMS encounter
// data), marital status, legal representative linking, religion, veteran status,
// and education level to emr_participants.
//
// Race and ethnicity use string columns + CHECK constraints (PostgreSQL pattern
// used throughout this codebase) rather than ENUM types, for easy extensibility.
// All fields nullable — participants may decline any demographic question.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_participants', function (Blueprint $table) {
            // Part A: Race & Ethnicity (OMB two-question format, GAP-07 / QW-03)
            $table->string('race')->nullable()->after('primary_language');
            $table->string('ethnicity')->nullable()->after('race');
            $table->string('race_detail', 255)->nullable()->after('ethnicity'); // patient-supplied granular self-ID

            // Part B: Marital Status + Legal Representative
            $table->string('marital_status')->nullable()->after('race_detail');
            $table->string('legal_representative_type')->nullable()->after('marital_status');
            $table->unsignedBigInteger('legal_representative_contact_id')->nullable()->after('legal_representative_type');
            $table->foreign('legal_representative_contact_id')
                ->references('id')
                ->on('emr_participant_contacts')
                ->nullOnDelete();

            // Part C: Additional SDOH Demographics
            $table->string('religion', 100)->nullable()->after('legal_representative_contact_id');
            $table->string('veteran_status')->nullable()->after('religion');
            $table->string('education_level')->nullable()->after('veteran_status');
        });

        // PostgreSQL CHECK constraints for enum-like string columns
        DB::statement("ALTER TABLE emr_participants ADD CONSTRAINT emr_participants_race_check
            CHECK (race IN (
                'white','black_african_american','asian','american_indian_alaska_native',
                'native_hawaiian_pacific_islander','multiracial','other','unknown','declined'
            ) OR race IS NULL)");

        DB::statement("ALTER TABLE emr_participants ADD CONSTRAINT emr_participants_ethnicity_check
            CHECK (ethnicity IN (
                'hispanic_latino','not_hispanic_latino','unknown','declined'
            ) OR ethnicity IS NULL)");

        DB::statement("ALTER TABLE emr_participants ADD CONSTRAINT emr_participants_marital_status_check
            CHECK (marital_status IN (
                'single','married','domestic_partner','divorced','widowed','separated','unknown'
            ) OR marital_status IS NULL)");

        DB::statement("ALTER TABLE emr_participants ADD CONSTRAINT emr_participants_legal_rep_type_check
            CHECK (legal_representative_type IN (
                'self','legal_guardian','durable_poa','healthcare_proxy','court_appointed','other'
            ) OR legal_representative_type IS NULL)");

        DB::statement("ALTER TABLE emr_participants ADD CONSTRAINT emr_participants_veteran_status_check
            CHECK (veteran_status IN (
                'not_veteran','veteran_active','veteran_inactive','unknown'
            ) OR veteran_status IS NULL)");

        DB::statement("ALTER TABLE emr_participants ADD CONSTRAINT emr_participants_education_level_check
            CHECK (education_level IN (
                'less_than_high_school','high_school_ged','some_college',
                'associates','bachelors','graduate','unknown'
            ) OR education_level IS NULL)");
    }

    public function down(): void
    {
        Schema::table('emr_participants', function (Blueprint $table) {
            $table->dropForeign(['legal_representative_contact_id']);
            $table->dropColumn([
                'race', 'ethnicity', 'race_detail',
                'marital_status', 'legal_representative_type', 'legal_representative_contact_id',
                'religion', 'veteran_status', 'education_level',
            ]);
        });
    }
};
