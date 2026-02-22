<?php

use Illuminate\Support\Facades\DB;
use App\Models\ShopOwner;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════\n";
echo "     SHOP OWNER REGISTRATION FLOW - STATUS CHECK\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Get all shop owners
$shopOwners = ShopOwner::all();

echo "Total Shop Owners: " . $shopOwners->count() . "\n\n";

if ($shopOwners->isEmpty()) {
    echo "⚠️  No shop owners found. Ready to test new registration!\n";
    echo "\n📝 STEP 1: Register a New Shop Owner\n";
    echo "   Navigate to: /shop-owner/register\n";
    echo "   Fill in all details and upload documents\n\n";
} else {
    echo "Current Shop Owners:\n";
    echo "─────────────────────────────────────────────────────────\n";
    
    foreach ($shopOwners as $owner) {
        echo "\n👤 {$owner->business_name}\n";
        echo "   Email: {$owner->email}\n";
        echo "   Type: " . ucfirst($owner->registration_type) . " | " . ucfirst($owner->business_type) . "\n";
        echo "   Status: " . $owner->status->value . "\n";
        echo "   Email Verified: " . ($owner->email_verified_at ? "✅ Yes" : "❌ No") . "\n";
        echo "   Password Set: " . ($owner->password ? "✅ Yes" : "❌ No") . "\n";
        
        // Check for pending password setup tokens
        $token = DB::table('password_reset_tokens')
            ->where('email', $owner->email)
            ->first();
        
        if ($token) {
            $hoursAgo = now()->diffInHours($token->created_at);
            $isExpired = $hoursAgo > 48;
            echo "   Password Token: " . ($isExpired ? "⏰ Expired ({$hoursAgo}h ago)" : "✅ Valid ({$hoursAgo}h ago)") . "\n";
        }
        
        echo "   ─────────────────────────────────────\n";
        
        // Provide next steps based on status
        if (!$owner->email_verified_at) {
            echo "   ▶️  NEXT: Verify email (check Mailtrap)\n";
        } elseif ($owner->status->value === 'pending') {
            echo "   ▶️  NEXT: Admin needs to approve\n";
        } elseif ($owner->status->value === 'approved' && !$owner->password) {
            echo "   ▶️  NEXT: Set password (check Mailtrap for link)\n";
        } elseif ($owner->status->value === 'approved' && $owner->password) {
            echo "   ✅  READY: Can login at /shop-owner/login\n";
        } elseif ($owner->status->value === 'rejected') {
            echo "   ❌  REJECTED: " . ($owner->rejection_reason ?? 'No reason provided') . "\n";
        }
    }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "     TESTING CHECKLIST\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "□ Step 1: Register new shop owner (/shop-owner/register)\n";
echo "□ Step 2: Check Mailtrap for verification email\n";
echo "□ Step 3: Click verification link\n";
echo "□ Step 4: Verify redirect to 'Pending Approval' page\n";
echo "□ Step 5: Login as Super Admin\n";
echo "□ Step 6: Navigate to Shop Owner Registrations\n";
echo "□ Step 7: Approve the pending registration\n";
echo "□ Step 8: Check Mailtrap for approval email\n";
echo "□ Step 9: Click password setup link from email\n";
echo "□ Step 10: Set password on setup page\n";
echo "□ Step 11: Login with new credentials\n";
echo "□ Step 12: Verify dashboard shows account type info\n";

echo "\n═══════════════════════════════════════════════════════════\n\n";

// Check Mailtrap configuration
echo "📧 MAILTRAP CONFIGURATION:\n";
echo "   Host: " . config('mail.mailers.smtp.host') . "\n";
echo "   Port: " . config('mail.mailers.smtp.port') . "\n";
echo "   Username: " . config('mail.mailers.smtp.username') . "\n";
echo "   From: " . config('mail.from.address') . "\n\n";

echo "✅ Test script complete!\n";
