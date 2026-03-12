<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    protected $document;
    protected $daysUntilExpiry;

    /**
     * Create a new notification instance.
     */
    public function __construct($document, $daysUntilExpiry)
    {
        $this->document = $document;
        $this->daysUntilExpiry = $daysUntilExpiry;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $urgency = $this->daysUntilExpiry <= 7 ? 'urgent' : 'important';
        
        return (new MailMessage)
            ->subject('Document Expiring Soon - Action Required')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('This is an ' . $urgency . ' reminder about an expiring document.')
            ->line('Document: ' . $this->document->document_name)
            ->line('Expiry Date: ' . $this->document->expiry_date->format('M d, Y'))
            ->line('Days Until Expiry: ' . $this->daysUntilExpiry)
            ->action('Update Document', url('/erp/hr/documents'))
            ->line('Please renew this document as soon as possible to maintain compliance.');
    }

    /**
     * Get the array representation of the notification (for database).
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'document_expiring',
            'title' => 'Document Expiring Soon',
            'message' => $this->document->document_name . ' expires in ' . $this->daysUntilExpiry . ' day(s)',
            'document_id' => $this->document->id,
            'document_name' => $this->document->document_name,
            'document_type' => $this->document->document_type ?? 'Other',
            'expiry_date' => $this->document->expiry_date->format('Y-m-d'),
            'days_until_expiry' => $this->daysUntilExpiry,
            'action_url' => '/erp/hr/documents',
            'priority' => $this->daysUntilExpiry <= 7 ? 'critical' : 'high',
        ];
    }
}
