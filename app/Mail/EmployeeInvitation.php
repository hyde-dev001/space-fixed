<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $inviteUrl;
    public $expiresAt;
    public $shopName;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, string $inviteUrl)
    {
        $this->userName = $user->name;
        $this->inviteUrl = $inviteUrl;
        $this->expiresAt = $user->invite_expires_at->format('F j, Y g:i A');
        $this->shopName = $user->shopOwner->business_name ?? 'SoleSpace';
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Welcome to {$this->shopName} - Set Up Your Account",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.employee-invitation',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
