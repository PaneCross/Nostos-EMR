<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_participants', function (Blueprint $table) {
            $table->string('advance_directive_status', 40)->nullable()->after('is_active');
            $table->string('advance_directive_type', 40)->nullable()->after('advance_directive_status');
            $table->date('advance_directive_reviewed_at')->nullable()->after('advance_directive_type');
            $table->unsignedBigInteger('advance_directive_reviewed_by_user_id')->nullable()->after('advance_directive_reviewed_at');
            $table->foreign('advance_directive_reviewed_by_user_id')->references('id')->on('shared_users');
        });

        DB::statement("ALTER TABLE emr_participants ADD CONSTRAINT emr_participants_advance_directive_status_check
            CHECK (advance_directive_status IS NULL OR advance_directive_status IN (
                'has_directive','declined_directive','incapacitated_no_directive','unknown'
            ))");
        DB::statement("ALTER TABLE emr_participants ADD CONSTRAINT emr_participants_advance_directive_type_check
            CHECK (advance_directive_type IS NULL OR advance_directive_type IN (
                'dnr','polst','living_will','healthcare_proxy','combined'
            ))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_participants DROP CONSTRAINT IF EXISTS emr_participants_advance_directive_status_check');
        DB::statement('ALTER TABLE emr_participants DROP CONSTRAINT IF EXISTS emr_participants_advance_directive_type_check');
        Schema::table('emr_participants', function (Blueprint $table) {
            $table->dropForeign(['advance_directive_reviewed_by_user_id']);
            $table->dropColumn([
                'advance_directive_status',
                'advance_directive_type',
                'advance_directive_reviewed_at',
                'advance_directive_reviewed_by_user_id',
            ]);
        });
    }
};
