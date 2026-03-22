<?php

// ─── ParticipantContactFactory ─────────────────────────────────────────────────
// Creates ParticipantContact model instances for tests and seeders.
// Requires participant_id to be set via factory state or explicit override.
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\Participant;
use App\Models\ParticipantContact;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParticipantContactFactory extends Factory
{
    protected $model = ParticipantContact::class;

    public function definition(): array
    {
        return [
            'participant_id'          => Participant::factory(),
            'contact_type'            => $this->faker->randomElement([
                'emergency', 'next_of_kin', 'poa', 'caregiver', 'pcp', 'specialist', 'other',
            ]),
            'first_name'              => $this->faker->firstName(),
            'last_name'               => $this->faker->lastName(),
            'relationship'            => $this->faker->randomElement(['Spouse', 'Daughter', 'Son', 'Friend', null]),
            // Phone stored as formatted (xxx) xxx-xxxx string to match frontend PhoneInput output
            'phone_primary'           => $this->fakePhone(),
            'phone_secondary'         => $this->faker->boolean(30) ? $this->fakePhone() : null,
            'email'                   => $this->faker->optional(0.4)->safeEmail(),
            'is_legal_representative' => $this->faker->boolean(15),
            'is_emergency_contact'    => $this->faker->boolean(60),
            'priority_order'          => $this->faker->numberBetween(1, 5),
            'notes'                   => null,
        ];
    }

    /** Format a fake phone number in (xxx) xxx-xxxx style */
    private function fakePhone(): string
    {
        return sprintf(
            '(%s) %s-%s',
            $this->faker->numerify('###'),
            $this->faker->numerify('###'),
            $this->faker->numerify('####'),
        );
    }

    /** Create an emergency contact */
    public function emergency(): static
    {
        return $this->state([
            'contact_type'         => 'emergency',
            'is_emergency_contact' => true,
            'priority_order'       => 1,
        ]);
    }

    /** Create a POA (legal representative) */
    public function poa(): static
    {
        return $this->state([
            'contact_type'            => 'poa',
            'is_legal_representative' => true,
            'is_emergency_contact'    => false,
        ]);
    }
}
