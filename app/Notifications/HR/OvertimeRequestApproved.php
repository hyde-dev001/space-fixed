<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OvertimeRequestApproved extends Notification implements ShouldQueue
{
    use Queueable;

    protected $overtimeRequest;
    protected $approver;

    /**
     * Create a new notification instance.
     */
    public function __construct($overtimeRequest, $approver)
    {
        $this->overtimeRequest = $overtimeRequest;
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
            ->subject('Overtime Request Approved')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your overtime request has been approved!')
            ->line('Date: ' . $this->overtimeRequest->overtime_date->format('M d, Y'))
            ->line('Hours: ' . $this->overtimeRequest->hours . ' hour(s)')
            ->line('Reason: ' . $this->overtimeRequest->reason)
            ->line('Approved by: ' . $this->approver->name)
            ->action('View Attendance', url('/erp/hr/self-service/attendance'))
            ->line('Your overtime hours will be reflected in your attendance records.');
    }

    /**
     * Get the array representation of the notification (for database).
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'overtime_request_approved',
            'title' => 'Overtime Request Approved',
            'message' => 'Your overtime request for ' . $this->overtimeRequest->hours . ' hour(s) on ' . $this->overtimeRequest->overtime_date->format('M d, Y') . ' has been approved',
            'overtime_request_id' => $this->overtimeRequest->id,
            'overtime_date' => $this->overtimeRequest->overtime_date->format('Y-m-d'),
            'hours' => $this->overtimeRequest->hours,
            'reason' => $this->overtimeRequest->reason,
            'approved_by' => $this->approver->name,
            'action_url' => '/erp/hr/self-service/attendance',
            'priority' => 'medium',
        ];
    }
}
