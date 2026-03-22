<?php

namespace App\Events;

use App\Models\ParticipantFlag;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantFlagUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ParticipantFlag $flag) {}
}
