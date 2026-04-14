<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShopOwnerApplicationUnderReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $ownerName,
        public string $businessName,
        public string $pendingApprovalUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your SoleSpace Shop Application Is Under Review',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.shop-owner-application-under-review',
            with: [
                'ownerName' => $this->ownerName,
                'businessName' => $this->businessName,
                'pendingApprovalUrl' => $this->pendingApprovalUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
