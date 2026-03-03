<?php

namespace App\Mail;

use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $purchaseRequest;

    /**
     * Create a new message instance.
     */
    public function __construct(PurchaseRequest $purchaseRequest)
    {
        $this->purchaseRequest = $purchaseRequest;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Purchase Request for Approval - ' . $this->purchaseRequest->pr_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.procurement.purchase-request-submitted',
            with: [
                'prNumber' => $this->purchaseRequest->pr_number,
                'productName' => $this->purchaseRequest->product_name,
                'quantity' => $this->purchaseRequest->quantity,
                'unitCost' => $this->purchaseRequest->unit_cost,
                'totalCost' => $this->purchaseRequest->total_cost,
                'priority' => ucfirst($this->purchaseRequest->priority),
                'justification' => $this->purchaseRequest->justification,
                'requester' => $this->purchaseRequest->requester?->name ?? 'Unknown',
                'requestedDate' => $this->purchaseRequest->requested_date,
                'supplier' => $this->purchaseRequest->supplier?->name ?? 'TBD',
                'viewUrl' => url('/erp/procurement/purchase-requests/' . $this->purchaseRequest->id),
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
