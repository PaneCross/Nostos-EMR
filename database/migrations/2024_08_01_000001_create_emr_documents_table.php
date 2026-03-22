<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── emr_documents ────────────────────────────────────────────────────────────
// Stores metadata for participant-level documents (care plan PDFs, consent
// forms, referral letters, lab reports, etc.).
//
// Files are stored on local disk at storage/app/participants/{participant_id}/.
// File paths stored relative to the storage root — never exposed in URLs.
// Soft-delete only (HIPAA: documents may not be permanently destroyed).
//
// Max upload: 20 MB. Accepted MIME types: PDF, JPEG, PNG, DOCX.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_documents', function (Blueprint $table) {
            $table->id();

            // ── Ownership ──────────────────────────────────────────────────────
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id')->nullable();

            // ── File metadata ─────────────────────────────────────────────────
            $table->string('file_name');                        // Original filename shown to users
            $table->string('file_path');                        // Relative path inside storage/app/
            $table->string('file_type', 10);                   // pdf | jpeg | png | docx
            $table->unsignedInteger('file_size_bytes');

            // ── Document classification ───────────────────────────────────────
            $table->string('description')->nullable();
            $table->string('document_category', 50)            // Enforced by PHP enum in model
                  ->default('other');

            // ── Upload provenance ─────────────────────────────────────────────
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->timestamp('uploaded_at')->useCurrent();

            // ── Soft delete (HIPAA — never hard-delete participant documents) ─
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'tenant_id']);
            $table->index(['tenant_id', 'document_category']);
            $table->index('uploaded_at');

            // ── Foreign keys ──────────────────────────────────────────────────
            $table->foreign('participant_id')->references('id')->on('emr_participants')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('uploaded_by_user_id')->references('id')->on('shared_users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_documents');
    }
};
