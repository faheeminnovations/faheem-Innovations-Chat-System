<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $userEmail,
        public string $temporaryPassword,
        public ?string $loginUrl = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to Faheem Innovations Chat',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-invite',
            with: [
                'loginUrl' => $this->loginUrl ?: url('/login'),
            ],
        );
    }
}
