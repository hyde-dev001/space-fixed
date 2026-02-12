<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrainingCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    protected $enrollment;
    protected $program;
    protected $passed;

    /**
     * Create a new notification instance.
     */
    public function __construct($enrollment, $program, $passed)
    {
        $this->enrollment = $enrollment;
        $this->program = $program;
        $this->passed = $passed;
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
            ->subject('Training Completed')
            ->line('Your training has been marked as completed:')
            ->line('**Program:** ' . $this->program->title)
            ->line('**Completion Date:** ' . $this->enrollment->completion_date->format('F d, Y'))
            ->line('**Status:** ' . ($this->passed ? '✓ Passed' : '✗ Not Passed'));

        if ($this->enrollment->assessment_score) {
            $message->line('**Assessment Score:** ' . $this->enrollment->assessment_score . '%');
        }

        if ($this->passed && $this->program->issues_certificate) {
            $message->line('A certificate has been issued for this training.')
                   ->action('View Certificate', url('/erp/hr/training/certifications'));
        } else {
            $message->action('View Training Details', url('/erp/hr/training'));
        }

        if ($this->enrollment->completion_notes) {
            $message->line('**Notes:** ' . $this->enrollment->completion_notes);
        }

        $message->line('Thank you for completing this training program.');

        return $message;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'training_completed',
            'title' => 'Training Completed',
            'message' => "You have completed {$this->program->title}" . ($this->passed ? ' and passed' : ''),
            'enrollment_id' => $this->enrollment->id,
            'program_id' => $this->program->id,
            'program_title' => $this->program->title,
            'completion_date' => $this->enrollment->completion_date->format('Y-m-d'),
            'passed' => $this->passed,
            'assessment_score' => $this->enrollment->assessment_score,
            'issues_certificate' => $this->program->issues_certificate,
            'action_url' => $this->passed && $this->program->issues_certificate ? '/erp/hr/training/certifications' : '/erp/hr/training',
            'priority' => 'medium',
        ];
    }
}
