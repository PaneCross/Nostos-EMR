<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        // mrn_prefix must be unique across factory-created sites to prevent MRN collisions
        // when multiple participants are created per test (each site gets sequence 00001).
        $prefix = strtoupper($this->faker->unique()->lexify('????'));

        return [
            'tenant_id'  => Tenant::factory(),
            'mrn_prefix' => $prefix,
            'name'       => $this->faker->company() . ' PACE ' . $prefix,
            'address'   => $this->faker->streetAddress(),
            'city'      => $this->faker->city(),
            'state'     => $this->faker->stateAbbr(),
            'zip'       => $this->faker->postcode(),
            'phone'     => $this->faker->phoneNumber(),
            'is_active' => true,
        ];
    }
}
