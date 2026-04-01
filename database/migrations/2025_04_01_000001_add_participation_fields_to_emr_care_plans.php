<?php

// ─── Migration 89 ──────────────────────────────────────────────────────────────
// Adds 42 CFR §460.104(d) participant acknowledgment fields to emr_care_plans.
//
// These fields record whether the participant (or their legal representative)
// was offered the opportunity to participate in care plan development and
// what their response was. CMS surveys consistently cite this as a deficiency.
//
// participant_offered_participation — boolean, tracks whether offer was made
// participant_response — enum CHECK constraint: accepted | declined | no_response
// offered_at — timestamp when the offer was made
// offered_by_user_id — FK to shared_users (IDT clinician who made the offer)
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_care_plans', function (Blueprint $table) {
            $table->boolean('participant_offered_participation')->default(false)->after('overall_goals_text');
            $table->string('participant_response', 20)->nullable()->after('participant_offered_participation');
            $table->timestamp('offered_at')->nullable()->after('participant_response');
            $table->foreignId('offered_by_user_id')->nullable()->after('offered_at')
                ->constrained('shared_users')->nullOnDelete();
        });

        // PostgreSQL CHECK constraint — Blueprint::check() does not exist in this version.
        DB::statement("
            ALTER TABLE emr_care_plans
            ADD CONSTRAINT emr_care_plans_participant_response_check
            CHECK (participant_response IS NULL OR participant_response IN ('accepted', 'declined', 'no_response'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_care_plans DROP CONSTRAINT IF EXISTS emr_care_plans_participant_response_check');

        Schema::table('emr_care_plans', function (Blueprint $table) {
            $table->dropForeign(['offered_by_user_id']);
            $table->dropColumn(['participant_offered_participation', 'participant_response', 'offered_at', 'offered_by_user_id']);
        });
    }
};
