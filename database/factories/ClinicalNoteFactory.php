<?php

namespace Database\Factories;

use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClinicalNoteFactory extends Factory
{
    protected $model = ClinicalNote::class;

    // ── Department maps to note type for realistic data ───────────────────────
    private const DEPT_NOTE_TYPES = [
        'primary_care'      => 'soap',
        'therapies'         => 'therapy_pt',
        'social_work'       => 'social_work',
        'behavioral_health' => 'behavioral_health',
        'dietary'           => 'dietary',
        'home_care'         => 'home_visit',
    ];

    private const VISIT_TYPES = ['in_center', 'telehealth', 'home_visit', 'phone'];

    public function definition(): array
    {
        $noteType  = $this->faker->randomElement(ClinicalNote::NOTE_TYPES);
        $status    = $this->faker->randomElement([
            ClinicalNote::STATUS_DRAFT,
            ClinicalNote::STATUS_DRAFT,   // weighted toward draft
            ClinicalNote::STATUS_SIGNED,
        ]);

        $visitDate = $this->faker->dateTimeBetween('-6 months', 'now');

        return [
            'participant_id'      => Participant::factory(),
            'tenant_id'           => Tenant::factory(),
            'site_id'             => fn (array $attributes) =>
                \App\Models\Participant::find($attributes['participant_id'])?->site_id ?? 1,
            'note_type'           => $noteType,
            'authored_by_user_id' => User::factory(),
            'department'          => $this->faker->randomElement([
                'primary_care', 'therapies', 'social_work',
                'behavioral_health', 'dietary', 'home_care',
            ]),
            'status'              => $status,
            'visit_type'          => $this->faker->randomElement(self::VISIT_TYPES),
            'visit_date'          => $visitDate->format('Y-m-d'),
            'visit_time'          => $this->faker->time('H:i:s'),
            'subjective'          => in_array($noteType, ClinicalNote::SOAP_NOTE_TYPES)
                ? $this->faker->paragraph(2)
                : null,
            'objective'           => in_array($noteType, ClinicalNote::SOAP_NOTE_TYPES)
                ? $this->faker->paragraph(2)
                : null,
            'assessment'          => in_array($noteType, ClinicalNote::SOAP_NOTE_TYPES)
                ? $this->faker->sentence()
                : null,
            'plan'                => in_array($noteType, ClinicalNote::SOAP_NOTE_TYPES)
                ? $this->faker->paragraph()
                : null,
            'content'             => ! in_array($noteType, ClinicalNote::SOAP_NOTE_TYPES)
                ? ['notes' => $this->faker->paragraph(3)]
                : null,
            'signed_at'           => $status === ClinicalNote::STATUS_SIGNED
                ? $visitDate
                : null,
            'signed_by_user_id'   => null,
            'parent_note_id'      => null,
            'is_late_entry'       => $this->faker->boolean(5),  // 5% late entries
            'late_entry_reason'   => null,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    public function draft(): static
    {
        return $this->state([
            'status'            => ClinicalNote::STATUS_DRAFT,
            'signed_at'         => null,
            'signed_by_user_id' => null,
        ]);
    }

    public function signed(): static
    {
        return $this->state(fn () => [
            'status'            => ClinicalNote::STATUS_SIGNED,
            'signed_at'         => $this->faker->dateTimeBetween('-3 months', 'now'),
            'signed_by_user_id' => null,  // caller should set via afterCreating
        ]);
    }

    public function soap(): static
    {
        return $this->state(fn () => [
            'note_type'  => 'soap',
            'subjective' => $this->faker->paragraph(2),
            'objective'  => $this->faker->paragraph(2),
            'assessment' => $this->faker->sentence(),
            'plan'       => $this->faker->paragraph(),
            'content'    => null,
        ]);
    }

    public function lateEntry(): static
    {
        return $this->state(fn () => [
            'is_late_entry'    => true,
            'late_entry_reason' => $this->faker->randomElement([
                'Documentation delay due to emergency',
                'System outage prevented timely entry',
                'Provider was off-site at time of visit',
            ]),
        ]);
    }

    public function forParticipant(int $participantId): static
    {
        return $this->state(['participant_id' => $participantId]);
    }

    public function forTenant(int $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

}
