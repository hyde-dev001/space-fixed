<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SuspensionNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $accountName,
        public string $accountTypeLabel,
        public ?string $reason,
        public string $appealUrl,
        public string $expiresAtLabel
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Account Suspension Notice - SoleSpace',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.suspension-notice',
            with: [
                'accountName' => $this->accountName,
                'accountTypeLabel' => $this->accountTypeLabel,
                'reason' => $this->reason,
                'appealUrl' => $this->appealUrl,
                'expiresAtLabel' => $this->expiresAtLabel,
            ],
        );
    }
}
