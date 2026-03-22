<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    // Realistic location names per type — typical PACE partner organizations
    private static array $locationNames = [
        'pace_center'    => ['Sunrise PACE East', 'Sunrise PACE West', 'Sunrise PACE North'],
        'acs_location'   => ['Sunrise Adult Care Setting', 'Lakeside ACS'],
        'dialysis'       => ['DaVita Dialysis Center', 'Fresenius Kidney Care', 'American Kidney Fund Center'],
        'specialist'     => ['Central Cardiology Associates', 'Neurology Partners LLC', 'Orthopedic Specialists of Central Ohio', 'Valley Pulmonology Group'],
        'hospital'       => ['Community Medical Center', 'Regional General Hospital', 'St. Mercy Health System'],
        'pharmacy'       => ['CVS Pharmacy #4821', 'Walgreens #7712', 'PACE Specialty Pharmacy'],
        'lab'            => ['Quest Diagnostics', 'LabCorp Patient Service Center', 'Hospital Reference Lab'],
        'day_program'    => ['Senior Circle Day Program', 'Valley Adult Day Services'],
        'other_external' => ['Home Health Agency', 'Community Mental Health Center', 'Hospice Partners'],
    ];

    public function definition(): array
    {
        $type  = $this->faker->randomElement(Location::LOCATION_TYPES);
        $names = self::$locationNames[$type] ?? ['External Location'];

        return [
            'tenant_id'     => fn () => Tenant::factory()->create()->id,
            'location_type' => $type,
            'name'          => $this->faker->randomElement($names),
            'label'         => null,
            'street'        => $this->faker->streetAddress(),
            'unit'          => $this->faker->optional(0.3)->secondaryAddress(),
            'city'          => $this->faker->city(),
            'state'         => $this->faker->stateAbbr(),
            'zip'           => $this->faker->postcode(),
            'phone'         => $this->faker->numerify('(###) ###-####'),
            'contact_name'  => $this->faker->optional(0.7)->name(),
            'notes'         => $this->faker->optional(0.3)->sentence(),
            'is_active'     => true,
        ];
    }

    /** PACE center (main day center). */
    public function paceCenter(): static
    {
        return $this->state([
            'location_type' => 'pace_center',
            'name'          => $this->faker->randomElement(self::$locationNames['pace_center']),
        ]);
    }

    /** Dialysis facility. */
    public function dialysis(): static
    {
        return $this->state([
            'location_type' => 'dialysis',
            'name'          => $this->faker->randomElement(self::$locationNames['dialysis']),
        ]);
    }

    /** Specialist office. */
    public function specialist(): static
    {
        return $this->state([
            'location_type' => 'specialist',
            'name'          => $this->faker->randomElement(self::$locationNames['specialist']),
        ]);
    }

    /** Inactive (archived/deactivated) location. */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
