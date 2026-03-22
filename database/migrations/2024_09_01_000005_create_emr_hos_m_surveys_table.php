<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_hos_m_surveys', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('participant_id');
            $table->foreign('participant_id')->references('id')->on('emr_participants');

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');

            // HOS-M is administered once per calendar year per participant
            $table->unsignedSmallInteger('survey_year');

            $table->unsignedBigInteger('administered_by_user_id');
            $table->foreign('administered_by_user_id')->references('id')->on('shared_users');

            $table->timestamp('administered_at');
            $table->boolean('completed')->default(false);

            // JSONB responses: {"physical_health": 1-5, "mental_health": 1-5,
            //   "pain": 1-5, "falls_past_year": 0|1, "fall_injuries": 0|1}
            $table->jsonb('responses')->nullable()->default('{}');

            // CMS submission tracking
            $table->boolean('submitted_to_cms')->default(false);
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            // One survey per participant per year (CMS requirement)
            $table->unique(['participant_id', 'survey_year']);
            $table->index(['tenant_id', 'survey_year', 'submitted_to_cms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_hos_m_surveys');
    }
};
