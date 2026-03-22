<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit A — add the (resource_type, resource_id) composite index to shared_audit_logs.
 * Required for efficient lookup of all audit events for a given resource.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_audit_logs', function (Blueprint $table) {
            $table->index(['resource_type', 'resource_id'], 'audit_logs_resource_type_resource_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('shared_audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_resource_type_resource_id_index');
        });
    }
};
