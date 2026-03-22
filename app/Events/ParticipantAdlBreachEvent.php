<?php

// ─── ParticipantAdlBreachEvent ────────────────────────────────────────────────
// Broadcast when an ADL record breaches a configured threshold.
// Frontend: if user is viewing the ADL tab, trigger a refresh; bell badge
// increments with warning/critical alert from primary_care + social_work.
//
// Channels:
//   - participant.{participant_id}: ADL tab refresh for chart viewers
//   - department.{tenant_id}.primary_care: alert the care team
//   - department.{tenant_id}.social_work: alert social work
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\AdlRecord;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantAdlBreachEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly AdlRecord $record) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("participant.{$this->record->participant_id}"),
            new Channel("department.{$this->record->tenant_id}.primary_care"),
            new Channel("department.{$this->record->tenant_id}.social_work"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'adl.breach';
    }

    public function broadcastWith(): array
    {
        return [
            'participant_id'   => $this->record->participant_id,
            'adl_category'     => $this->record->adl_category,
            'new_level'        => $this->record->independence_level,
            'level_label'      => $this->record->levelLabel(),
            'recorded_at'      => $this->record->recorded_at?->toIso8601String(),
        ];
    }
}
