<?php

// ─── AlertCreatedEvent ────────────────────────────────────────────────────────
// Broadcast when a new alert is created (system or manual).
// Frontend: NotificationBell subscribes to tenant.{tenant_id} channel and
// increments its unread badge count on receipt.
//
// Channels:
//   - tenant.{tenant_id}: all users in the tenant see new alert badge updates
//   - department.{tenant_id}.{dept}: per-department for targeted display
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\Alert;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AlertCreatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Alert $alert) {}

    /**
     * Broadcast on:
     *  - tenant channel (all users in org)
     *  - one channel per target department
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel("tenant.{$this->alert->tenant_id}"),
        ];

        foreach ($this->alert->target_departments ?? [] as $dept) {
            $channels[] = new Channel("department.{$this->alert->tenant_id}.{$dept}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'alert.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id'                  => $this->alert->id,
            'alert_type'          => $this->alert->alert_type,
            'severity'            => $this->alert->severity,
            'title'               => $this->alert->title,
            'message'             => $this->alert->message,
            'target_departments'  => $this->alert->target_departments,
            'participant_id'      => $this->alert->participant_id,
            'created_at'          => $this->alert->created_at?->toIso8601String(),
        ];
    }
}
