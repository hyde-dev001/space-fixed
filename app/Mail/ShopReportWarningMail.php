<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShopReportWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $accountName,
        public int $reportCount,
        public string $primaryReason,
        public ?string $adminNotes,
        public string $reviewedAtLabel
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Account Warning Notice - SoleSpace',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.shop-report-warning',
            with: [
                'accountName' => $this->accountName,
                'reportCount' => $this->reportCount,
                'primaryReason' => $this->primaryReason,
                'adminNotes' => $this->adminNotes,
                'reviewedAtLabel' => $this->reviewedAtLabel,
            ],
        );
    }
}
