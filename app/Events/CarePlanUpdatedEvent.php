<?php

// ─── CarePlanUpdatedEvent ─────────────────────────────────────────────────────
// Broadcast when a care plan goal is created, updated, or the plan status changes.
// Frontend: refresh the CarePlan tab for active chart viewers.
//
// Channels:
//   - tenant.{tenant_id}: IDT dashboard awareness (upcoming reviews)
//   - participant.{participant_id}: chart viewers receive live goal update
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\CarePlan;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CarePlanUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly CarePlan $carePlan,
        public readonly string   $domain,
        public readonly string   $updatedByDepartment,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("tenant.{$this->carePlan->tenant_id}"),
            new Channel("participant.{$this->carePlan->participant_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'care_plan.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'participant_id'        => $this->carePlan->participant_id,
            'care_plan_id'          => $this->carePlan->id,
            'domain'                => $this->domain,
            'status'                => $this->carePlan->status,
            'updated_by_department' => $this->updatedByDepartment,
        ];
    }
}
