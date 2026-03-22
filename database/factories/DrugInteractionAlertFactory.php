<?php

namespace Database\Factories;

use App\Models\DrugInteractionAlert;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DrugInteractionAlertFactory extends Factory
{
    protected $model = DrugInteractionAlert::class;

    public function definition(): array
    {
        return [
            'participant_id'  => Participant::factory(),
            'tenant_id'       => Tenant::factory(),
            'medication_id_1' => Medication::factory(),
            'medication_id_2' => Medication::factory(),
            'drug_name_1'     => 'Warfarin',
            'drug_name_2'     => 'Aspirin',
            'severity'        => $this->faker->randomElement(['contraindicated', 'major', 'moderate', 'minor']),
            'description'     => 'Concurrent use increases bleeding risk significantly.',
            'is_acknowledged' => false,
            'acknowledged_by_user_id' => null,
            'acknowledged_at' => null,
            'acknowledgement_note' => null,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    /** Simulate a contraindicated interaction (highest severity). */
    public function contraindicated(): static
    {
        return $this->state([
            'severity'    => 'contraindicated',
            'drug_name_1' => 'Warfarin',
            'drug_name_2' => 'Aspirin',
            'description' => 'Concurrent use of warfarin and aspirin greatly increases hemorrhage risk.',
        ]);
    }

    /** Simulate an already-acknowledged interaction. */
    public function acknowledged(?int $userId = null): static
    {
        return $this->state([
            'is_acknowledged'         => true,
            'acknowledged_by_user_id' => $userId,
            'acknowledged_at'         => now()->subHours(2),
            'acknowledgement_note'    => 'Reviewed; benefit outweighs risk for this patient.',
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
