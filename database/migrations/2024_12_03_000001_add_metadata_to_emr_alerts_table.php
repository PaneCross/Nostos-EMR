<?php

// ─── Migration: add metadata JSONB to emr_alerts ──────────────────────────────
// Adds a nullable JSONB `metadata` column for alert-type-specific context.
// Used by 'chat' alerts to store channel_id for deep-linking notification
// clicks directly to the relevant chat channel (/chat?channel={id}).
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_alerts', function (Blueprint $table) {
            // Arbitrary context payload (e.g. ['channel_id' => 42] for chat alerts).
            // Nullable so existing alerts are unaffected.
            $table->jsonb('metadata')->nullable()->after('target_departments');
        });
    }

    public function down(): void
    {
        Schema::table('emr_alerts', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
