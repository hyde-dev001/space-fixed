<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SuspensionAppealDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $accountName,
        public string $accountTypeLabel,
        public string $decision,
        public ?string $reviewerNotes
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Suspension Appeal Decision - SoleSpace',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.suspension-appeal-decision',
            with: [
                'accountName' => $this->accountName,
                'accountTypeLabel' => $this->accountTypeLabel,
                'decision' => $this->decision,
                'reviewerNotes' => $this->reviewerNotes,
            ],
        );
    }
}
