<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OvertimeRequestRejected extends Notification implements ShouldQueue
{
    use Queueable;

    protected $overtimeRequest;
    protected $rejector;
    protected string $reason;

    /**
     * Create a new notification instance.
     */
    public function __construct($overtimeRequest, $rejector, string $reason)
    {
        $this->overtimeRequest = $overtimeRequest;
        $this->rejector = $rejector;
        $this->reason = $reason;
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
            ->subject('Overtime Request Rejected')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your overtime request has been rejected.')
            ->line('Date: ' . $this->overtimeRequest->overtime_date->format('M d, Y'))
            ->line('Hours: ' . $this->overtimeRequest->hours . ' hour(s)')
            ->line('Reason: ' . $this->reason)
            ->line('Rejected by: ' . $this->rejector->name)
            ->action('View Attendance', url('/erp/hr/self-service/attendance'))
            ->line('Please contact HR/Manager if you need clarification.');
    }

    /**
     * Get the array representation of the notification (for database).
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'overtime_request_rejected',
            'title' => 'Overtime Request Rejected',
            'message' => 'Your overtime request for ' . $this->overtimeRequest->hours . ' hour(s) on ' . $this->overtimeRequest->overtime_date->format('M d, Y') . ' has been rejected',
            'overtime_request_id' => $this->overtimeRequest->id,
            'overtime_date' => $this->overtimeRequest->overtime_date->format('Y-m-d'),
            'hours' => $this->overtimeRequest->hours,
            'rejection_reason' => $this->reason,
            'rejected_by' => $this->rejector->name,
            'action_url' => '/erp/hr/self-service/attendance',
            'priority' => 'high',
        ];
    }
}
