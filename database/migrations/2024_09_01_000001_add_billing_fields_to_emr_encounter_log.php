<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_encounter_log', function (Blueprint $table) {
            // 837P billing provider NPI fields
            $table->string('billing_provider_npi', 10)->nullable()->after('procedure_code');
            $table->string('rendering_provider_npi', 10)->nullable()->after('billing_provider_npi');
            $table->string('service_facility_npi', 10)->nullable()->after('rendering_provider_npi');

            // Diagnosis codes as JSONB array of ICD-10 strings e.g. ["E11.9","I50.9"]
            $table->jsonb('diagnosis_codes')->nullable()->default('[]')->after('service_facility_npi');

            // Procedure modifier (e.g. GT for telehealth)
            $table->string('procedure_modifier', 10)->nullable()->after('diagnosis_codes');

            // CMS Place of Service code (2-digit)
            $table->string('place_of_service_code', 2)->nullable()->after('procedure_modifier');

            // Service units (e.g. 1.00 visit, 15-minute therapy increments)
            $table->decimal('units', 8, 2)->default(1.00)->after('place_of_service_code');

            // Charge amount submitted on the claim
            $table->decimal('charge_amount', 10, 2)->default(0.00)->after('units');

            // Claim type: internal_capitated (PACE center), external_claim (837P EDR), chart_review_crr
            $table->string('claim_type', 30)->default('internal_capitated')->after('charge_amount');

            // EDI submission lifecycle
            $table->string('submission_status', 20)->default('pending')->after('claim_type');
            $table->timestamp('submitted_at')->nullable()->after('submission_status');

            // FK to emr_edi_batches (set when encounter is batched into an EDI file)
            $table->unsignedBigInteger('edi_batch_id')->nullable()->after('submitted_at');

            // CMS 277CA acknowledgement fields
            $table->string('cms_acknowledgement_status', 20)->nullable()->after('edi_batch_id');
            $table->text('rejection_reason')->nullable()->after('cms_acknowledgement_status');

            // Index for submission queue queries
            $table->index(['tenant_id', 'submission_status', 'service_date'], 'enc_tenant_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_encounter_log', function (Blueprint $table) {
            $table->dropIndex('enc_tenant_status_date_idx');
            $table->dropColumn([
                'billing_provider_npi',
                'rendering_provider_npi',
                'service_facility_npi',
                'diagnosis_codes',
                'procedure_modifier',
                'place_of_service_code',
                'units',
                'charge_amount',
                'claim_type',
                'submission_status',
                'submitted_at',
                'edi_batch_id',
                'cms_acknowledgement_status',
                'rejection_reason',
            ]);
        });
    }
};
