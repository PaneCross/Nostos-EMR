<?php

// ─── Migration: Create emr_clinical_orders table ─────────────────────────────
// W4-7 / BLOCKER-04: 42 CFR §460.90 requires all PACE services to be ordered
// and documented. This table implements a lightweight CPOE (Computerized Provider
// Order Entry) system for lab, imaging, therapy, consult, DME, and referral orders.
//
// Auto-routing: order_type determines target_department at creation time via
// ClinicalOrder::DEPARTMENT_ROUTING constant (enforced in ClinicalOrderController).
//
// Status lifecycle:
//   pending → acknowledged → in_progress → resulted (lab/imaging) or completed
//   pending → cancelled (any non-terminal status)
//
// Alert severity by priority: stat→critical, urgent→warning, routine→info
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_clinical_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('shared_sites')->cascadeOnDelete();
            $table->foreignId('ordered_by_user_id')->constrained('shared_users');
            $table->timestamp('ordered_at');

            // Clinical content
            $table->string('order_type', 50);          // see ORDER_TYPES constant
            $table->string('priority', 20);             // routine / urgent / stat
            $table->string('status', 30)->default('pending');
            $table->text('instructions');
            $table->text('clinical_indication')->nullable();

            // Routing
            $table->string('target_department', 50);   // auto-set from DEPARTMENT_ROUTING
            $table->string('target_facility', 200)->nullable();  // external facility name
            $table->date('due_date')->nullable();

            // Acknowledgment
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            // Result
            $table->timestamp('resulted_at')->nullable();
            $table->text('result_summary')->nullable();
            $table->foreignId('result_document_id')->nullable()->constrained('emr_documents')->nullOnDelete();

            // Completion / Cancellation
            $table->timestamp('completed_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();
        });

        // Order type CHECK constraint — all valid PACE order categories
        DB::statement("
            ALTER TABLE emr_clinical_orders
            ADD CONSTRAINT emr_clinical_orders_type_check
            CHECK (order_type IN (
                'lab', 'imaging', 'consult', 'therapy_pt', 'therapy_ot',
                'therapy_st', 'therapy_speech', 'dme', 'medication_change',
                'home_health', 'hospice_referral', 'other'
            ))
        ");

        // Priority CHECK
        DB::statement("
            ALTER TABLE emr_clinical_orders
            ADD CONSTRAINT emr_clinical_orders_priority_check
            CHECK (priority IN ('routine', 'urgent', 'stat'))
        ");

        // Status CHECK
        DB::statement("
            ALTER TABLE emr_clinical_orders
            ADD CONSTRAINT emr_clinical_orders_status_check
            CHECK (status IN ('pending', 'acknowledged', 'in_progress', 'resulted', 'completed', 'cancelled'))
        ");

        // Composite indexes for common query patterns
        DB::statement("CREATE INDEX emr_clinical_orders_participant_status_idx ON emr_clinical_orders (participant_id, status)");
        DB::statement("CREATE INDEX emr_clinical_orders_dept_status_tenant_idx ON emr_clinical_orders (target_department, status, tenant_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_clinical_orders');
    }
};
