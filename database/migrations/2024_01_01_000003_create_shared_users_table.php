<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('shared_sites')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->enum('department', [
                'primary_care',
                'therapies',
                'social_work',
                'behavioral_health',
                'dietary',
                'activities',
                'home_care',
                'transportation',
                'pharmacy',
                'idt',
                'enrollment',
                'finance',
                'qa_compliance',
                'it_admin',
            ]);
            $table->enum('role', ['admin', 'standard'])->default('standard');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->foreignId('provisioned_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->timestamp('provisioned_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['tenant_id', 'department']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_users');
    }
};
