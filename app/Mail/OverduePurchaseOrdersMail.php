<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class OverduePurchaseOrdersMail extends Mailable
{
    use Queueable, SerializesModels;

    public $overduePOs;

    /**
     * Create a new message instance.
     */
    public function __construct(Collection $overduePOs)
    {
        $this->overduePOs = $overduePOs;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Overdue Purchase Orders Alert - ' . $this->overduePOs->count() . ' Orders',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.procurement.overdue-purchase-orders',
            with: [
                'overdueCount' => $this->overduePOs->count(),
                'purchaseOrders' => $this->overduePOs,
                'viewUrl' => url('/erp/procurement/purchase-orders?overdue=1'),
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
