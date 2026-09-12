<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class SupplierPaymentConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $maskedDestination */
    public function __construct(
        public string $supplierName,
        public string $poNumber,
        public string $amount,
        public string $paymentMethod,
        public string $externalTransactionReference,
        public ?CarbonInterface $externallyPaidAt,
        public array $maskedDestination,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment Confirmation - PO ' . $this->poNumber);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.supplier-payment-confirmation',
            with: [
                'supplierName' => $this->supplierName,
                'poNumber' => $this->poNumber,
                'amount' => $this->amount,
                'paymentMethod' => $this->paymentMethod,
                'externalTransactionReference' => $this->externalTransactionReference,
                'externallyPaidAt' => $this->externallyPaidAt,
                'maskedDestination' => $this->maskedDestination,
            ],
        );
    }
}
