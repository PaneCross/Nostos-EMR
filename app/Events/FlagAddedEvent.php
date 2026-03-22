<?php

// ─── FlagAddedEvent ───────────────────────────────────────────────────────────
// Broadcast when a new participant flag is created or updated.
// Frontend: refresh the Flags tab for active chart viewers; critical flags also
// trigger the life-threatening allergy banner refresh.
//
// Channels:
//   - tenant.{tenant_id}: org-wide for dashboard awareness
//   - participant.{participant_id}: chart viewers receive live flag update
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\ParticipantFlag;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FlagAddedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ParticipantFlag $flag) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("tenant.{$this->flag->tenant_id}"),
            new Channel("participant.{$this->flag->participant_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'flag.added';
    }

    public function broadcastWith(): array
    {
        return [
            'participant_id' => $this->flag->participant_id,
            'flag_type'      => $this->flag->flag_type,
            'severity'       => $this->flag->severity,
            'is_active'      => $this->flag->is_active,
        ];
    }
}
