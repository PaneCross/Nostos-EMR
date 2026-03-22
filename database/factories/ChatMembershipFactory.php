<?php

namespace Database\Factories;

use App\Models\ChatChannel;
use App\Models\ChatMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChatMembershipFactory extends Factory
{
    protected $model = ChatMembership::class;

    public function definition(): array
    {
        return [
            'channel_id'   => ChatChannel::factory(),
            'user_id'      => User::factory(),
            'joined_at'    => now(),
            'last_read_at' => null,
        ];
    }

    /** Mark this membership as having read all messages. */
    public function read(): static
    {
        return $this->state(['last_read_at' => now()]);
    }
}
