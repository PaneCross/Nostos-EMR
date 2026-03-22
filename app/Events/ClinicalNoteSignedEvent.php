<?php

// ─── ClinicalNoteSignedEvent ──────────────────────────────────────────────────
// Broadcast when a clinical note is signed (status changes to 'signed').
// Frontend: if user is viewing the affected participant's chart tab, trigger
// a data refresh of that tab without full page reload.
//
// Channels:
//   - tenant.{tenant_id}: org-wide awareness
//   - participant.{participant_id}: chart tab refresh for active viewers
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\ClinicalNote;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClinicalNoteSignedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ClinicalNote $note) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("tenant.{$this->note->tenant_id}"),
            new Channel("participant.{$this->note->participant_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'note.signed';
    }

    public function broadcastWith(): array
    {
        return [
            'participant_id' => $this->note->participant_id,
            'note_id'        => $this->note->id,
            'note_type'      => $this->note->note_type,
            'authored_by'    => $this->note->author
                ? $this->note->author->first_name . ' ' . $this->note->author->last_name
                : null,
            'department'     => $this->note->author?->department,
            'summary'        => mb_substr($this->note->content['subjective'] ?? $this->note->content['narrative'] ?? '', 0, 120),
            'signed_at'      => now()->toIso8601String(),
        ];
    }
}
