<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_edi_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');

            // edr = Encounter Data Record, crr = Chart Review Record, pde = Part D PDE
            $table->string('batch_type', 10)->default('edr');

            $table->string('file_name')->nullable();

            // Full X12 5010A1 EDI file content — never returned in API responses
            $table->longText('file_content')->nullable();

            $table->unsignedInteger('record_count')->default(0);
            $table->decimal('total_charge_amount', 12, 2)->default(0.00);

            // draft, submitted, acknowledged, partially_accepted, rejected
            $table->string('status', 30)->default('draft');

            $table->timestamp('submitted_at')->nullable();

            // direct = CSSC direct submission, clearinghouse = via Availity/Change Healthcare
            $table->string('submission_method', 20)->nullable();
            $table->string('clearinghouse_reference', 100)->nullable();
            $table->string('cms_response_code', 20)->nullable();

            $table->unsignedBigInteger('created_by_user_id');
            $table->foreign('created_by_user_id')->references('id')->on('shared_users');

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'batch_type', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_edi_batches');
    }
};
