<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_users', function (Blueprint $table) {
            // Per-user notification delivery preferences.
            // Keys: alert type slug (e.g. 'alert_critical', 'sdr_overdue', 'new_message')
            // Values: 'in_app_only' | 'email_immediate' | 'email_digest' | 'off'
            // Missing key = fallback to 'in_app_only'.
            $table->jsonb('notification_preferences')->default('{}')->after('provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('shared_users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
