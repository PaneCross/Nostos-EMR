<?php

// ─── Migration: emr_drug_interactions_reference ───────────────────────────────
// Static reference table of known clinically-significant drug-drug interactions.
// Seeded by MedicationsReferenceSeeder with ~100 interaction pairs.
//
// DrugInteractionService::checkInteractions() queries this table to detect
// interactions when a new medication is added to a participant.
//
// The pair (drug_name_1, drug_name_2) is normalized: drug_name_1 < drug_name_2
// alphabetically to avoid duplicate pairs in both orderings.
// Lookups use an OR condition to match either direction.
//
// severity levels mirror the emr_drug_interaction_alerts table:
//   contraindicated > major > moderate > minor
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_drug_interactions_reference', function (Blueprint $table) {
            $table->id();

            // Normalized pair: drug_name_1 < drug_name_2 alphabetically
            $table->string('drug_name_1', 200);
            $table->string('drug_name_2', 200);

            $table->enum('severity', [
                'contraindicated',
                'major',
                'moderate',
                'minor',
            ]);
            $table->text('description');  // Clinical explanation of the interaction

            $table->timestamp('created_at')->useCurrent();  // Static reference

            // ── Unique pair constraint ─────────────────────────────────────────
            $table->unique(['drug_name_1', 'drug_name_2'], 'drug_interact_pair_unique');

            // ── Indexes for fast lookup ────────────────────────────────────────
            $table->index('drug_name_1', 'drug_interact_name1_idx');
            $table->index('drug_name_2', 'drug_interact_name2_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_drug_interactions_reference');
    }
};
