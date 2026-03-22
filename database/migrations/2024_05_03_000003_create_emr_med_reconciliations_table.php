<?php

// ─── Migration: emr_med_reconciliations ───────────────────────────────────────
// Medication reconciliation records — required at care transitions.
//
// CMS PACE regulation requires medication reconciliation:
//   - At enrollment (initial comprehensive reconciliation)
//   - After any hospitalization or ER visit
//   - At each IDT meeting (ongoing review)
//
// A reconciliation captures the complete medication review at a point in time.
// reconciled_medications (JSONB) stores a snapshot of all medications reviewed,
// their reconciliation outcome, and discrepancy notes if applicable.
//
// reconciliation_type drives the workflow:
//   enrollment      — on PACE enrollment, done by primary_care
//   post_hospital   — after hospitalization/ER, triggered by SDR or care team
//   idt_review      — at each IDT meeting cycle
//   routine         — ad-hoc routine review
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_med_reconciliations', function (Blueprint $table) {
            $table->id();

            // ── Scope ─────────────────────────────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // ── Performed by ──────────────────────────────────────────────────
            $table->foreignId('reconciled_by_user_id')
                ->constrained('shared_users')
                ->cascadeOnDelete();
            $table->string('reconciling_department', 50);

            // ── Classification ─────────────────────────────────────────────────
            $table->enum('reconciliation_type', [
                'enrollment',
                'post_hospital',
                'idt_review',
                'routine',
            ]);

            // ── Reconciliation snapshot ────────────────────────────────────────
            // JSONB array: each element = {medication_id, drug_name, action:
            // continue|discontinue|modify|new, discrepancy_note (nullable)}
            $table->jsonb('reconciled_medications')->default('[]');

            $table->timestamp('reconciled_at');
            $table->text('clinical_notes')->nullable();
            $table->boolean('has_discrepancies')->default(false);

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'reconciled_at'], 'med_recon_participant_idx');
            $table->index(['tenant_id', 'reconciliation_type'], 'med_recon_tenant_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_med_reconciliations');
    }
};
