<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificationIssued extends Notification implements ShouldQueue
{
    use Queueable;

    protected $certification;
    protected $program;

    /**
     * Create a new notification instance.
     */
    public function __construct($certification, $program)
    {
        $this->certification = $certification;
        $this->program = $program;
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
        $message = (new MailMessage)
            ->subject('Certificate Issued')
            ->line('Congratulations! A certificate has been issued for your completed training:')
            ->line('**Certificate:** ' . $this->certification->certificate_name)
            ->line('**Certificate Number:** ' . $this->certification->certificate_number)
            ->line('**Issue Date:** ' . $this->certification->issue_date->format('F d, Y'));

        if ($this->certification->expiry_date) {
            $message->line('**Expiry Date:** ' . $this->certification->expiry_date->format('F d, Y'));
        } else {
            $message->line('**Validity:** No expiration');
        }

        $message->action('View Certificate', url('/erp/hr/training/certifications'))
               ->line('Keep this certificate for your records.');

        return $message;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'certification_issued',
            'title' => 'Certificate Issued',
            'message' => "Certificate issued for {$this->program->title}",
            'certification_id' => $this->certification->id,
            'certificate_name' => $this->certification->certificate_name,
            'certificate_number' => $this->certification->certificate_number,
            'issue_date' => $this->certification->issue_date->format('Y-m-d'),
            'expiry_date' => $this->certification->expiry_date?->format('Y-m-d'),
            'action_url' => '/erp/hr/training/certifications',
            'priority' => 'medium',
        ];
    }
}
