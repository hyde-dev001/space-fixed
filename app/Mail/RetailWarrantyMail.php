<?php

namespace App\Mail;

use App\Models\RetailWarrantyIssuance;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// The queued delivery job sends synchronously to persist transport acceptance itself.
class RetailWarrantyMail extends Mailable
{
    public function __construct(public readonly RetailWarrantyIssuance $issuance) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your SoleSpace Product Warranty — Order #'.($this->issuance->order_snapshot['number'] ?? $this->issuance->order_id));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.retail-warranty');
    }

    public function attachments(): array
    {
        return [Attachment::fromStorageDisk('local', $this->issuance->certificate_path)
            ->as($this->issuance->warranty_number.'.pdf')->withMime('application/pdf')];
    }
}
