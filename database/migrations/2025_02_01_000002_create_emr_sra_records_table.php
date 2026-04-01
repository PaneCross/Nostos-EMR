<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Security Risk Analysis (SRA) tracking table.
     *
     * HIPAA 45 CFR §164.308(a)(1)(ii)(A) requires covered entities to conduct
     * an accurate and thorough assessment of potential risks and vulnerabilities
     * to the confidentiality, integrity, and availability of ePHI. CMS also
     * requires an annual SRA update as part of the Meaningful Use / Promoting
     * Interoperability attestation process.
     *
     * Each row represents one SRA cycle. Multiple records are expected over time;
     * IT Admin uses next_sra_due to track when the next annual analysis is required.
     * The most recent completed SRA drives the compliance posture widget.
     *
     * Soft deletes preserve records for audit — SRA history must be retained.
     */
    public function up(): void
    {
        Schema::create('emr_sra_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->date('sra_date');
            $table->string('conducted_by');              // name of person / external firm
            $table->text('scope_description');
            $table->string('risk_level', 20);            // CHECK constraint below
            $table->text('findings_summary')->nullable();
            $table->date('next_sra_due')->nullable();
            $table->string('status', 30)->default('in_progress'); // CHECK constraint below
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('tenant_id');
            $table->foreign('reviewed_by_user_id')
                  ->references('id')
                  ->on('shared_users')
                  ->nullOnDelete();
        });

        DB::statement("
            ALTER TABLE emr_sra_records
            ADD CONSTRAINT emr_sra_records_risk_level_check
            CHECK (risk_level IN ('low', 'moderate', 'high', 'critical'))
        ");

        DB::statement("
            ALTER TABLE emr_sra_records
            ADD CONSTRAINT emr_sra_records_status_check
            CHECK (status IN ('in_progress', 'completed', 'needs_update'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_sra_records');
    }
};
