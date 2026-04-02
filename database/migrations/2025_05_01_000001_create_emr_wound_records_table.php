<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_wound_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id');

            // Wound classification
            $table->string('wound_type', 50);
            $table->string('location', 255); // anatomical location, e.g. 'Right heel', 'Sacrum'
            $table->string('pressure_injury_stage', 50)->nullable(); // only for pressure injuries

            // Measurements (cm)
            $table->decimal('length_cm', 5, 1)->nullable();
            $table->decimal('width_cm', 5, 1)->nullable();
            $table->decimal('depth_cm', 5, 1)->nullable();

            // Wound bed characteristics
            $table->string('wound_bed', 50)->nullable();
            $table->string('exudate_amount', 50)->nullable();
            $table->string('exudate_type', 50)->nullable();
            $table->string('periwound_skin', 50)->nullable();
            $table->boolean('odor')->default(false);
            $table->tinyInteger('pain_score')->nullable(); // 0-10

            // Treatment plan
            $table->text('treatment_description')->nullable();
            $table->string('dressing_type', 255)->nullable();
            $table->string('dressing_change_frequency', 100)->nullable();
            $table->string('goal', 50)->nullable(); // healing, maintenance, palliative

            // Status tracking
            $table->string('status', 50)->default('open');
            $table->date('first_identified_date');
            $table->date('healed_date')->nullable();

            // Metadata
            $table->unsignedBigInteger('documented_by_user_id');
            $table->boolean('photo_taken')->default(false); // flag only, no image storage in MVP
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Foreign keys
            $table->foreign('participant_id')->references('id')->on('emr_participants')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('shared_sites')->cascadeOnDelete();
            $table->foreign('documented_by_user_id')->references('id')->on('shared_users')->cascadeOnDelete();

            // Query optimization indexes
            $table->index(['participant_id', 'status']);
            $table->index(['tenant_id', 'wound_type', 'status']);
        });

        // CHECK constraints for enum-like columns
        DB::statement("ALTER TABLE emr_wound_records ADD CONSTRAINT emr_wound_records_type_check
            CHECK (wound_type IN ('pressure_injury','diabetic_foot_ulcer','venous_ulcer',
            'arterial_ulcer','surgical_wound','traumatic_wound','moisture_associated','other'))");

        DB::statement("ALTER TABLE emr_wound_records ADD CONSTRAINT emr_wound_records_stage_check
            CHECK (pressure_injury_stage IS NULL OR pressure_injury_stage IN
            ('stage_1','stage_2','stage_3','stage_4','unstageable','deep_tissue_injury'))");

        DB::statement("ALTER TABLE emr_wound_records ADD CONSTRAINT emr_wound_records_wound_bed_check
            CHECK (wound_bed IS NULL OR wound_bed IN
            ('granulation','slough','eschar','epithelialization','mixed','not_visible'))");

        DB::statement("ALTER TABLE emr_wound_records ADD CONSTRAINT emr_wound_records_exudate_amount_check
            CHECK (exudate_amount IS NULL OR exudate_amount IN ('none','scant','light','moderate','heavy'))");

        DB::statement("ALTER TABLE emr_wound_records ADD CONSTRAINT emr_wound_records_exudate_type_check
            CHECK (exudate_type IS NULL OR exudate_type IN ('serous','serosanguineous','sanguineous','purulent'))");

        DB::statement("ALTER TABLE emr_wound_records ADD CONSTRAINT emr_wound_records_periwound_check
            CHECK (periwound_skin IS NULL OR periwound_skin IN ('intact','macerated','erythema','callus','other'))");

        DB::statement("ALTER TABLE emr_wound_records ADD CONSTRAINT emr_wound_records_goal_check
            CHECK (goal IS NULL OR goal IN ('healing','maintenance','palliative'))");

        DB::statement("ALTER TABLE emr_wound_records ADD CONSTRAINT emr_wound_records_status_check
            CHECK (status IN ('open','healing','healed','deteriorating','stable'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_wound_records');
    }
};
