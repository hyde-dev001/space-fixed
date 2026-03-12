<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PerformanceReviewDue extends Notification implements ShouldQueue
{
    use Queueable;

    protected $performanceReview;
    protected $reviewCycle;
    protected $daysUntilDue;

    /**
     * Create a new notification instance.
     */
    public function __construct($performanceReview, $reviewCycle = null, $daysUntilDue = null)
    {
        $this->performanceReview = $performanceReview;
        $this->reviewCycle = $reviewCycle;
        $this->daysUntilDue = $daysUntilDue;
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
            ->subject('Performance Review Due')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('You have a performance review that requires your attention.');

        if ($this->reviewCycle) {
            $message->line('Review Cycle: ' . $this->reviewCycle);
        }

        $message->line('Review Period: ' . $this->performanceReview->review_period_start->format('M d') . ' - ' . $this->performanceReview->review_period_end->format('M d, Y'));

        if ($this->daysUntilDue) {
            $message->line('Days Until Due: ' . $this->daysUntilDue);
        }

        return $message
            ->action('Complete Review', url('/erp/hr/performance'))
            ->line('Please complete this review at your earliest convenience.');
    }

    /**
     * Get the array representation of the notification (for database).
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'performance_review_due',
            'title' => 'Performance Review Due',
            'message' => 'Performance review for ' . $this->performanceReview->review_period_start->format('M Y') . ' requires your attention',
            'performance_review_id' => $this->performanceReview->id,
            'employee_id' => $this->performanceReview->employee_id,
            'review_period_start' => $this->performanceReview->review_period_start->format('Y-m-d'),
            'review_period_end' => $this->performanceReview->review_period_end->format('Y-m-d'),
            'review_cycle' => $this->reviewCycle,
            'days_until_due' => $this->daysUntilDue,
            'action_url' => '/erp/hr/performance',
            'priority' => $this->daysUntilDue && $this->daysUntilDue <= 3 ? 'high' : 'medium',
        ];
    }
}
