<?php

// ─── Migration: emr_locations ──────────────────────────────────────────────────
// Physical locations used for appointment scheduling.
//
// Location types cover all PACE service delivery settings:
//   pace_center     — the PACE day center itself (home base)
//   acs_location    — Adult Care Setting (contracted adult day health)
//   dialysis        — dialysis clinic (common PACE comorbidity)
//   specialist      — specialist physician offices
//   hospital        — acute care hospital (for tracking admissions/visits)
//   pharmacy        — pharmacy pickup / delivery
//   lab             — laboratory draw site
//   day_program     — contracted day programs / activity centers
//   other_external  — any other external site
//
// 'label' is a short display name used in the driver app and appointment cards
// (e.g. "Dialysis - Sunrise" instead of the full street address).
//
// Multi-tenant: each location belongs to one tenant. Locations are NOT shared
// across organizations.
//
// Soft deletes: locations can be deactivated (is_active=false) or fully
// soft-deleted. Both preserve historical appointment records.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_locations', function (Blueprint $table) {
            $table->id();

            // ── Tenant scope ───────────────────────────────────────────────────
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // ── Classification ────────────────────────────────────────────────
            $table->enum('location_type', [
                'pace_center',
                'acs_location',
                'dialysis',
                'specialist',
                'hospital',
                'pharmacy',
                'lab',
                'day_program',
                'other_external',
            ]);

            // ── Identity ──────────────────────────────────────────────────────
            $table->string('name', 150);
            $table->string('label', 100)->nullable();   // Short label for UI/driver app (optional)

            // ── Address ───────────────────────────────────────────────────────
            $table->string('street', 200)->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip', 10)->nullable();

            // ── Contact ───────────────────────────────────────────────────────
            $table->string('phone', 20)->nullable();
            $table->string('contact_name', 100)->nullable();

            // ── Notes / status ────────────────────────────────────────────────
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            // ── Audit ─────────────────────────────────────────────────────────
            $table->softDeletes();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['tenant_id', 'is_active'], 'locations_tenant_active_idx');
            $table->index(['tenant_id', 'location_type'], 'locations_tenant_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_locations');
    }
};
