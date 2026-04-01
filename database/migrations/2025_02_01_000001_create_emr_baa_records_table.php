<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business Associate Agreement (BAA) tracking table.
     *
     * HIPAA 45 CFR §164.308(b)(1) requires a written BAA with every Business
     * Associate that creates, receives, maintains, or transmits ePHI on behalf
     * of the Covered Entity. This table tracks BAA status, expiration dates, and
     * vendor contact details so IT Admin can monitor compliance.
     *
     * Status lifecycle: pending → active → expiring_soon → expired | terminated
     * Status is MANUALLY maintained by IT Admin (not auto-computed) except for
     * the expiring_soon logic in BaaRecord::isExpiringSoon() which is used by the
     * compliance posture widget to generate UI warnings.
     *
     * Soft deletes preserve records for audit — never hard-delete BAA history.
     */
    public function up(): void
    {
        Schema::create('emr_baa_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('vendor_name');
            $table->string('vendor_type', 50);          // CHECK constraint below
            $table->boolean('phi_accessed')->default(true);
            $table->date('baa_signed_date')->nullable(); // nullable for pending BAAs
            $table->date('baa_expiration_date')->nullable();
            $table->string('status', 30)->default('pending'); // CHECK constraint below
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("
            ALTER TABLE emr_baa_records
            ADD CONSTRAINT emr_baa_records_vendor_type_check
            CHECK (vendor_type IN (
                'cloud_provider', 'clearinghouse', 'lab', 'pharmacy',
                'ehr', 'telehealth', 'it_services', 'other'
            ))
        ");

        DB::statement("
            ALTER TABLE emr_baa_records
            ADD CONSTRAINT emr_baa_records_status_check
            CHECK (status IN (
                'active', 'expiring_soon', 'expired', 'pending', 'terminated'
            ))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_baa_records');
    }
};
