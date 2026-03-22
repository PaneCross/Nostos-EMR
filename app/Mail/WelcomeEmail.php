<?php

// ─── WelcomeEmail ─────────────────────────────────────────────────────────────
// Sent by IT Admin when provisioning a new user account.
// Tells the new user their email address and instructs them to use OTP sign-in.
// Delivered via Mailpit in dev (http://localhost:8025).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to NostosEMR — Your Account Has Been Created');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome');
    }

    public function attachments(): array
    {
        return [];
    }
}
