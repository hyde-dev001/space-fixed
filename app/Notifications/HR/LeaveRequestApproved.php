<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestApproved extends Notification implements ShouldQueue
{
    use Queueable;

    protected $leaveRequest;
    protected $approver;

    /**
     * Create a new notification instance.
     */
    public function __construct($leaveRequest, $approver)
    {
        $this->leaveRequest = $leaveRequest;
        $this->approver = $approver;
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
            ->subject('Leave Request Approved')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your leave request has been approved!')
            ->line('Leave Type: ' . ucfirst($this->leaveRequest->leave_type))
            ->line('Duration: ' . $this->leaveRequest->start_date->format('M d, Y') . ' to ' . $this->leaveRequest->end_date->format('M d, Y'))
            ->line('Days: ' . $this->leaveRequest->days)
            ->line('Approved by: ' . $this->approver->name)
            ->action('View Leave Details', url('/erp/hr/self-service/leaves'))
            ->line('Enjoy your time off!');
    }

    /**
     * Get the array representation of the notification (for database).
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'leave_request_approved',
            'title' => 'Leave Request Approved',
            'message' => 'Your ' . $this->leaveRequest->leave_type . ' leave request for ' . $this->leaveRequest->days . ' day(s) has been approved',
            'leave_request_id' => $this->leaveRequest->id,
            'leave_type' => $this->leaveRequest->leave_type,
            'start_date' => $this->leaveRequest->start_date->format('Y-m-d'),
            'end_date' => $this->leaveRequest->end_date->format('Y-m-d'),
            'days' => $this->leaveRequest->days,
            'approved_by' => $this->approver->name,
            'action_url' => '/erp/hr/self-service/leaves',
            'priority' => 'medium',
        ];
    }
}
