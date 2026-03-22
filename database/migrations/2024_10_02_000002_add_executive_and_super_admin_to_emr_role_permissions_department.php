<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE emr_role_permissions DROP CONSTRAINT IF EXISTS emr_role_permissions_department_check');
        DB::statement("ALTER TABLE emr_role_permissions ADD CONSTRAINT emr_role_permissions_department_check CHECK (department IN (
            'primary_care','therapies','social_work','behavioral_health',
            'dietary','activities','home_care','transportation',
            'pharmacy','idt','enrollment','finance','qa_compliance','it_admin',
            'executive','super_admin'
        ))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_role_permissions DROP CONSTRAINT IF EXISTS emr_role_permissions_department_check');
        DB::statement("ALTER TABLE emr_role_permissions ADD CONSTRAINT emr_role_permissions_department_check CHECK (department IN (
            'primary_care','therapies','social_work','behavioral_health',
            'dietary','activities','home_care','transportation',
            'pharmacy','idt','enrollment','finance','qa_compliance','it_admin'
        ))");
    }
};
