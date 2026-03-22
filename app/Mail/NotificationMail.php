<?php

// ─── NotificationMail ─────────────────────────────────────────────────────────
// Sent immediately when a user has 'email_immediate' preference for an alert type.
//
// HIPAA COMPLIANCE: Zero PHI in subject or body.
// Subject: "You have a new notification — NostosEMR"
// Body:    Generic prompt to log in. No alert type, patient name, or clinical data.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have a new notification — NostosEMR');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notification');
    }

    public function attachments(): array
    {
        return [];
    }
}
