<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\ShopOwner;
use App\Models\ShopDocument;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════\n";
echo "     CREATE TEST SHOP OWNER FOR FLOW TESTING\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Delete existing test user if exists
$testEmail = 'flowtest@example.com';
ShopOwner::where('email', $testEmail)->delete();
DB::table('password_reset_tokens')->where('email', $testEmail)->delete();

echo "Creating test shop owner...\n";

DB::beginTransaction();

try {
    // Create shop owner
    $shopOwner = ShopOwner::create([
        'first_name' => 'Flow',
        'last_name' => 'Test',
        'email' => $testEmail,
        'phone' => '+1234567890',
        'password' => null, // Will be set after approval
        'business_name' => 'Flow Test Shop',
        'business_address' => '123 Test Street, Test City',
        'business_type' => 'retail',
        'registration_type' => 'individual',
        'status' => 'pending',
        'email_verified_at' => null, // Not verified yet
    ]);

    echo "✅ Shop Owner Created:\n";
    echo "   ID: {$shopOwner->id}\n";
    echo "   Email: {$shopOwner->email}\n";
    echo "   Business: {$shopOwner->business_name}\n";
    echo "   Type: " . ucfirst($shopOwner->registration_type) . " - " . ucfirst($shopOwner->business_type) . "\n";
    echo "   Status: {$shopOwner->status->value}\n\n";

    DB::commit();

    echo "═══════════════════════════════════════════════════════════\n";
    echo "     NEXT STEPS TO TEST\n";
    echo "═══════════════════════════════════════════════════════════\n\n";

    echo "✅ Step 1: Shop owner created (DONE)\n\n";
    
    echo "📧 Step 2: Send Verification Email\n";
    echo "   Run: php artisan tinker\n";
    echo "   Then: \$owner = App\\Models\\ShopOwner::find({$shopOwner->id});\n";
    echo "         \$owner->sendEmailVerificationNotification();\n\n";

    echo "   OR manually trigger by logging in as this shop owner\n\n";

    echo "✅ Step 3: Check Mailtrap\n";
    echo "   Login to: https://mailtrap.io/inboxes\n";
    echo "   Look for verification email sent to: {$testEmail}\n\n";

    echo "🔗 Step 4: Test Verification Link\n";
    echo "   Click the link in the email\n";
    echo "   Should redirect to: /shop-owner/pending-approval\n\n";

    echo "👔 Step 5: Approve as Admin\n";
    echo "   Login as Super Admin\n";
    echo "   Go to Shop Owner Registrations\n";
    echo "   Approve: {$shopOwner->business_name}\n";
    echo "   This will send password setup email\n\n";

    echo "📧 Step 6: Password Setup\n";
    echo "   Check Mailtrap for approval email\n";
    echo "   Click password setup link\n";
    echo "   Set password and login\n\n";

    echo "═══════════════════════════════════════════════════════════\n\n";

    // Quick command to send verification email
    echo "💡 QUICK SEND VERIFICATION EMAIL:\n";
    echo "   php artisan tinker --execute=\"App\\Models\\ShopOwner::find({$shopOwner->id})->sendEmailVerificationNotification();\"\n\n";

} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
