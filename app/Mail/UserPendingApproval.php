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

class UserPendingApproval extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $approveUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New registration awaiting approval: {$this->user->email}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.user-pending-approval',
            with: [
                'title' => 'New registration awaiting approval',
                'previewText' => "{$this->user->email} signed up and is waiting for approval",
                'userName' => $this->user->name,
                'userEmail' => $this->user->email,
                'url' => $this->approveUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
