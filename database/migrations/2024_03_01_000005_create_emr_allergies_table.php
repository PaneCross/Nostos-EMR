<?php

// ─── Migration: emr_allergies ───────────────────────────────────────────────────
// Drug/food/environmental allergies and dietary restrictions.
// Life-threatening allergies display a persistent red banner on the participant profile.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_allergies', function (Blueprint $table) {
            $table->id();

            // ── Participant + tenant ownership ────────────────────────────────
            $table->foreignId('participant_id')
                ->constrained('emr_participants')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('shared_tenants')
                ->cascadeOnDelete();

            // ── Allergy classification ────────────────────────────────────────
            $table->enum('allergy_type', [
                'drug', 'food', 'environmental', 'dietary_restriction', 'latex', 'contrast',
            ]);
            $table->string('allergen_name', 150);
            $table->text('reaction_description')->nullable();

            // ── Severity ──────────────────────────────────────────────────────
            // life_threatening triggers the participant profile red banner
            $table->enum('severity', [
                'mild', 'moderate', 'severe', 'life_threatening', 'intolerance',
            ]);

            // ── Timeline and verification ─────────────────────────────────────
            $table->date('onset_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('verified_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['participant_id', 'is_active']);
            $table->index(['participant_id', 'severity']);  // fast banner query
            $table->index(['tenant_id', 'allergy_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_allergies');
    }
};
