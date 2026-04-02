<?php

// ─── Migration: add_hpms_fields_to_emr_participants ────────────────────────
// Adds CMS HPMS enrollment file fields missing from the participant record.
//
// New columns:
//   medicare_a_start_date — Medicare Part A effective date (HPMS Field 3).
//                           Required for accurate CMS enrollment file submission.
//   medicare_b_start_date — Medicare Part B effective date (HPMS Field 4).
//                           Required for accurate CMS enrollment file submission.
//   county_fips_code      — 5-digit county FIPS code (HPMS Field 11).
//                           Used for capitation rate lookup (county-specific CMS rates)
//                           and HPMS enrollment file submission.
//
// All columns are nullable — pre-existing participants will not have these values
// until data migration or next enrollment intake.
//
// W4-9 — GAP-14: HPMS enrollment file CMS field verification.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_participants', function (Blueprint $table) {
            // HPMS Field 3: Medicare Part A effective date
            $table->date('medicare_a_start_date')
                ->nullable()
                ->after('medicare_id');

            // HPMS Field 4: Medicare Part B effective date
            $table->date('medicare_b_start_date')
                ->nullable()
                ->after('medicare_a_start_date');

            // HPMS Field 11: County FIPS code (5-digit, e.g. '39049' = Franklin County OH)
            // Also used in emr_participant_risk_scores for capitation rate lookup.
            $table->char('county_fips_code', 5)
                ->nullable()
                ->after('medicaid_id');
        });
    }

    public function down(): void
    {
        Schema::table('emr_participants', function (Blueprint $table) {
            $table->dropColumn(['medicare_a_start_date', 'medicare_b_start_date', 'county_fips_code']);
        });
    }
};
