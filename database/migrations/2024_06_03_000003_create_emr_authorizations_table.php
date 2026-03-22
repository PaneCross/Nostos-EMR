<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_authorizations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('participant_id');

            $table->string('service_type', 100);
            $table->unsignedInteger('authorized_units')->nullable(); // e.g. # of visits
            $table->date('authorized_start');
            $table->date('authorized_end');
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('participant_id')->references('id')->on('emr_participants')->onDelete('cascade');

            $table->index(['tenant_id', 'status']);
            $table->index(['participant_id', 'authorized_end']);
            $table->index(['tenant_id', 'authorized_end']); // for expiry alerts
        });

        DB::statement("ALTER TABLE emr_authorizations ADD CONSTRAINT emr_authorizations_status_check
            CHECK (status IN ('active','expired','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_authorizations');
    }
};
