<?php

// ─── Migration: emr_icd10_lookup ────────────────────────────────────────────────
// Static ICD-10 code reference table used for typeahead search in the problem list.
// Seeded with ~200 PACE-relevant codes by Icd10Seeder.
// The description column gets a GIN index for fast full-text search on PostgreSQL.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_icd10_lookup', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('description', 200);
            $table->string('category', 100)->nullable();  // e.g., 'Cardiovascular', 'Neurological'
            $table->timestamp('created_at')->useCurrent();

            $table->index('code');
        });

        // PostgreSQL GIN index on description for fast ILIKE / to_tsvector search
        DB::statement("CREATE INDEX emr_icd10_lookup_description_gin ON emr_icd10_lookup USING gin(to_tsvector('english', description))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_icd10_lookup');
    }
};
