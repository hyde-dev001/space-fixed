<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\HR\LeaveRequest;
use App\Models\Employee;

class LeaveRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    protected $leaveRequest;
    protected $employee;
    protected $approverInfo;

    /**
     * Create a new notification instance.
     */
    public function __construct(LeaveRequest $leaveRequest, Employee $employee, array $approverInfo)
    {
        $this->leaveRequest = $leaveRequest;
        $this->employee = $employee;
        $this->approverInfo = $approverInfo;
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
        $employeeName = $this->employee->firstName . ' ' . $this->employee->lastName;
        $leaveType = ucfirst(str_replace('_', ' ', $this->leaveRequest->leaveType));
        $startDate = date('M d, Y', strtotime($this->leaveRequest->startDate));
        $endDate = date('M d, Y', strtotime($this->leaveRequest->endDate));
        $days = $this->leaveRequest->noOfDays;
        
        $delegationNote = '';
        if ($this->approverInfo['is_delegated']) {
            $delegationNote = "Note: This approval has been delegated to you by " . 
                             ($this->approverInfo['original_approver'] ?? 'the original approver') . ".";
        }

        return (new MailMessage)
                    ->subject("Leave Request Pending Approval - {$employeeName}")
                    ->greeting("Hello {$notifiable->name},")
                    ->line("A new leave request requires your approval.")
                    ->line("**Employee:** {$employeeName}")
                    ->line("**Leave Type:** {$leaveType}")
                    ->line("**Duration:** {$startDate} to {$endDate} ({$days} day(s))")
                    ->line("**Reason:** {$this->leaveRequest->reason}")
                    ->when($delegationNote, function ($mail) use ($delegationNote) {
                        return $mail->line($delegationNote);
                    })
                    ->action('Review Leave Request', url('/erp/hr/leave-requests/' . $this->leaveRequest->id))
                    ->line('Please review and take appropriate action on this request.')
                    ->line('Thank you for your attention to this matter.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_request_submitted',
            'leave_request_id' => $this->leaveRequest->id,
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->firstName . ' ' . $this->employee->lastName,
            'leave_type' => $this->leaveRequest->leaveType,
            'start_date' => $this->leaveRequest->startDate,
            'end_date' => $this->leaveRequest->endDate,
            'days' => $this->leaveRequest->noOfDays,
            'reason' => $this->leaveRequest->reason,
            'approval_level' => $this->approverInfo['approval_level'],
            'is_delegated' => $this->approverInfo['is_delegated'],
            'action_url' => url('/erp/hr/leave-requests/' . $this->leaveRequest->id),
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
