<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants');
            $table->foreignId('site_id')->constrained('shared_sites');

            // Identity
            $table->string('mrn', 20)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('preferred_name', 100)->nullable();
            $table->date('dob');
            $table->string('gender', 20)->nullable();
            $table->string('pronouns', 30)->nullable();
            $table->string('ssn_last_four', 4)->nullable();

            // Insurance IDs
            $table->string('medicare_id', 20)->nullable();
            $table->string('medicaid_id', 20)->nullable();
            $table->string('pace_contract_id', 20)->nullable();
            $table->string('h_number', 20)->nullable();

            // Language
            $table->string('primary_language', 50)->default('English');
            $table->boolean('interpreter_needed')->default(false);
            $table->string('interpreter_language', 50)->nullable();

            // Enrollment
            $table->enum('enrollment_status', [
                'referred', 'intake', 'pending', 'enrolled', 'disenrolled', 'deceased',
            ])->default('referred');
            $table->date('enrollment_date')->nullable();
            $table->date('disenrollment_date')->nullable();
            $table->string('disenrollment_reason')->nullable();

            // NF Eligibility
            $table->boolean('nursing_facility_eligible')->default(false);
            $table->date('nf_certification_date')->nullable();

            // Photo
            $table->string('photo_path')->nullable();

            // Meta
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            // Indexes
            $table->index(['tenant_id', 'enrollment_status']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
            $table->index(['tenant_id', 'is_active']);
            $table->index('mrn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_participants');
    }
};
