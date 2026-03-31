<?php

// ─── Migration 79: emr_consent_records ────────────────────────────────────────
// Tracks participant consent and acknowledgment records per HIPAA 45 CFR §164.520
// (NPP acknowledgment) and 42 CFR §460.110 (general consent requirements).
//
// consent_type=npp_acknowledgment is auto-created when a participant is enrolled
// (see EnrollmentService::handleEnrollment). QA dashboard shows participants
// missing their NPP acknowledgment as a compliance gap.
//
// document_path stores the location of signed consent form PDFs (via DocumentController).
// expiration_date is used for time-limited consents (e.g. research_consent, photo_release).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_consent_records', function (Blueprint $table) {
            $table->id();

            // ── Participant + tenant context ───────────────────────────────
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('participant_id')->references('id')->on('emr_participants')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->onDelete('cascade');

            // ── Consent type + document ────────────────────────────────────
            $table->string('consent_type');       // npp_acknowledgment | hipaa_authorization | treatment_consent | research_consent | photo_release | other
            $table->string('document_title');     // e.g. 'Notice of Privacy Practices - Sunrise PACE'
            $table->string('document_version')->nullable(); // e.g. '2025-01'
            $table->string('document_path')->nullable();    // Path to signed PDF (stored via DocumentController)

            // ── Consent decision ───────────────────────────────────────────
            $table->string('status');             // acknowledged | refused | unable_to_consent
            $table->string('acknowledged_by')->nullable(); // Name of participant or representative
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('representative_type')->nullable(); // self | guardian | poa | healthcare_proxy

            // ── Validity ───────────────────────────────────────────────────
            $table->date('expiration_date')->nullable(); // For time-limited consents

            // ── Metadata ───────────────────────────────────────────────────
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->foreign('created_by_user_id')->references('id')->on('shared_users')->onDelete('restrict');

            $table->timestamps();
        });

        // CHECK constraints
        \DB::statement("ALTER TABLE emr_consent_records ADD CONSTRAINT emr_consent_records_type_check CHECK (consent_type IN ('npp_acknowledgment','hipaa_authorization','treatment_consent','research_consent','photo_release','other'))");
        \DB::statement("ALTER TABLE emr_consent_records ADD CONSTRAINT emr_consent_records_status_check CHECK (status IN ('acknowledged','refused','unable_to_consent','pending'))");
        \DB::statement("ALTER TABLE emr_consent_records ADD CONSTRAINT emr_consent_records_representative_type_check CHECK (representative_type IS NULL OR representative_type IN ('self','guardian','poa','healthcare_proxy'))");

        // Performance indexes
        \DB::statement('CREATE INDEX emr_consent_records_participant_idx ON emr_consent_records (participant_id)');
        \DB::statement('CREATE INDEX emr_consent_records_tenant_type_idx ON emr_consent_records (tenant_id, consent_type)');
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_consent_records');
    }
};
