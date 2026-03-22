<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_hpms_submissions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');

            // enrollment, disenrollment, quality_data, hos_m
            $table->string('submission_type', 30);

            // Generated flat-file content (pipe-delimited or fixed-width per CMS spec)
            // Never returned in API responses — downloads served through HpmsController
            $table->longText('file_content')->nullable();

            $table->unsignedInteger('record_count')->default(0);

            // Reporting period
            $table->date('period_start');
            $table->date('period_end');

            // draft, submitted, confirmed
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();

            $table->unsignedBigInteger('created_by_user_id');
            $table->foreign('created_by_user_id')->references('id')->on('shared_users');

            $table->timestamps();

            $table->index(['tenant_id', 'submission_type', 'period_start']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_hpms_submissions');
    }
};
