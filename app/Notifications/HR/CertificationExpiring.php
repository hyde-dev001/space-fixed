<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificationExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    protected $certification;
    protected $daysUntilExpiry;

    /**
     * Create a new notification instance.
     */
    public function __construct($certification, $daysUntilExpiry)
    {
        $this->certification = $certification;
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
            ->subject('Certificate Expiring Soon - Action Required')
            ->line("This is an {$urgency} notification about your certification expiry:")
            ->line('**Certificate:** ' . $this->certification->certificate_name)
            ->line('**Certificate Number:** ' . $this->certification->certificate_number)
            ->line('**Expiry Date:** ' . $this->certification->expiry_date->format('F d, Y'))
            ->line('**Days Remaining:** ' . $this->daysUntilExpiry . ' day(s)')
            ->action('Renew Certificate', url('/erp/hr/training/certifications'))
            ->line('Please take necessary steps to renew your certification before it expires.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'certification_expiring',
            'title' => 'Certificate Expiring Soon',
            'message' => "{$this->certification->certificate_name} expires in {$this->daysUntilExpiry} day(s)",
            'certification_id' => $this->certification->id,
            'certificate_name' => $this->certification->certificate_name,
            'certificate_number' => $this->certification->certificate_number,
            'expiry_date' => $this->certification->expiry_date->format('Y-m-d'),
            'days_until_expiry' => $this->daysUntilExpiry,
            'action_url' => '/erp/hr/training/certifications',
            'priority' => $this->daysUntilExpiry <= 7 ? 'critical' : 'high',
        ];
    }
}
