<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your NostosEMR Sign-In Code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }

    public function attachments(): array
    {
        return [];
    }
}
