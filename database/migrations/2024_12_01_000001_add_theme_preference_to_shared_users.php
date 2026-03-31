<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ─── Migration 75: Theme Preference on shared_users ───────────────────────────
// Adds `theme_preference` (light/dark) to shared_users so each user's chosen
// display mode is persisted server-side. Synced to the frontend via Inertia
// shared props (HandleInertiaRequests) and toggled via POST /user/theme.
//
// Uses raw DB::statement for the CHECK constraint (standard pattern in this
// codebase — Blueprint::check() is not available in this Laravel version).
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE shared_users
            ADD COLUMN theme_preference VARCHAR(10) NOT NULL DEFAULT 'light'
            CHECK (theme_preference IN ('light', 'dark'))
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE shared_users DROP COLUMN theme_preference");
    }
};
