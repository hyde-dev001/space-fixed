<?php

namespace App\Mail;

use App\Models\ReplenishmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReplenishmentRequestReviewedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $replenishmentRequest;

    /**
     * Create a new message instance.
     */
    public function __construct(ReplenishmentRequest $replenishmentRequest)
    {
        $this->replenishmentRequest = $replenishmentRequest;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $status = ucfirst($this->replenishmentRequest->status);
        return new Envelope(
            subject: "Replenishment Request {$status} - " . $this->replenishmentRequest->request_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.procurement.replenishment-request-reviewed',
            with: [
                'requestNumber' => $this->replenishmentRequest->request_number,
                'productName' => $this->replenishmentRequest->product_name,
                'skuCode' => $this->replenishmentRequest->sku_code,
                'quantityNeeded' => $this->replenishmentRequest->quantity_needed,
                'status' => ucfirst($this->replenishmentRequest->status),
                'reviewer' => $this->replenishmentRequest->reviewer?->name ?? 'Procurement Team',
                'reviewedDate' => $this->replenishmentRequest->reviewed_date,
                'responseNotes' => $this->replenishmentRequest->response_notes,
                'viewUrl' => url('/erp/procurement/replenishment-requests/' . $this->replenishmentRequest->id),
            ],
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
