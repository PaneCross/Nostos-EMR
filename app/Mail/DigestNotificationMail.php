<?php

// ─── DigestNotificationMail ───────────────────────────────────────────────────
// Sent by DigestNotificationJob every 2 hours for users who prefer 'email_digest'.
//
// HIPAA COMPLIANCE: Zero PHI in subject or body.
// Subject: "You have {N} new notifications — NostosEMR"
// Body:    Generic prompt to log in. No alert types, patient names, or clinical data.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DigestNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly int  $count,
    ) {}

    public function envelope(): Envelope
    {
        $plural = $this->count === 1 ? 'notification' : 'notifications';
        return new Envelope(
            subject: "You have {$this->count} new {$plural} — NostosEMR"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.digest_notification');
    }

    public function attachments(): array
    {
        return [];
    }
}
