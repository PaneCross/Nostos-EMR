<?php

// ─── SdrCreatedEvent ──────────────────────────────────────────────────────────
// Broadcast when a new SDR is submitted.
// Frontend: SDR Index page for the assigned department receives live update
// and adds the new SDR to the top of their queue without page reload.
//
// Channels:
//   - department.{tenant_id}.{assigned_dept}: assigned dept sees new request
//   - tenant.{tenant_id}: org-wide for cross-dept visibility
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\Sdr;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SdrCreatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Sdr $sdr) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("tenant.{$this->sdr->tenant_id}"),
            new Channel("department.{$this->sdr->tenant_id}.{$this->sdr->assigned_department}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'sdr.created';
    }

    public function broadcastWith(): array
    {
        return [
            'sdr_id'              => $this->sdr->id,
            'participant_id'      => $this->sdr->participant_id,
            'request_type'        => $this->sdr->request_type,
            'type_label'          => $this->sdr->typeLabel(),
            'priority'            => $this->sdr->priority,
            'assigned_department' => $this->sdr->assigned_department,
            'due_at'              => $this->sdr->due_at?->toIso8601String(),
        ];
    }
}
