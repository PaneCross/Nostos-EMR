<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = $this->faker->company() . ' PACE';

        return [
            'name'               => $name,
            'slug'               => Str::slug($name),
            'transport_mode'     => 'direct',
            'cms_contract_id'    => 'H' . $this->faker->numerify('####'),
            'state'              => $this->faker->stateAbbr(),
            'timezone'           => $this->faker->randomElement([
                'America/New_York',
                'America/Chicago',
                'America/Los_Angeles',
                'America/Denver',
            ]),
            'auto_logout_minutes' => 15,
            'is_active'          => true,
        ];
    }

    public function demo(): static
    {
        return $this->state([
            'name'            => 'Sunrise PACE — Demo Organization',
            'slug'            => 'sunrise-pace-demo',
            'transport_mode'  => 'direct',
            'cms_contract_id' => 'H9999',
            'state'           => 'CA',
            'timezone'        => 'America/Los_Angeles',
        ]);
    }
}
