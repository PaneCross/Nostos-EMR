<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_participant_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $table->enum('contact_type', [
                'emergency', 'next_of_kin', 'poa', 'caregiver', 'pcp', 'specialist', 'other',
            ])->default('emergency');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('relationship', 100)->nullable();
            $table->string('phone_primary', 20)->nullable();
            $table->string('phone_secondary', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('is_legal_representative')->default(false);
            $table->boolean('is_emergency_contact')->default(false);
            $table->unsignedSmallInteger('priority_order')->default(1);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['participant_id', 'contact_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_participant_contacts');
    }
};
