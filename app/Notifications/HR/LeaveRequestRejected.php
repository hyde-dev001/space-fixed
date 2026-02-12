<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestRejected extends Notification implements ShouldQueue
{
    use Queueable;

    protected $leaveRequest;
    protected $rejector;

    /**
     * Create a new notification instance.
     */
    public function __construct($leaveRequest, $rejector)
    {
        $this->leaveRequest = $leaveRequest;
        $this->rejector = $rejector;
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
        return (new MailMessage)
            ->subject('Leave Request Rejected')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your leave request has been rejected.')
            ->line('Leave Type: ' . ucfirst($this->leaveRequest->leave_type))
            ->line('Duration: ' . $this->leaveRequest->start_date->format('M d, Y') . ' to ' . $this->leaveRequest->end_date->format('M d, Y'))
            ->line('Days: ' . $this->leaveRequest->days)
            ->line('Rejected by: ' . $this->rejector->name)
            ->line('Reason: ' . ($this->leaveRequest->rejection_reason ?? 'No reason provided'))
            ->action('View Leave Details', url('/erp/hr/self-service/leaves'))
            ->line('Please contact HR if you have any questions.');
    }

    /**
     * Get the array representation of the notification (for database).
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'leave_request_rejected',
            'title' => 'Leave Request Rejected',
            'message' => 'Your ' . $this->leaveRequest->leave_type . ' leave request for ' . $this->leaveRequest->days . ' day(s) has been rejected',
            'leave_request_id' => $this->leaveRequest->id,
            'leave_type' => $this->leaveRequest->leave_type,
            'start_date' => $this->leaveRequest->start_date->format('Y-m-d'),
            'end_date' => $this->leaveRequest->end_date->format('Y-m-d'),
            'days' => $this->leaveRequest->days,
            'rejection_reason' => $this->leaveRequest->rejection_reason ?? 'No reason provided',
            'rejected_by' => $this->rejector->name,
            'action_url' => '/erp/hr/self-service/leaves',
            'priority' => 'high',
        ];
    }
}
