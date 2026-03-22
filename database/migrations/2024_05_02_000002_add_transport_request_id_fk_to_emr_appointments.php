<?php

// ─── Migration: FK emr_appointments.transport_request_id ──────────────────────
// Phase 5B: Now that emr_transport_requests exists (EMR-side table), we can
// add the real FK constraint from emr_appointments.transport_request_id.
//
// Phase 5A created transport_request_id as a plain unsignedBigInteger with
// no FK, because it was intended as a cross-app reference to transport_trips.
// Phase 5B redefines it as an EMR-internal reference: appointments link to
// emr_transport_requests records (which in turn bridge to transport_trips).
//
// nullOnDelete: if the transport request record is cancelled and somehow removed,
// the appointment survives (transport_request_id just becomes null).
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_appointments', function (Blueprint $table) {
            // Add the FK constraint now that emr_transport_requests exists.
            // The column itself (transport_request_id unsignedBigInteger nullable) was
            // already created in the Phase 5A migration; we're just adding the constraint.
            $table->foreign('transport_request_id')
                ->references('id')
                ->on('emr_transport_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emr_appointments', function (Blueprint $table) {
            $table->dropForeign(['transport_request_id']);
        });
    }
};
