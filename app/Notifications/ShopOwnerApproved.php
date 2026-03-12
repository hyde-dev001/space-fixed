<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ShopOwnerApproved extends Notification // implements ShouldQueue - Disabled for immediate sending
{
    // use Queueable; - Not needed when not queued

    protected $shopOwner;
    protected $token;

    /**
     * Create a new notification instance.
     */
    public function __construct($shopOwner, $token)
    {
        $this->shopOwner = $shopOwner;
        $this->token = $token;
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
        $setupUrl = route('shop-owner.password.setup', [
            'token' => $this->token,
            'email' => $this->shopOwner->email
        ]);

        return (new MailMessage)
            ->subject('🎉 Your Shop Owner Application has been Approved!')
            ->greeting('Congratulations, ' . $this->shopOwner->first_name . '!')
            ->line('We\'re excited to inform you that your shop owner application for **' . $this->shopOwner->business_name . '** has been approved!')
            ->line('You\'re now one step away from accessing your shop dashboard.')
            ->line('**Next Step:** Set up your password to complete your registration.')
            ->action('Set Up Your Password', $setupUrl)
            ->line('This link will expire in 48 hours for security reasons.')
            ->line('Once you\'ve set up your password, you\'ll be able to:')
            ->line('• Manage your shop profile and products')
            ->line('• Track orders and sales')
            ->line('• Communicate with customers')
            ->line('• Access analytics and reports')
            ->line('If you have any questions, please don\'t hesitate to contact our support team.')
            ->salutation('Welcome to SoleSpace!');
    }
}
