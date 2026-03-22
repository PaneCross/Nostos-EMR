<?php

// ─── DocumentFactory ──────────────────────────────────────────────────────────
// Generates emr_documents rows for tests and Phase 8A demo seeder.
//
// State helpers:
//   ->pdf()           — creates a PDF document record
//   ->image()         — creates a JPEG image document record
//   ->forCategory($c) — sets document_category to a specific value
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\Document;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $category = $this->faker->randomElement(Document::VALID_CATEGORIES);
        $ext      = $this->faker->randomElement(['pdf', 'docx', 'jpg', 'png']);
        $fileName = $this->faker->words(3, true) . '.' . $ext;

        // Build a plausible relative storage path (no real file created)
        $participantId = $this->faker->numberBetween(1, 30);
        $filePath      = "participants/{$participantId}/" . $this->faker->uuid() . ".{$ext}";

        return [
            'participant_id'      => Participant::factory(),
            'tenant_id'           => Tenant::factory(),
            'site_id'             => null,
            'file_name'           => $fileName,
            'file_path'           => $filePath,
            'file_type'           => $ext === 'jpg' ? 'jpeg' : $ext,
            'file_size_bytes'     => $this->faker->numberBetween(10_000, 5_000_000),
            'description'         => $this->faker->optional(0.6)->sentence(),
            'document_category'   => $category,
            'uploaded_by_user_id' => User::factory(),
            'uploaded_at'         => $this->faker->dateTimeBetween('-6 months', 'now'),
        ];
    }

    /** Simulate a PDF document upload. */
    public function pdf(): static
    {
        return $this->state(fn () => [
            'file_type' => 'pdf',
            'file_name' => $this->faker->words(3, true) . '.pdf',
        ]);
    }

    /** Simulate a JPEG image upload (e.g. wound photo, scanned form). */
    public function image(): static
    {
        return $this->state(fn () => [
            'file_type' => 'jpeg',
            'file_name' => $this->faker->words(2, true) . '.jpg',
        ]);
    }

    /** Simulate a document in a specific category. */
    public function forCategory(string $category): static
    {
        return $this->state(fn () => ['document_category' => $category]);
    }
}
