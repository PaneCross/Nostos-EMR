<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\SignificantChangeEvent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class SignificantChangeEventFactory extends Factory
{
    protected $model = SignificantChangeEvent::class;

    public function definition(): array
    {
        $triggerDate = $this->faker->dateTimeBetween('-60 days', 'now');

        return [
            'tenant_id'           => 1,  // overridden in tests
            'participant_id'      => Participant::factory(),
            'trigger_type'        => $this->faker->randomElement(SignificantChangeEvent::TRIGGER_TYPES),
            'trigger_date'        => $triggerDate->format('Y-m-d'),
            'trigger_source'      => $this->faker->randomElement(SignificantChangeEvent::TRIGGER_SOURCES),
            'source_incident_id'  => null,
            'source_integration_log_id' => null,
            'idt_review_due_date' => date('Y-m-d', strtotime($triggerDate->format('Y-m-d') . ' +30 days')),
            'status'              => 'pending',
            'review_completed_at' => null,
            'review_completed_by_user_id' => null,
            'notes'               => null,
            'created_by_user_id'  => null,
        ];
    }

    /** Simulate an overdue event (trigger_date 60 days ago, no completion). */
    public function overdue(): static
    {
        return $this->state(function () {
            $triggerDate = now()->subDays(60)->toDateString();
            return [
                'trigger_date'        => $triggerDate,
                'idt_review_due_date' => now()->subDays(30)->toDateString(),
                'status'              => 'pending',
            ];
        });
    }

    /** Simulate a completed event. */
    public function completed(): static
    {
        return $this->state(function () {
            return [
                'status'              => 'completed',
                'review_completed_at' => now()->subDays(5),
            ];
        });
    }

    /** Simulate a hospitalization trigger from ADT connector. */
    public function fromHospitalization(): static
    {
        return $this->state(fn () => [
            'trigger_type'   => 'hospitalization',
            'trigger_source' => 'adt_connector',
        ]);
    }
}
