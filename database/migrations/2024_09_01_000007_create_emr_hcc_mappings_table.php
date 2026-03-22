<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_hcc_mappings', function (Blueprint $table) {
            $table->id();

            // ICD-10-CM diagnosis code (e.g. 'E11.9')
            $table->string('icd10_code', 10);

            // CMS-HCC category (e.g. 'HCC19') — null means code has no HCC mapping
            $table->string('hcc_category', 10)->nullable();

            // Human-readable HCC label (e.g. 'Diabetes without Complications')
            $table->string('hcc_label', 200)->nullable();

            // CMS relative adjustment factor for risk scoring
            $table->decimal('raf_value', 6, 4)->nullable();

            // Model year (CMS publishes updated mappings annually)
            $table->unsignedSmallInteger('effective_year')->default(2025);

            $table->timestamps();

            // One mapping per ICD-10 code per model year
            $table->unique(['icd10_code', 'effective_year']);
            $table->index('hcc_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_hcc_mappings');
    }
};
