<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShopOwnerRejected extends Notification implements ShouldQueue
{
    use Queueable;

    protected $shopOwner;
    protected $rejectionReason;

    /**
     * Create a new notification instance.
     */
    public function __construct($shopOwner, $rejectionReason = null)
    {
        $this->shopOwner = $shopOwner;
        $this->rejectionReason = $rejectionReason;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Shop Owner Application Status - ' . config('app.name'))
            ->greeting('Hello ' . $this->shopOwner->first_name . ',')
            ->line('Thank you for your interest in becoming a shop owner on SoleSpace.')
            ->line('After careful review, we regret to inform you that we are unable to approve your application for **' . $this->shopOwner->business_name . '** at this time.');

        if ($this->rejectionReason) {
            $message->line('**Reason:** ' . $this->rejectionReason);
        }

        $message->line('If you believe this decision was made in error or if you have additional information to share, please feel free to contact our support team.')
            ->action('Contact Support', url('/contact'))
            ->line('We appreciate your understanding and wish you the best in your business endeavors.');

        return $message;
    }
}
