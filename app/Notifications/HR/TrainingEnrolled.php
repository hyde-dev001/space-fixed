<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrainingEnrolled extends Notification implements ShouldQueue
{
    use Queueable;

    protected $enrollment;
    protected $program;

    /**
     * Create a new notification instance.
     */
    public function __construct($enrollment, $program)
    {
        $this->enrollment = $enrollment;
        $this->program = $program;
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
            ->subject('You Have Been Enrolled in Training')
            ->line('You have been enrolled in the following training program:')
            ->line('**Program:** ' . $this->program->title)
            ->line('**Category:** ' . ucfirst(str_replace('_', ' ', $this->program->category)))
            ->line('**Delivery Method:** ' . ucfirst(str_replace('_', ' ', $this->program->delivery_method)))
            ->line('**Duration:** ' . $this->program->duration_hours . ' hours')
            ->action('View Training Details', url('/erp/hr/training'))
            ->line('Please review the training objectives and prepare accordingly.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'training_enrolled',
            'title' => 'New Training Enrollment',
            'message' => "You have been enrolled in {$this->program->title}",
            'enrollment_id' => $this->enrollment->id,
            'program_id' => $this->program->id,
            'program_title' => $this->program->title,
            'category' => $this->program->category,
            'delivery_method' => $this->program->delivery_method,
            'duration_hours' => $this->program->duration_hours,
            'is_mandatory' => $this->program->is_mandatory,
            'action_url' => '/erp/hr/training',
            'priority' => $this->program->is_mandatory ? 'high' : 'medium',
        ];
    }
}
