<?php

// ─── Migration 68: add executive + super_admin to shared_users.department ─────
// Phase 10B — Executive Role & Super Admin Panel
//
// Extends the department CHECK constraint on shared_users to allow two new roles:
//   executive  — PACE organization leadership; cross-site read access within tenant
//   super_admin — Nostos staff; cross-tenant access for platform support/onboarding
//
// These are DEPARTMENT values (not role values). The existing role='super_admin'
// (e.g. tj@nostos.tech) is unaffected.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL enum is a CHECK constraint — drop and re-add with extended values.
        DB::statement("ALTER TABLE shared_users DROP CONSTRAINT IF EXISTS shared_users_department_check");
        DB::statement("ALTER TABLE shared_users ADD CONSTRAINT shared_users_department_check CHECK (department IN (
            'primary_care','therapies','social_work','behavioral_health',
            'dietary','activities','home_care','transportation',
            'pharmacy','idt','enrollment','finance','qa_compliance','it_admin',
            'executive','super_admin'
        ))");
    }

    public function down(): void
    {
        // Revert: will fail if any executive/super_admin department rows exist
        DB::statement("ALTER TABLE shared_users DROP CONSTRAINT IF EXISTS shared_users_department_check");
        DB::statement("ALTER TABLE shared_users ADD CONSTRAINT shared_users_department_check CHECK (department IN (
            'primary_care','therapies','social_work','behavioral_health',
            'dietary','activities','home_care','transportation',
            'pharmacy','idt','enrollment','finance','qa_compliance','it_admin'
        ))");
    }
};
