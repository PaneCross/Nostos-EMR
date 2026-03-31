<?php

// ─── ConsentRecordFactory ─────────────────────────────────────────────────────
// Generates test ConsentRecord records for participant consent tracking tests.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\ConsentRecord;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConsentRecordFactory extends Factory
{
    protected $model = ConsentRecord::class;

    public function definition(): array
    {
        $tenant = Tenant::factory()->create();

        return [
            'participant_id'     => Participant::factory()->create(['tenant_id' => $tenant->id])->id,
            'tenant_id'          => $tenant->id,
            'consent_type'       => 'npp_acknowledgment',
            'document_title'     => 'Notice of Privacy Practices',
            'document_version'   => '2025-01',
            'document_path'      => null,
            'status'             => 'pending',
            'acknowledged_by'    => null,
            'acknowledged_at'    => null,
            'representative_type'=> null,
            'expiration_date'    => null,
            'notes'              => null,
            'created_by_user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
        ];
    }

    /** Acknowledged consent record */
    public function acknowledged(): static
    {
        return $this->state([
            'status'              => 'acknowledged',
            'acknowledged_by'     => $this->faker->name(),
            'acknowledged_at'     => now()->subDays(rand(1, 30)),
            'representative_type' => 'self',
        ]);
    }

    /** Refused consent */
    public function refused(): static
    {
        return $this->state([
            'status' => 'refused',
            'notes'  => 'Participant declined to sign.',
        ]);
    }

    /** Unable to consent */
    public function unableToConsent(): static
    {
        return $this->state([
            'status'              => 'unable_to_consent',
            'acknowledged_by'     => $this->faker->name(),
            'representative_type' => 'guardian',
            'notes'               => 'Cognitive impairment documented. Guardian present.',
        ]);
    }

    /** Pending NPP acknowledgment (auto-created at enrollment) */
    public function pendingNpp(): static
    {
        return $this->state([
            'consent_type'   => 'npp_acknowledgment',
            'document_title' => 'Notice of Privacy Practices',
            'status'         => 'pending',
        ]);
    }
}
