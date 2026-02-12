<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\HR\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;

class CheckDocumentExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hr:check-document-expiry {--shop-owner-id= : Specific shop owner ID to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for expiring documents and send notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting document expiry check...');

        // Get shop owner filter if specified
        $shopOwnerId = $this->option('shop-owner-id');

        // Step 1: Mark expired documents
        $this->markExpiredDocuments($shopOwnerId);

        // Step 2: Send reminders for expiring documents
        $this->sendExpiryReminders($shopOwnerId);

        $this->info('Document expiry check completed successfully!');
        
        return Command::SUCCESS;
    }

    /**
     * Mark documents as expired if their expiry date has passed.
     */
    protected function markExpiredDocuments($shopOwnerId = null)
    {
        $query = EmployeeDocument::whereNotNull('expiry_date')
            ->where('expiry_date', '<', Carbon::now())
            ->whereIn('status', ['verified', 'pending']);

        if ($shopOwnerId) {
            $query->forShopOwner($shopOwnerId);
        }

        $expiredDocuments = $query->get();

        $count = 0;
        foreach ($expiredDocuments as $document) {
            if ($document->markAsExpired()) {
                $count++;
                
                // TODO: Send expiry notification to HR and employee
                $this->notifyDocumentExpired($document);
            }
        }

        $this->info("Marked {$count} documents as expired.");
    }

    /**
     * Send reminders for documents expiring soon.
     */
    protected function sendExpiryReminders($shopOwnerId = null)
    {
        $query = EmployeeDocument::whereNotNull('expiry_date')
            ->where('requires_renewal', true)
            ->whereIn('status', ['verified', 'pending']);

        if ($shopOwnerId) {
            $query->forShopOwner($shopOwnerId);
        }

        $documents = $query->get();

        $count = 0;
        foreach ($documents as $document) {
            if ($document->needs_reminder) {
                $this->sendExpiryReminder($document);
                $document->recordReminderSent();
                $count++;
            }
        }

        $this->info("Sent {$count} expiry reminder notifications.");
    }

    /**
     * Send expiry reminder notification.
     */
    protected function sendExpiryReminder($document)
    {
        $daysUntilExpiry = $document->days_until_expiry;
        
        $this->line("  Sending reminder for {$document->document_type} (Employee: {$document->employee->fullName}, Expires in: {$daysUntilExpiry} days)");

        // TODO: Implement actual email/notification sending
        // Example:
        // $hrUsers = User::where('shop_owner_id', $document->shop_owner_id)
        //     ->where('role', 'HR')
        //     ->get();
        //
        // foreach ($hrUsers as $hrUser) {
        //     Notification::send($hrUser, new DocumentExpiryReminder($document));
        // }
        //
        // // Also notify the employee
        // if ($document->employee->email) {
        //     Mail::to($document->employee->email)->send(new DocumentExpiringMail($document));
        // }
    }

    /**
     * Send document expired notification.
     */
    protected function notifyDocumentExpired($document)
    {
        $this->line("  Document expired: {$document->document_type} (Employee: {$document->employee->fullName})");

        // TODO: Implement actual email/notification sending
        // Example:
        // $hrUsers = User::where('shop_owner_id', $document->shop_owner_id)
        //     ->where('role', 'HR')
        //     ->get();
        //
        // Notification::send($hrUsers, new DocumentExpiredNotification($document));
    }
}
