<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMailtrapEmail extends Command
{
    protected $signature = 'test:mailtrap {email?}';
    protected $description = 'Test outbound email sending with current mailer configuration';

    public function handle()
    {
        $email = $this->argument('email') ?? 'test@example.com';
        
        $this->info('Sending test email to: ' . $email);
        $this->info('Using mailer: ' . config('mail.default'));

        try {
            Mail::raw('This is a test email from SoleSpace! If you received this, your email setup is working correctly. 🎉', function ($message) use ($email) {
                $message->to($email)
                        ->subject('Test Email - SoleSpace Mail Setup');
            });

            $this->info('✓ Email sent successfully!');
            $this->info('');
            $this->info('Next steps:');
            $this->info('1. Check recipient inbox (and Spam/Junk folder) for the email');
            $this->info('2. If you see it, your setup is complete!');
        } catch (\Exception $e) {
            $this->error('✗ Failed to send email');
            $this->error('Error: ' . $e->getMessage());
            $this->info('');
            $this->info('Troubleshooting:');
            $this->info('1. Check your .env file has correct SMTP credentials');
            $this->info('2. Run: php artisan config:clear');
            $this->info('3. Make sure MAIL_MAILER=smtp in .env');
        }
    }
}
