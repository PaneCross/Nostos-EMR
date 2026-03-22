<?php

// ─── Migration: create_emr_integration_log_table ──────────────────────────────
// Records every inbound/outbound integration message for auditability.
// Used by IT Admin panel (integration health monitoring) and retry logic.
// Append-only: no updated_at (updated_at = null); status managed via updates.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_integration_log', function (Blueprint $table) {
            $table->id();
            // Tenant scoping — required on all EMR tables
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->onDelete('cascade');

            // Which external system sent/received this message
            $table->enum('connector_type', ['hl7_adt', 'lab_results', 'pharmacy_ncpdp', 'other']);

            // inbound = received by NostosEMR, outbound = sent by NostosEMR
            $table->enum('direction', ['inbound', 'outbound'])->default('inbound');

            // Raw payload stored for replay/debug; JSONB for indexable fields
            $table->jsonb('raw_payload');

            // Timestamp when the job finished processing (null = still pending)
            $table->timestamp('processed_at')->nullable();

            // pending = queued but not yet run
            // processed = job ran successfully
            // failed = job threw an exception (see error_message)
            // retried = was failed, manually re-dispatched
            $table->enum('status', ['pending', 'processed', 'failed', 'retried'])->default('pending');

            $table->text('error_message')->nullable();

            // Number of retry attempts so far (incremented by RetryFailedIntegration action)
            $table->tinyInteger('retry_count')->default(0)->unsigned();

            // Append-only — no updated_at
            $table->timestamp('created_at')->useCurrent();

            // Indexes for IT Admin log viewer filters
            $table->index(['tenant_id', 'connector_type']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_integration_log');
    }
};
