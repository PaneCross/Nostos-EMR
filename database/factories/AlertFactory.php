<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class AlertFactory extends Factory
{
    protected $model = Alert::class;

    private static array $alertTypes = [
        ['source' => 'adl',        'type' => 'adl_decline',        'severity' => 'warning',  'depts' => ['primary_care', 'social_work']],
        ['source' => 'assessment',  'type' => 'assessment_overdue',  'severity' => 'warning',  'depts' => ['primary_care']],
        ['source' => 'allergy',    'type' => 'allergy_critical',    'severity' => 'critical', 'depts' => ['primary_care', 'pharmacy']],
        ['source' => 'sdr',        'type' => 'sdr_overdue',        'severity' => 'critical', 'depts' => ['primary_care', 'qa_compliance']],
        ['source' => 'sdr',        'type' => 'sdr_warning_24h',    'severity' => 'info',     'depts' => ['social_work']],
        ['source' => 'manual',     'type' => 'manual',             'severity' => 'info',     'depts' => ['idt']],
    ];

    public function definition(): array
    {
        $alertDef = $this->faker->randomElement(self::$alertTypes);

        return [
            'tenant_id'           => fn () => Tenant::factory()->create()->id,
            'participant_id'      => fn (array $attrs) => Participant::factory()->create(['tenant_id' => $attrs['tenant_id']])->id,
            'source_module'       => $alertDef['source'],
            'alert_type'          => $alertDef['type'],
            'title'               => $this->faker->sentence(5),
            'message'             => $this->faker->paragraph(),
            'severity'            => $alertDef['severity'],
            'target_departments'  => $alertDef['depts'],
            'created_by_system'   => true,
            'created_by_user_id'  => null,
            'is_active'           => true,
            'acknowledged_at'     => null,
            'acknowledged_by_user_id' => null,
            'resolved_at'         => null,
        ];
    }

    /** Make a critical severity alert. */
    public function critical(): static
    {
        return $this->state(['severity' => 'critical']);
    }

    /** Make an info severity alert. */
    public function info(): static
    {
        return $this->state(['severity' => 'info']);
    }

    /** Make a manual (user-created) alert. */
    public function manual(): static
    {
        return $this->state([
            'source_module'      => 'manual',
            'alert_type'         => 'manual',
            'created_by_system'  => false,
        ]);
    }

    /** Make an already-acknowledged alert. */
    public function acknowledged(): static
    {
        return $this->state([
            'acknowledged_at'          => now()->subMinutes(10),
            'acknowledged_by_user_id'  => fn (array $attrs) => \App\Models\User::factory()->create(['tenant_id' => $attrs['tenant_id']])->id,
        ]);
    }

    /** Make a resolved (inactive) alert. */
    public function resolved(): static
    {
        return $this->state([
            'is_active'   => false,
            'resolved_at' => now()->subHour(),
        ]);
    }
}
