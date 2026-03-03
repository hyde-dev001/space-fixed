<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InventoryReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $reportData;
    public User $user;
    public string $reportType;

    /**
     * Create a new message instance.
     */
    public function __construct(array $reportData, User $user, string $reportType = 'general')
    {
        $this->reportData = $reportData;
        $this->user = $user;
        $this->reportType = $reportType;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Inventory Report - ' . now()->format('F d, Y'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.inventory-report',
            with: [
                'reportData' => $this->reportData,
                'user' => $this->user,
                'reportType' => $this->reportType,
                'generatedAt' => now()->format('F d, Y h:i A'),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
