<?php

namespace App\Notifications\HR;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayslipGenerated extends Notification implements ShouldQueue
{
    use Queueable;

    protected $payroll;

    /**
     * Create a new notification instance.
     */
    public function __construct($payroll)
    {
        $this->payroll = $payroll;
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
            ->subject('Your Payslip is Ready')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your payslip for ' . $this->payroll->pay_period_start->format('F Y') . ' is now available.')
            ->line('Period: ' . $this->payroll->pay_period_start->format('M d') . ' - ' . $this->payroll->pay_period_end->format('M d, Y'))
            ->line('Gross Salary: $' . number_format($this->payroll->gross_salary, 2))
            ->line('Deductions: $' . number_format($this->payroll->deductions, 2))
            ->line('Net Salary: $' . number_format($this->payroll->net_salary, 2))
            ->action('View Payslip', url('/erp/hr/self-service/payslips'))
            ->line('Thank you for your hard work!');
    }

    /**
     * Get the array representation of the notification (for database).
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'payslip_generated',
            'title' => 'New Payslip Available',
            'message' => 'Your payslip for ' . $this->payroll->pay_period_start->format('F Y') . ' is ready to view',
            'payroll_id' => $this->payroll->id,
            'period_start' => $this->payroll->pay_period_start->format('Y-m-d'),
            'period_end' => $this->payroll->pay_period_end->format('Y-m-d'),
            'gross_salary' => $this->payroll->gross_salary,
            'net_salary' => $this->payroll->net_salary,
            'deductions' => $this->payroll->deductions,
            'action_url' => '/erp/hr/self-service/payslips',
            'priority' => 'medium',
        ];
    }
}
