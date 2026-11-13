<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PrivilegedPasswordResetMail extends Mailable implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $email,
        public readonly string $rawToken,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your SoleSpace administrator password',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.privileged.password-reset',
            with: [
                'recipientName' => $this->recipientName,
                'resetUrl' => route('admin.password.reset').'#token='.rawurlencode($this->rawToken),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
