<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_immunizations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('vaccine_type', 50);
            $table->string('vaccine_name', 200);
            $table->string('cvx_code', 10)->nullable();           // CDC CVX code for FHIR mapping
            $table->date('administered_date');
            $table->unsignedBigInteger('administered_by_user_id')->nullable();
            $table->string('administered_at_location', 200)->nullable();
            $table->string('lot_number', 50)->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->unsignedTinyInteger('dose_number')->nullable();
            $table->date('next_dose_due')->nullable();
            $table->boolean('refused')->default(false);
            $table->text('refusal_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('participant_id')->references('id')->on('emr_participants');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('administered_by_user_id')->references('id')->on('shared_users');

            $table->index(['participant_id', 'tenant_id']);
            $table->index('administered_date');
            $table->index('next_dose_due');
        });

        DB::statement("ALTER TABLE emr_immunizations ADD CONSTRAINT emr_immunizations_vaccine_type_check CHECK (vaccine_type IN (
            'influenza','pneumococcal_ppsv23','pneumococcal_pcv15','pneumococcal_pcv20',
            'covid_19','tdap','shingles','hepatitis_b','other'
        ))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_immunizations');
    }
};
