<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_capitation_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('participant_id');

            // CMS monthly capitation: stored as YYYY-MM for easy monthly aggregation.
            // Unique per participant per month (one capitation line per period).
            $table->char('month_year', 7); // e.g. '2026-03'

            // Component rates stored separately for audit/reconciliation.
            $table->decimal('medicare_a_rate',  10, 2)->default(0);
            $table->decimal('medicare_b_rate',  10, 2)->default(0);
            $table->decimal('medicare_d_rate',  10, 2)->default(0);
            $table->decimal('medicaid_rate',    10, 2)->default(0);
            $table->decimal('total_capitation', 10, 2)->default(0);

            // CMS eligibility category drives which rate applies (PACE special needs).
            $table->string('eligibility_category', 100)->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('participant_id')->references('id')->on('emr_participants')->onDelete('cascade');

            // One record per participant per month
            $table->unique(['participant_id', 'month_year']);

            $table->index(['tenant_id', 'month_year']);
            $table->index(['participant_id', 'month_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_capitation_records');
    }
};
