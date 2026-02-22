<?php

use App\Models\ShopOwner;
use Illuminate\Support\Facades\URL;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════\n";
echo "     GENERATE EMAIL VERIFICATION LINK\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$shopOwner = ShopOwner::find(4); // Flow Test Shop

if (!$shopOwner) {
    echo "❌ Shop owner not found!\n";
    exit(1);
}

echo "Shop Owner: {$shopOwner->business_name}\n";
echo "Email: {$shopOwner->email}\n";
echo "Verified: " . ($shopOwner->email_verified_at ? "✅ Yes" : "❌ No") . "\n\n";

// Generate verification URL
$verificationUrl = URL::temporarySignedRoute(
    'verification.verify',
    now()->addMinutes(60),
    [
        'id' => $shopOwner->id,
        'hash' => sha1($shopOwner->email),
    ]
);

echo "🔗 VERIFICATION LINK:\n";
echo "{$verificationUrl}\n\n";

echo "═══════════════════════════════════════════════════════════\n";
echo "     TEST INSTRUCTIONS\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "1. Copy the verification link above\n";
echo "2. Paste it in your browser\n";
echo "3. Should redirect to: /shop-owner/pending-approval\n";
echo "4. Page should show:\n";
echo "   - 'Email verified successfully!' message\n";
echo "   - Pending approval status\n";
echo "   - Progress timeline\n\n";
