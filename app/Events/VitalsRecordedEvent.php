<?php

// ─── VitalsRecordedEvent ──────────────────────────────────────────────────────
// Broadcast when a new vital signs record is created for a participant.
// Frontend: if user is viewing the participant's Vitals tab, trigger a refresh
// of the vitals trend charts without full page reload.
//
// Channels:
//   - participant.{participant_id}: viewers of this chart receive live update
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\Vital;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VitalsRecordedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Vital $vital) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("participant.{$this->vital->participant_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'vitals.recorded';
    }

    public function broadcastWith(): array
    {
        return [
            'participant_id' => $this->vital->participant_id,
            'recorded_by'    => $this->vital->recordedBy
                ? $this->vital->recordedBy->first_name . ' ' . $this->vital->recordedBy->last_name
                : null,
            'recorded_at'    => $this->vital->recorded_at?->toIso8601String(),
        ];
    }
}
