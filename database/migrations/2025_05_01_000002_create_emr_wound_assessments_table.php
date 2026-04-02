<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_wound_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wound_record_id');
            $table->unsignedBigInteger('assessed_by_user_id');
            $table->timestamp('assessed_at');

            // Re-measurement fields
            $table->decimal('length_cm', 5, 1)->nullable();
            $table->decimal('width_cm', 5, 1)->nullable();
            $table->decimal('depth_cm', 5, 1)->nullable();
            $table->string('wound_bed', 50)->nullable();
            $table->string('exudate_amount', 50)->nullable();
            $table->string('exudate_type', 50)->nullable();
            $table->string('periwound_skin', 50)->nullable();
            $table->boolean('odor')->default(false);
            $table->tinyInteger('pain_score')->nullable();
            $table->text('treatment_description')->nullable();

            // Trend tracking
            $table->string('status_change', 50)->nullable(); // improved, unchanged, deteriorated, healed

            $table->text('notes')->nullable();

            // Append-only — no updated_at
            $table->timestamp('created_at')->useCurrent();

            // Foreign keys
            $table->foreign('wound_record_id')->references('id')->on('emr_wound_records')->cascadeOnDelete();
            $table->foreign('assessed_by_user_id')->references('id')->on('shared_users')->cascadeOnDelete();

            $table->index('wound_record_id');
        });

        DB::statement("ALTER TABLE emr_wound_assessments ADD CONSTRAINT emr_wound_assessments_wound_bed_check
            CHECK (wound_bed IS NULL OR wound_bed IN
            ('granulation','slough','eschar','epithelialization','mixed','not_visible'))");

        DB::statement("ALTER TABLE emr_wound_assessments ADD CONSTRAINT emr_wound_assessments_exudate_amount_check
            CHECK (exudate_amount IS NULL OR exudate_amount IN ('none','scant','light','moderate','heavy'))");

        DB::statement("ALTER TABLE emr_wound_assessments ADD CONSTRAINT emr_wound_assessments_exudate_type_check
            CHECK (exudate_type IS NULL OR exudate_type IN ('serous','serosanguineous','sanguineous','purulent'))");

        DB::statement("ALTER TABLE emr_wound_assessments ADD CONSTRAINT emr_wound_assessments_periwound_check
            CHECK (periwound_skin IS NULL OR periwound_skin IN ('intact','macerated','erythema','callus','other'))");

        DB::statement("ALTER TABLE emr_wound_assessments ADD CONSTRAINT emr_wound_assessments_status_change_check
            CHECK (status_change IS NULL OR status_change IN ('improved','unchanged','deteriorated','healed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_wound_assessments');
    }
};
