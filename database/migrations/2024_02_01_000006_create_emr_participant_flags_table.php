<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_participant_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('shared_tenants');
            $table->enum('flag_type', [
                'wheelchair', 'stretcher', 'oxygen', 'behavioral',
                'fall_risk', 'wandering_risk', 'isolation', 'dnr',
                'weight_bearing_restriction', 'dietary_restriction',
                'elopement_risk', 'hospice', 'other',
            ]);
            $table->text('description')->nullable();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['participant_id', 'is_active']);
            $table->index(['tenant_id', 'flag_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_participant_flags');
    }
};
