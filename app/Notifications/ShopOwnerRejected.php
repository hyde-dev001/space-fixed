<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ShopOwnerRejected extends Notification
{
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
        $resubmitUrl = URL::temporarySignedRoute(
            'shop-owner.resubmission.form',
            now()->addDays(14),
            ['shopOwner' => $this->shopOwner->id]
        );
        $supportEmail = (string) (config('mail.from.address') ?: 'support@solespace.io');

        $message = (new MailMessage)
            ->subject('Shop Owner Application Status - ' . config('app.name'))
            ->greeting('Hello ' . $this->shopOwner->first_name . ',')
            ->line('Thank you for your interest in becoming a shop owner on SoleSpace.')
            ->line('After careful review, we regret to inform you that we are unable to approve your application for **' . $this->shopOwner->business_name . '** at this time.');

        if ($this->rejectionReason) {
            $message->line('**Reason for rejection:** ' . $this->rejectionReason);
        }

        $message->line('You can review your previous submission and resubmit your application after updating your details or replacing/additional documents.')
            ->action('Review and Resubmit Application', $resubmitUrl)
            ->line('This resubmission link expires in 14 days for security purposes.')
            ->line('If you believe this decision was made in error or if you have additional information to share, please contact support at [' . $supportEmail . '](mailto:' . $supportEmail . ').')
            ->line('We appreciate your understanding and wish you the best in your business endeavors.');

        return $message;
    }
}
