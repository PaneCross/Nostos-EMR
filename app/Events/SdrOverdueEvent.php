<?php

// ─── SdrOverdueEvent ──────────────────────────────────────────────────────────
// Broadcast when an SDR is escalated as overdue by SdrDeadlineEnforcementJob.
// Frontend: SDR Index shows the row in red; bell badge increments with critical alert.
//
// Channels:
//   - department.{tenant_id}.{assigned_dept}: assigned dept escalation notice
//   - department.{tenant_id}.qa_compliance: QA always receives overdue escalations
//   - tenant.{tenant_id}: org-wide overdue signal
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\Sdr;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SdrOverdueEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Sdr $sdr) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("tenant.{$this->sdr->tenant_id}"),
            new Channel("department.{$this->sdr->tenant_id}.{$this->sdr->assigned_department}"),
            new Channel("department.{$this->sdr->tenant_id}.qa_compliance"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'sdr.overdue';
    }

    public function broadcastWith(): array
    {
        return [
            'sdr_id'              => $this->sdr->id,
            'participant_id'      => $this->sdr->participant_id,
            'request_type'        => $this->sdr->request_type,
            'type_label'          => $this->sdr->typeLabel(),
            'assigned_department' => $this->sdr->assigned_department,
            'due_at'              => $this->sdr->due_at?->toIso8601String(),
            'hours_overdue'       => abs($this->sdr->hoursRemaining()),
            'escalated_at'        => $this->sdr->escalated_at?->toIso8601String(),
        ];
    }
}
