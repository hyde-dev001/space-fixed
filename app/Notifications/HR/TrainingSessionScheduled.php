<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrainingSessionScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    protected $session;
    protected $program;

    /**
     * Create a new notification instance.
     */
    public function __construct($session, $program)
    {
        $this->session = $session;
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
        $daysUntil = now()->diffInDays($this->session->start_date, false);

        return (new MailMessage)
            ->subject('Training Session Scheduled')
            ->line('Your training session has been scheduled:')
            ->line('**Program:** ' . $this->program->title)
            ->line('**Session:** ' . $this->session->session_name)
            ->line('**Date:** ' . $this->session->start_date->format('F d, Y') . ($this->session->start_time ? ' at ' . $this->session->start_time : ''))
            ->line('**Location:** ' . ($this->session->location ?: 'Online'))
            ->when($this->session->online_meeting_link, function ($mail) {
                return $mail->line('**Meeting Link:** ' . $this->session->online_meeting_link);
            })
            ->action('View Training Details', url('/erp/hr/training'))
            ->line($daysUntil > 0 ? "The session starts in {$daysUntil} day(s)." : 'The session is starting soon!');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        $daysUntil = now()->diffInDays($this->session->start_date, false);

        return [
            'type' => 'training_session_scheduled',
            'title' => 'Training Session Scheduled',
            'message' => "{$this->program->title} is scheduled for {$this->session->start_date->format('M d, Y')}",
            'session_id' => $this->session->id,
            'program_id' => $this->program->id,
            'program_title' => $this->program->title,
            'session_name' => $this->session->session_name,
            'start_date' => $this->session->start_date->format('Y-m-d'),
            'start_time' => $this->session->start_time,
            'location' => $this->session->location,
            'online_meeting_link' => $this->session->online_meeting_link,
            'days_until' => $daysUntil,
            'action_url' => '/erp/hr/training',
            'priority' => $daysUntil <= 3 ? 'high' : 'medium',
        ];
    }
}
