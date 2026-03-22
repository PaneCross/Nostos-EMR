<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_social_determinants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('assessed_by_user_id')->nullable();
            $table->timestamp('assessed_at')->useCurrent();
            $table->string('housing_stability', 30)->default('unknown');
            $table->string('food_security', 30)->default('unknown');
            $table->string('transportation_access', 30)->default('unknown');
            $table->string('social_isolation_risk', 30)->default('unknown');
            $table->string('caregiver_strain', 30)->default('unknown');
            $table->string('financial_strain', 30)->default('unknown');
            $table->text('safety_concerns')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('participant_id')->references('id')->on('emr_participants');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('assessed_by_user_id')->references('id')->on('shared_users');

            $table->index(['participant_id', 'tenant_id']);
            $table->index('assessed_at');
        });

        $values = implode("','", ['stable', 'at_risk', 'unstable', 'homeless', 'unknown']);
        DB::statement("ALTER TABLE emr_social_determinants ADD CONSTRAINT emr_social_determinants_housing_check CHECK (housing_stability IN ('{$values}'))");
        $values2 = implode("','", ['secure', 'at_risk', 'insecure', 'unknown']);
        DB::statement("ALTER TABLE emr_social_determinants ADD CONSTRAINT emr_social_determinants_food_check CHECK (food_security IN ('{$values2}'))");
        $values3 = implode("','", ['adequate', 'limited', 'none', 'unknown']);
        DB::statement("ALTER TABLE emr_social_determinants ADD CONSTRAINT emr_social_determinants_transport_check CHECK (transportation_access IN ('{$values3}'))");
        $values4 = implode("','", ['low', 'moderate', 'high', 'unknown']);
        DB::statement("ALTER TABLE emr_social_determinants ADD CONSTRAINT emr_social_determinants_isolation_check CHECK (social_isolation_risk IN ('{$values4}'))");
        $values5 = implode("','", ['none', 'mild', 'moderate', 'severe', 'unknown']);
        DB::statement("ALTER TABLE emr_social_determinants ADD CONSTRAINT emr_social_determinants_caregiver_check CHECK (caregiver_strain IN ('{$values5}'))");
        DB::statement("ALTER TABLE emr_social_determinants ADD CONSTRAINT emr_social_determinants_financial_check CHECK (financial_strain IN ('{$values5}'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_social_determinants');
    }
};
