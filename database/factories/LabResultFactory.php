<?php

namespace Database\Factories;

use App\Models\LabResult;
use App\Models\LabResultComponent;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabResultFactory extends Factory
{
    protected $model = LabResult::class;

    // Common lab panel names for realistic seeding
    private static array $panels = [
        'CBC with Differential',
        'Comprehensive Metabolic Panel',
        'Basic Metabolic Panel',
        'Lipid Panel',
        'Hemoglobin A1c',
        'TSH',
        'Urinalysis',
        'PT/INR',
        'PSA',
        'Vitamin D, 25-Hydroxy',
        'Iron Studies',
        'Magnesium Level',
    ];

    private static array $loinc = [
        'CBC with Differential'         => '58410-2',
        'Comprehensive Metabolic Panel'  => '24323-8',
        'Basic Metabolic Panel'          => '24320-4',
        'Lipid Panel'                    => '57698-3',
        'Hemoglobin A1c'                 => '4548-4',
        'TSH'                            => '3016-3',
        'Urinalysis'                     => '24357-6',
        'PT/INR'                         => '5902-2',
        'PSA'                            => '2857-1',
        'Vitamin D, 25-Hydroxy'          => '1989-3',
        'Iron Studies'                   => '24325-3',
        'Magnesium Level'                => '2593-2',
    ];

    public function definition(): array
    {
        $testName = $this->faker->randomElement(self::$panels);
        $isAbnormal = $this->faker->boolean(20);
        $isReviewed = $isAbnormal ? $this->faker->boolean(60) : $this->faker->boolean(80);
        $collectedAt = $this->faker->dateTimeBetween('-6 months', '-1 day');

        return [
            'participant_id'          => Participant::factory(),
            'tenant_id'               => Tenant::factory(),
            'integration_log_id'      => null,
            'test_name'               => $testName,
            'test_code'               => self::$loinc[$testName] ?? null,
            'collected_at'            => $collectedAt,
            'resulted_at'             => $this->faker->dateTimeBetween($collectedAt, 'now'),
            'ordering_provider_name'  => $this->faker->optional(0.7)->name(),
            'performing_facility'     => $this->faker->optional(0.8)->randomElement([
                'Sunrise PACE Laboratory', 'Quest Diagnostics', 'LabCorp', 'Hospital Lab',
            ]),
            'source'                  => $this->faker->randomElement(['hl7_inbound', 'manual_entry']),
            'overall_status'          => 'final',
            'abnormal_flag'           => $isAbnormal,
            'reviewed_by_user_id'     => null,
            'reviewed_at'             => null,
            'notes'                   => $this->faker->optional(0.2)->sentence(),
        ];
    }

    /** Lab result with no abnormal components (all normal). */
    public function normal(): static
    {
        return $this->state([
            'abnormal_flag' => false,
        ]);
    }

    /** Lab result flagged as abnormal. */
    public function abnormal(): static
    {
        return $this->state([
            'abnormal_flag' => true,
        ]);
    }

    /** Lab result with a critical-value component. */
    public function critical(): static
    {
        return $this->afterCreating(function (LabResult $lab) {
            LabResultComponent::create([
                'lab_result_id'   => $lab->id,
                'component_name'  => 'Potassium',
                'component_code'  => '2823-3',
                'value'           => '2.8',
                'unit'            => 'mEq/L',
                'reference_range' => '3.5-5.1',
                'abnormal_flag'   => 'critical_low',
            ]);
            $lab->update(['abnormal_flag' => true]);
        });
    }

    /** Lab result that has been reviewed by a clinician. */
    public function reviewed(User $reviewer = null): static
    {
        return $this->afterCreating(function (LabResult $lab) use ($reviewer) {
            $userId = $reviewer?->id ?? User::factory()->create()->id;
            $lab->update([
                'reviewed_by_user_id' => $userId,
                'reviewed_at'         => now()->subHours($this->faker->numberBetween(1, 72)),
            ]);
        });
    }

    /** Sourced from HL7 inbound. */
    public function fromHl7(?int $integrationLogId = null): static
    {
        return $this->state([
            'source'             => 'hl7_inbound',
            'integration_log_id' => $integrationLogId,
        ]);
    }

    /** Manually entered by clinical staff. */
    public function manual(): static
    {
        return $this->state([
            'source'             => 'manual_entry',
            'integration_log_id' => null,
        ]);
    }

    /** Preliminary result (not yet finalized). */
    public function preliminary(): static
    {
        return $this->state([
            'overall_status' => 'preliminary',
            'resulted_at'    => null,
        ]);
    }
}
