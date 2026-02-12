<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingTaskAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    protected $onboardingTask;
    protected $employee;
    protected $dueDate;

    /**
     * Create a new notification instance.
     */
    public function __construct($onboardingTask, $employee, $dueDate = null)
    {
        $this->onboardingTask = $onboardingTask;
        $this->employee = $employee;
        $this->dueDate = $dueDate;
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
            ->subject('New Onboarding Task Assigned')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('A new onboarding task has been assigned to you for ' . $this->employee->name . '.')
            ->line('Task: ' . $this->onboardingTask->task_name)
            ->line('Description: ' . $this->onboardingTask->description);

        if ($this->dueDate) {
            $message->line('Due Date: ' . $this->dueDate->format('M d, Y'));
        }

        if ($this->onboardingTask->is_mandatory) {
            $message->line('⚠️ This is a mandatory task.');
        }

        return $message
            ->action('View Onboarding Checklist', url('/erp/hr/onboarding'))
            ->line('Please complete this task as soon as possible.');
    }

    /**
     * Get the array representation of the notification (for database).
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'onboarding_task_assigned',
            'title' => 'New Onboarding Task',
            'message' => 'Onboarding task assigned: ' . $this->onboardingTask->task_name . ' for ' . $this->employee->name,
            'onboarding_task_id' => $this->onboardingTask->id,
            'task_name' => $this->onboardingTask->task_name,
            'description' => $this->onboardingTask->description,
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->name,
            'assigned_to' => $this->onboardingTask->assigned_to,
            'is_mandatory' => $this->onboardingTask->is_mandatory,
            'due_date' => $this->dueDate ? $this->dueDate->format('Y-m-d') : null,
            'action_url' => '/erp/hr/onboarding',
            'priority' => $this->onboardingTask->is_mandatory ? 'high' : 'medium',
        ];
    }
}
