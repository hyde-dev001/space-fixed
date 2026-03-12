<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class OverduePurchaseOrdersNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $overduePOs;

    /**
     * Create a new notification instance.
     */
    public function __construct(Collection $overduePOs)
    {
        $this->overduePOs = $overduePOs;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
                    ->subject('Overdue Purchase Orders Alert')
                    ->line('You have **' . $this->overduePOs->count() . '** overdue purchase orders requiring attention.')
                    ->line('');

        foreach ($this->overduePOs->take(5) as $po) {
            $daysOverdue = now()->diffInDays($po->expected_delivery_date);
            $message->line("**{$po->po_number}** - {$po->product_name} ({$daysOverdue} days overdue)");
        }

        if ($this->overduePOs->count() > 5) {
            $message->line('...and ' . ($this->overduePOs->count() - 5) . ' more.');
        }

        return $message->action('View All Purchase Orders', url('/erp/procurement/purchase-orders?overdue=1'))
                      ->line('Please follow up with suppliers to ensure timely delivery.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'overdue_count' => $this->overduePOs->count(),
            'po_numbers' => $this->overduePOs->pluck('po_number')->toArray(),
            'message' => $this->overduePOs->count() . ' overdue purchase orders',
        ];
    }
}
