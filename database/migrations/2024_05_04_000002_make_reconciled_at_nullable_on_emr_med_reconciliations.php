<?php

// ─── Migration: make reconciled_at nullable ────────────────────────────────────
// The Phase 5C migration set reconciled_at as NOT NULL, but with the Phase 5D
// workflow the record starts in 'in_progress' state and reconciled_at is only
// populated when providerApproval() locks the record (Step 5). Making it nullable
// is the correct semantic — a reconciliation that hasn't been approved yet has no
// reconciled_at value.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_med_reconciliations', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Fill nulls with created_at before reverting to NOT NULL
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE emr_med_reconciliations SET reconciled_at = created_at WHERE reconciled_at IS NULL'
        );
        Schema::table('emr_med_reconciliations', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable(false)->change();
        });
    }
};
