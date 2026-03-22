<?php

namespace Database\Factories;

use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    public function definition(): array
    {
        return [
            'channel_id'     => ChatChannel::factory(),
            'sender_user_id' => User::factory(),
            'message_text'   => $this->faker->sentence(),
            'priority'       => 'standard',
            'sent_at'        => now(),
            'edited_at'      => null,
        ];
    }

    /** Urgent priority message. */
    public function urgent(): static
    {
        return $this->state(['priority' => 'urgent']);
    }

    /** Soft-deleted message (shows as "This message was deleted"). */
    public function deleted(): static
    {
        return $this->state(['deleted_at' => now()]);
    }
}
