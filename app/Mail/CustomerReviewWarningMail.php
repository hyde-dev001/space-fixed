<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerReviewWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $customerName,
        public int $warningStrike,
        public int $warningLimit,
        public string $reasonLabel,
        public ?string $adminNotes,
        public string $reviewedAtLabel
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Customer Account Warning - SoleSpace',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-review-warning',
            with: [
                'customerName' => $this->customerName,
                'warningStrike' => $this->warningStrike,
                'warningLimit' => $this->warningLimit,
                'reasonLabel' => $this->reasonLabel,
                'adminNotes' => $this->adminNotes,
                'reviewedAtLabel' => $this->reviewedAtLabel,
            ],
        );
    }
}
