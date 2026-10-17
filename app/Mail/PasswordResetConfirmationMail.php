<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public ?string $userName;
    public string $resetTime;

    public function __construct(?string $userName = null, ?string $resetTime = null)
    {
        $this->userName = $userName;
        $this->resetTime = $resetTime ?? now()->toDayDateTimeString();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Security Alert: Your Password Was Reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password_reset_confirmation',
            with: [
                'userName' => $this->userName,
                'resetTime' => $this->resetTime,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
