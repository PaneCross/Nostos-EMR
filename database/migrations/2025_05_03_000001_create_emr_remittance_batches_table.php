<?php

// Migration 102 — emr_remittance_batches
//
// Stores inbound X12 835 ERA (Electronic Remittance Advice) batches.
// Each batch represents one payment from a payer (CMS, Medicaid MCO, etc.)
// with a corresponding set of claim adjudication records.
//
// The raw EDI 835 content is stored for audit trail per HIPAA 45 CFR §164.312(b).
// Status lifecycle: received → processing → processed → posted | error

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_remittance_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('check_eft_number')->nullable();
            $table->string('payer_name')->nullable();      // populated by parser job after upload
            $table->string('payer_id')->nullable();
            $table->text('edi_835_content'); // raw X12 content for audit
            $table->date('payment_date')->nullable();      // populated by parser job after upload
            $table->decimal('payment_amount', 12, 2)->nullable(); // populated by parser job after upload
            $table->date('check_issue_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('status')->default('received');
            $table->string('source')->default('manual_upload');
            $table->integer('claim_count')->default(0);
            $table->integer('paid_count')->default(0);
            $table->integer('denied_count')->default(0);
            $table->integer('adjustment_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->foreign('created_by_user_id')->references('id')->on('shared_users');
            $table->timestamps();
        });

        // PostgreSQL CHECK constraints for controlled vocabulary enforcement
        DB::statement("ALTER TABLE emr_remittance_batches ADD CONSTRAINT emr_remittance_batches_payment_method_check CHECK (payment_method IN ('check', 'eft', 'virtual_card', 'other'))");
        DB::statement("ALTER TABLE emr_remittance_batches ADD CONSTRAINT emr_remittance_batches_status_check CHECK (status IN ('received', 'processing', 'processed', 'posted', 'error'))");
        DB::statement("ALTER TABLE emr_remittance_batches ADD CONSTRAINT emr_remittance_batches_source_check CHECK (source IN ('manual_upload', 'clearinghouse_auto'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_remittance_batches');
    }
};
