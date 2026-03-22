<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->enum('department', [
                'primary_care', 'therapies', 'social_work', 'behavioral_health',
                'dietary', 'activities', 'home_care', 'transportation',
                'pharmacy', 'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
            ]);
            $table->enum('role', ['admin', 'standard']);
            $table->string('module', 100);              // e.g. clinical_notes, medications, transport_dashboard, etc.
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_export')->default(false);
            $table->timestamps();

            $table->unique(['department', 'role', 'module']);
            $table->index(['department', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_role_permissions');
    }
};
