<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit A — The shared_users.role check constraint did not include 'super_admin',
 * causing the DemoEnvironmentSeeder to fail on fresh deployments.
 * Add 'super_admin' to the allowed role values.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL enum is a CHECK constraint — drop and re-add with extended values.
        DB::statement("ALTER TABLE shared_users DROP CONSTRAINT IF EXISTS shared_users_role_check");
        DB::statement("ALTER TABLE shared_users ADD CONSTRAINT shared_users_role_check CHECK (role IN ('admin', 'standard', 'super_admin'))");
    }

    public function down(): void
    {
        // Revert to original two-value constraint (will fail if any super_admin rows exist)
        DB::statement("ALTER TABLE shared_users DROP CONSTRAINT IF EXISTS shared_users_role_check");
        DB::statement("ALTER TABLE shared_users ADD CONSTRAINT shared_users_role_check CHECK (role IN ('admin', 'standard'))");
    }
};
