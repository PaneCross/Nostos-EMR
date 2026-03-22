<?php

namespace Database\Factories;

use App\Models\EncounterLog;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EncounterLogFactory extends Factory
{
    protected $model = EncounterLog::class;

    public function definition(): array
    {
        return [
            'tenant_id'          => Tenant::factory(),
            'participant_id'     => Participant::factory(),
            'service_date'       => $this->faker->dateTimeBetween('-90 days', 'now')->format('Y-m-d'),
            'service_type'       => $this->faker->randomElement(array_keys(\App\Models\EncounterLog::SERVICE_TYPES)),
            'procedure_code'     => $this->faker->boolean(60) ? $this->faker->numerify('9921#') : null,
            'provider_user_id'   => null,
            'notes'              => $this->faker->boolean(40) ? $this->faker->sentence() : null,
            'created_by_user_id' => null,
        ];
    }

    /** Encounter with procedure code. */
    public function withProcedureCode(string $code = '99213'): static
    {
        return $this->state(['procedure_code' => $code]);
    }
}
