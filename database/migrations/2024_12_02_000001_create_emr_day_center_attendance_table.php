<?php

// ─── Migration: emr_day_center_attendance ─────────────────────────────────────
// Tracks PACE participant attendance at the day center.
// Each record represents one participant for one day at one site.
// Status: present | absent | late | excused
// Used by: Scheduling/DayCenter.tsx, DayCenterController
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_day_center_attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('site_id');
            $table->date('attendance_date');
            $table->string('status', 20)->default('present');   // present|absent|late|excused
            $table->time('check_in_time')->nullable();           // NULL if absent
            $table->time('check_out_time')->nullable();          // NULL if still present or absent
            $table->string('absent_reason', 100)->nullable();    // required when status=absent|excused
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'participant_id', 'site_id', 'attendance_date'],
                'emr_day_center_attendance_unique');

            $table->index(['tenant_id', 'attendance_date']);
            $table->index(['tenant_id', 'site_id', 'attendance_date']);

            $table->foreign('participant_id')->references('id')->on('emr_participants')->onDelete('cascade');
            $table->foreign('site_id')->references('id')->on('shared_sites')->onDelete('cascade');
            $table->foreign('recorded_by_user_id')->references('id')->on('shared_users')->onDelete('cascade');
        });

        // PostgreSQL CHECK constraint on status values
        DB::statement("ALTER TABLE emr_day_center_attendance ADD CONSTRAINT emr_day_center_attendance_status_check CHECK (status IN ('present', 'absent', 'late', 'excused'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_day_center_attendance');
    }
};
