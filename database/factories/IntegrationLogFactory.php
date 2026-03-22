<?php

namespace Database\Factories;

use App\Models\IntegrationLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class IntegrationLogFactory extends Factory
{
    protected $model = IntegrationLog::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => Tenant::factory(),
            'connector_type' => $this->faker->randomElement(['hl7_adt', 'lab_results']),
            'direction'      => 'inbound',
            'raw_payload'    => ['message_type' => 'A01', 'patient_mrn' => 'TEST-00001'],
            'status'         => 'processed',
            'processed_at'   => now(),
            'error_message'  => null,
            'retry_count'    => 0,
        ];
    }

    /** Simulates a pending entry (job not yet dispatched/run). */
    public function pending(): static
    {
        return $this->state([
            'status'       => 'pending',
            'processed_at' => null,
        ]);
    }

    /** Simulates a failed processing attempt. */
    public function failed(string $error = 'Processing error'): static
    {
        return $this->state([
            'status'        => 'failed',
            'error_message' => $error,
            'processed_at'  => now(),
        ]);
    }

    /** Simulates a successfully processed HL7 ADT message. */
    public function hl7Adt(string $messageType = 'A01'): static
    {
        return $this->state([
            'connector_type' => 'hl7_adt',
            'raw_payload'    => [
                'message_type'   => $messageType,
                'patient_mrn'    => 'TEST-00001',
                'event_datetime' => now()->toIso8601String(),
                'facility'       => 'Sunrise General Hospital',
            ],
            'status'       => 'processed',
            'processed_at' => now(),
        ]);
    }

    /** Simulates a lab result integration entry. */
    public function labResult(bool $abnormal = false): static
    {
        return $this->state([
            'connector_type' => 'lab_results',
            'raw_payload'    => [
                'patient_mrn'   => 'TEST-00001',
                'test_code'     => 'HGB',
                'test_name'     => 'Hemoglobin',
                'value'         => $abnormal ? '7.2' : '13.5',
                'unit'          => 'g/dL',
                'abnormal_flag' => $abnormal,
                'result_date'   => now()->toDateString(),
            ],
            'status'       => 'processed',
            'processed_at' => now(),
        ]);
    }
}
