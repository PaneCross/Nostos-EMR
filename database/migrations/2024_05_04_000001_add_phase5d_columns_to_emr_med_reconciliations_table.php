<?php

// ─── Migration: Phase 5D columns on emr_med_reconciliations ───────────────────
// Extends the Phase 5C reconciliation table to support the full 5-step
// medication reconciliation workflow introduced in Phase 5D.
//
// New columns:
//   prior_source          — Where the prior medication list came from
//   prior_medications     — JSONB: meds entered by clinician in Step 2
//   changes_made          — JSONB: audit trail of decisions applied in Step 4
//   approved_by_user_id   — Provider who approved and locked the record
//   approved_at           — Timestamp of provider approval
//   status                — Workflow state: in_progress → decisions_made → approved
//
// The 'status' column replaces the implicit meaning of reconciled_at being set;
// 'approved' is the terminal state and makes the record immutable.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_med_reconciliations', function (Blueprint $table) {
            // ── Workflow state ─────────────────────────────────────────────────
            // in_progress: wizard open, prior meds being entered
            // decisions_made: clinician applied all decisions, awaiting provider sign-off
            // approved: provider locked the record — immutable
            $table->string('status', 30)->default('in_progress')->after('has_discrepancies');

            // ── Prior medication source ────────────────────────────────────────
            // Where the external medication list came from (Step 1 of wizard)
            $table->string('prior_source', 50)->nullable()->after('status');

            // ── Prior medication list ──────────────────────────────────────────
            // Medications entered by the clinician in Step 2 (from external source).
            // Schema per entry: {drug_name, dose, dose_unit, frequency, route,
            //                    prescriber (freetext), notes (nullable)}
            $table->jsonb('prior_medications')->default('[]')->after('prior_source');

            // ── Decisions audit trail ─────────────────────────────────────────
            // JSONB record of every decision applied in Step 4. Populated by
            // MedReconciliationService::applyDecisions().
            // Schema per entry: {drug_name, action, notes, medication_id (nullable)}
            $table->jsonb('changes_made')->nullable()->after('prior_medications');

            // ── Provider approval ─────────────────────────────────────────────
            // Approval is a distinct step from performing the reconciliation
            // (reconciled_by_user_id). In PACE, the prescribing provider must
            // formally sign off on the resulting medication list.
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete()
                ->after('changes_made');

            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');

        });

        // PostgreSQL CHECK constraint for status enum values
        DB::statement("ALTER TABLE emr_med_reconciliations ADD CONSTRAINT med_recon_status_check CHECK (status IN ('in_progress', 'decisions_made', 'approved'))");

        // Only one active (non-approved) reconciliation allowed per participant at a time.
        // Enforced in MedReconciliationService::startReconciliation() rather than DB constraint
        // because partial uniqueness on nullable status is complex across databases.
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_med_reconciliations DROP CONSTRAINT IF EXISTS med_recon_status_check');
        Schema::table('emr_med_reconciliations', function (Blueprint $table) {
            $table->dropForeign(['approved_by_user_id']);
            $table->dropColumn([
                'status', 'prior_source', 'prior_medications',
                'changes_made', 'approved_by_user_id', 'approved_at',
            ]);
        });
    }
};
