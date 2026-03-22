<?php

// ─── EdiBatchFactory ──────────────────────────────────────────────────────────
// Generates emr_edi_batches rows for tests and Phase 9B demo seeder.
//
// State helpers:
//   ->submitted()  — marks batch as submitted with a submitted_at timestamp
//   ->rejected()   — marks batch as rejected
//   ->crr()        — creates a Chart Review Record batch
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\EdiBatch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EdiBatchFactory extends Factory
{
    protected $model = EdiBatch::class;

    public function definition(): array
    {
        $batchId   = 'BATCH' . $this->faker->numerify('####') . now()->format('YmdHis');
        $recordCount = $this->faker->numberBetween(1, 50);
        $totalCharge = $this->faker->randomFloat(2, 500, 25000);

        // Minimal valid X12 ISA/GS preamble for test purposes
        $fileContent = implode("~\n", [
            'ISA*00*          *00*          *ZZ*1234567890     *ZZ*CMSMEDICARED   *' . now()->format('ymd') . '*' . now()->format('Hi') . '*^*00501*000000001*0*P*:',
            'GS*HC*1234567890*CMSMEDICARED*' . now()->format('Ymd') . '*' . now()->format('Hi') . '*1*X*005010X222A2',
            'ST*837*0001*005010X222A2',
            "BHT*0019*00*{$batchId}*" . now()->format('Ymd') . '*' . now()->format('Hi') . '*CH',
            'SE*4*0001',
            'GE*1*1',
            'IEA*1*000000001',
        ]) . "~\n";

        return [
            'tenant_id'           => Tenant::factory(),
            'batch_type'          => 'edr',
            'file_name'           => $batchId . '.edi',
            'file_content'        => $fileContent,
            'record_count'        => $recordCount,
            'total_charge_amount' => $totalCharge,
            'status'              => 'draft',
            'submitted_at'        => null,
            'submission_method'   => null,
            'clearinghouse_reference' => null,
            'cms_response_code'   => null,
            'created_by_user_id'  => User::factory(),
        ];
    }

    /** Batch that has been submitted to CMS. */
    public function submitted(): static
    {
        return $this->state(fn () => [
            'status'           => 'submitted',
            'submitted_at'     => now()->subHours($this->faker->numberBetween(1, 72)),
            'submission_method'=> 'direct',
        ]);
    }

    /** Batch that was rejected by CMS. */
    public function rejected(): static
    {
        return $this->state(fn () => [
            'status'            => 'rejected',
            'submitted_at'      => now()->subDays(2),
            'submission_method' => 'clearinghouse',
            'cms_response_code' => 'R:' . $this->faker->numerify('##'),
        ]);
    }

    /** Chart Review Record batch. */
    public function crr(): static
    {
        return $this->state(fn () => ['batch_type' => 'crr']);
    }
}
