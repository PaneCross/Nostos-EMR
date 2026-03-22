<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_procedures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('performed_by_user_id')->nullable();
            $table->string('procedure_name', 300);
            $table->string('cpt_code', 10)->nullable();      // HCPCS/CPT for billing
            $table->string('snomed_code', 20)->nullable();   // SNOMED CT for FHIR
            $table->date('performed_date');
            $table->string('facility', 200)->nullable();
            $table->string('body_site', 100)->nullable();
            $table->string('outcome', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 30)->default('internal');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('participant_id')->references('id')->on('emr_participants');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('performed_by_user_id')->references('id')->on('shared_users');

            $table->index(['participant_id', 'tenant_id']);
            $table->index('performed_date');
        });

        DB::statement("ALTER TABLE emr_procedures ADD CONSTRAINT emr_procedures_source_check CHECK (source IN ('internal','external_report','patient_reported'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_procedures');
    }
};
