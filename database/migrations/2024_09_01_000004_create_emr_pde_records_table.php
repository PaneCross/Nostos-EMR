<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_pde_records', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('participant_id');
            $table->foreign('participant_id')->references('id')->on('emr_participants');

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');

            // Link to dispensed medication (nullable — can be entered manually without an eMAR record)
            $table->unsignedBigInteger('medication_id')->nullable();
            $table->foreign('medication_id')->references('id')->on('emr_medications');

            $table->string('drug_name');
            $table->string('ndc_code', 13)->nullable(); // National Drug Code — 11-digit (XXXXXXXXXXX) or dashed (XXXXX-XXXX-XX)

            $table->date('dispense_date');
            $table->unsignedSmallInteger('days_supply');
            $table->decimal('quantity_dispensed', 8, 3);

            // Cost components per CMS PDE data element specifications
            $table->decimal('ingredient_cost', 10, 2)->default(0.00);
            $table->decimal('dispensing_fee', 8, 2)->default(0.00);
            $table->decimal('patient_pay', 8, 2)->default(0.00);

            // True Out-of-Pocket (TrOOP) accumulated for this dispensing event
            // Catastrophic threshold for 2025: $7,400
            $table->decimal('troop_amount', 10, 2)->default(0.00);

            $table->string('pharmacy_npi', 10)->nullable();
            $table->string('prescriber_npi', 10)->nullable();

            // PDE submission lifecycle: pending, submitted, accepted, rejected
            $table->string('submission_status', 20)->default('pending');

            // CMS-assigned PDE identifier (returned in MARx response)
            $table->string('pde_id', 100)->nullable();

            $table->timestamps();

            $table->index(['participant_id', 'tenant_id', 'dispense_date']);
            $table->index(['tenant_id', 'submission_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_pde_records');
    }
};
