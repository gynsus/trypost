<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your account has been approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.user-approved',
            with: [
                'title' => 'Your account has been approved',
                'previewText' => 'You can now log in and start scheduling posts',
                'userName' => $this->user->name,
                'url' => route('login'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
