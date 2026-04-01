<?php

// ─── Migration: add VIS fields to emr_immunizations (W4-4 QW-11) ─────────────
// Federal law (42 USC 300aa-26) and CMS PACE guidelines require that a Vaccine
// Information Statement (VIS) be given to each patient before administration.
// Clinicians must document that VIS was provided and note the VIS publication date
// (which identifies which version of the statement was given).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_immunizations', function (Blueprint $table) {
            $table->boolean('vis_given')->default(false)->after('refusal_reason');
            $table->date('vis_publication_date')->nullable()->after('vis_given');
        });
    }

    public function down(): void
    {
        Schema::table('emr_immunizations', function (Blueprint $table) {
            $table->dropColumn(['vis_given', 'vis_publication_date']);
        });
    }
};
