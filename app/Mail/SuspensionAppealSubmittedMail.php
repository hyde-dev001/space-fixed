<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SuspensionAppealSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $accountName,
        public string $accountTypeLabel,
        public string $recipientEmail,
        public ?string $suspensionReason,
        public string $appealMessage,
        public string $submittedAtLabel,
        public string $reviewUrl
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Suspension Appeal Submitted - SoleSpace',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.suspension-appeal-submitted',
            with: [
                'accountName' => $this->accountName,
                'accountTypeLabel' => $this->accountTypeLabel,
                'recipientEmail' => $this->recipientEmail,
                'suspensionReason' => $this->suspensionReason,
                'appealMessage' => $this->appealMessage,
                'submittedAtLabel' => $this->submittedAtLabel,
                'reviewUrl' => $this->reviewUrl,
            ],
        );
    }
}
