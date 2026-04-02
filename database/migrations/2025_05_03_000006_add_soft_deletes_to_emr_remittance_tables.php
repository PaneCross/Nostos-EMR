<?php

// Migration 106 — Add soft delete support to W5-3 remittance tables
//
// The RemittanceBatch and RemittanceClaim models use SoftDeletes but the
// original W5-3 migrations (102-105) were missing the deleted_at columns.
// All EMR models use soft deletes per the HIPAA append-only audit requirement.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RemittanceBatch uses SoftDeletes trait.
        // The original W5-3 migration (102) omitted the deleted_at column.
        // DenialRecord already has softDeletes in its create migration (104).
        Schema::table('emr_remittance_batches', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('emr_remittance_batches', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
