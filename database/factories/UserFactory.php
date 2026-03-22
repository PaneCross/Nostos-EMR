<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    // PACE-appropriate first names for demo users
    private const FIRST_NAMES = [
        'Margaret', 'Eleanor', 'Patricia', 'Dorothy', 'Helen',
        'Barbara', 'Ruth', 'Gloria', 'Shirley', 'Norma',
        'Carlos', 'James', 'Robert', 'William', 'Harold',
        'George', 'Frank', 'Richard', 'Raymond', 'Walter',
        'Linda', 'Diane', 'Susan', 'Karen', 'Nancy',
        'Michael', 'David', 'Thomas', 'Charles', 'Joseph',
    ];

    public function definition(): array
    {
        $department = $this->faker->randomElement([
            'primary_care', 'therapies', 'social_work', 'behavioral_health',
            'dietary', 'activities', 'home_care', 'transportation',
            'pharmacy', 'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
        ]);

        $firstName = $this->faker->randomElement(self::FIRST_NAMES);

        return [
            'tenant_id'             => Tenant::factory(),
            'site_id'               => null,
            'first_name'            => $firstName,
            'last_name'             => 'Demo',
            'email'                 => strtolower($firstName) . '.' . $department . '.' . $this->faker->unique()->randomNumber(6, true) . '@sunrisepace-demo.test',
            'department'            => $department,
            'role'                  => 'standard',
            'is_active'             => true,
            'last_login_at'         => null,
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'provisioned_at'        => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => 'admin']);
    }

    public function forDepartment(string $department): static
    {
        return $this->state(['department' => $department]);
    }
}
