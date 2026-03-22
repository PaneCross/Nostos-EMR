<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_participant_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $table->enum('address_type', ['home', 'center', 'emergency', 'other'])->default('home');
            $table->string('street', 200);
            $table->string('unit', 30)->nullable();
            $table->string('city', 100);
            $table->string('state', 2);
            $table->string('zip', 10);
            $table->text('notes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->date('effective_date')->nullable();
            $table->date('end_date')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['participant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_participant_addresses');
    }
};
