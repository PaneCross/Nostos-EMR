<?php

// ─── Migration: make_requesting_user_id_nullable_on_emr_sdrs ──────────────────
// System-generated SDRs (e.g. from HL7 discharge A03) have no requesting user.
// Making requesting_user_id nullable allows ProcessHl7AdtJob to create SDRs
// without requiring a human user ID.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_sdrs', function (Blueprint $table) {
            // Drop the existing FK constraint before altering the column
            $table->dropForeign(['requesting_user_id']);
            $table->foreignId('requesting_user_id')
                ->nullable()
                ->change()
                ->constrained('shared_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emr_sdrs', function (Blueprint $table) {
            $table->dropForeign(['requesting_user_id']);
            $table->foreignId('requesting_user_id')
                ->change()
                ->constrained('shared_users')
                ->restrictOnDelete();
        });
    }
};
