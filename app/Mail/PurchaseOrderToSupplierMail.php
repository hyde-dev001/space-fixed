<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderToSupplierMail extends Mailable
{
    use Queueable, SerializesModels;

    public $purchaseOrder;

    /**
     * Create a new message instance.
     */
    public function __construct(PurchaseOrder $purchaseOrder)
    {
        $this->purchaseOrder = $purchaseOrder;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Purchase Order - ' . $this->purchaseOrder->po_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.procurement.purchase-order-to-supplier',
            with: [
                'poNumber' => $this->purchaseOrder->po_number,
                'productName' => $this->purchaseOrder->product_name,
                'quantity' => $this->purchaseOrder->quantity,
                'unitCost' => $this->purchaseOrder->unit_cost,
                'totalCost' => $this->purchaseOrder->total_cost,
                'paymentTerms' => $this->purchaseOrder->payment_terms,
                'expectedDeliveryDate' => $this->purchaseOrder->expected_delivery_date,
                'notes' => $this->purchaseOrder->notes,
                'orderedDate' => $this->purchaseOrder->ordered_date,
                'supplier' => $this->purchaseOrder->supplier,
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
