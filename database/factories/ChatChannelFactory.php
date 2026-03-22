<?php

namespace Database\Factories;

use App\Models\ChatChannel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChatChannelFactory extends Factory
{
    protected $model = ChatChannel::class;

    public function definition(): array
    {
        return [
            'tenant_id'          => Tenant::factory(),
            'channel_type'       => $this->faker->randomElement(['department', 'broadcast']),
            'name'               => $this->faker->words(3, true),
            'participant_id'     => null,
            'created_by_user_id' => User::factory(),
            'is_active'          => true,
        ];
    }

    /** DM channel between two users (name is null). */
    public function direct(): static
    {
        return $this->state(['channel_type' => 'direct', 'name' => null]);
    }

    /** Participant IDT channel. */
    public function participantIdt(): static
    {
        return $this->state(['channel_type' => 'participant_idt']);
    }

    /** Org-wide broadcast channel. */
    public function broadcast(): static
    {
        return $this->state(['channel_type' => 'broadcast', 'name' => 'All Staff']);
    }

    /** Department channel. */
    public function department(string $dept): static
    {
        return $this->state([
            'channel_type' => 'department',
            'name'         => ucwords(str_replace('_', ' ', $dept)),
        ]);
    }
}
