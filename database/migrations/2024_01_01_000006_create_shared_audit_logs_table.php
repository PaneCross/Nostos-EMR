<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 100);              // login, logout, otp_sent, otp_failed, session_timeout, unauthorized_access, phi_read, phi_write, etc.
            $table->string('resource_type', 100)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });

        // Enforce append-only via PostgreSQL rules — no UPDATE or DELETE allowed
        DB::unprepared('
            CREATE RULE audit_no_update AS ON UPDATE TO shared_audit_logs DO INSTEAD NOTHING;
            CREATE RULE audit_no_delete AS ON DELETE TO shared_audit_logs DO INSTEAD NOTHING;
        ');
    }

    public function down(): void
    {
        DB::unprepared('
            DROP RULE IF EXISTS audit_no_update ON shared_audit_logs;
            DROP RULE IF EXISTS audit_no_delete ON shared_audit_logs;
        ');
        Schema::dropIfExists('shared_audit_logs');
    }
};
