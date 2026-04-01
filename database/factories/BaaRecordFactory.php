<?php

namespace Database\Factories;

use App\Models\BaaRecord;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for BaaRecord.
 *
 * Default: active BAA with expiration ~18 months in the future.
 * States document real-world BAA lifecycle scenarios for test authors.
 */
class BaaRecordFactory extends Factory
{
    protected $model = BaaRecord::class;

    public function definition(): array
    {
        return [
            'tenant_id'           => Tenant::factory(),
            'vendor_name'         => $this->faker->company(),
            'vendor_type'         => $this->faker->randomElement(BaaRecord::VENDOR_TYPES),
            'phi_accessed'        => $this->faker->boolean(80),
            'baa_signed_date'     => now()->subMonths(rand(6, 18))->toDateString(),
            'baa_expiration_date' => now()->addMonths(rand(6, 24))->toDateString(),
            'status'              => 'active',
            'contact_name'        => $this->faker->name(),
            'contact_email'       => $this->faker->companyEmail(),
            'contact_phone'       => $this->faker->phoneNumber(),
            'notes'               => null,
        ];
    }

    /**
     * BAA whose expiration date is in the past.
     * Simulates a vendor whose BAA was never renewed — creates a compliance gap.
     */
    public function expired(): static
    {
        return $this->state([
            'baa_expiration_date' => now()->subDays(rand(1, 180))->toDateString(),
            'status'              => 'expired',
        ]);
    }

    /**
     * BAA expiring within the 60-day warning window.
     * Simulates an upcoming renewal. Triggers amber badge in Security UI.
     */
    public function expiringSoon(): static
    {
        return $this->state([
            'baa_expiration_date' => now()->addDays(rand(5, 50))->toDateString(),
            'status'              => 'expiring_soon',
        ]);
    }

    /**
     * BAA pending signature — vendor selected but agreement not yet executed.
     * Common for new clearinghouse relationships (e.g. prior to billing go-live).
     */
    public function pending(): static
    {
        return $this->state([
            'baa_signed_date'     => null,
            'baa_expiration_date' => null,
            'status'              => 'pending',
        ]);
    }
}
