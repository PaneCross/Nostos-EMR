<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_insurance_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $table->enum('payer_type', [
                'medicare_a', 'medicare_b', 'medicare_d', 'medicaid', 'other',
            ]);
            $table->string('member_id', 50)->nullable();
            $table->string('group_id', 50)->nullable();
            $table->string('plan_name', 150)->nullable();
            $table->string('bin_pcn', 50)->nullable();
            $table->date('effective_date')->nullable();
            $table->date('term_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['participant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_insurance_coverages');
    }
};
