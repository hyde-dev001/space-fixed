<?php

use App\Models\ShopOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════\n";
echo "     PASSWORD SETUP DEBUGGING\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$email = 'flowtest@example.com';
$owner = ShopOwner::where('email', $email)->first();

if (!$owner) {
    echo "❌ Shop owner not found!\n";
    exit(1);
}

// Get the token from database
$tokenRecord = DB::table('password_reset_tokens')->where('email', $email)->first();

if (!$tokenRecord) {
    echo "❌ No password reset token found!\n";
    echo "   Creating new token...\n\n";
    
    $plainToken = Str::random(64);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $email],
        [
            'email' => $email,
            'token' => hash('sha256', $plainToken),
            'created_at' => now()
        ]
    );
    
    echo "✅ Token created!\n";
    echo "Plain token: {$plainToken}\n";
    echo "Hashed token: " . hash('sha256', $plainToken) . "\n\n";
    
    $url = route('shop-owner.password.setup', ['token' => $plainToken, 'email' => $email]);
    echo "🔗 PASSWORD SETUP URL:\n";
    echo "{$url}\n\n";
    echo "Copy this URL and paste it in your browser!\n";
    
} else {
    echo "⚠️  Token exists but we can't retrieve the plain version.\n";
    echo "   The database only stores the hashed token for security.\n\n";
    echo "   Creating a NEW token...\n\n";
    
    $plainToken = Str::random(64);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $email],
        [
            'email' => $email,
            'token' => hash('sha256', $plainToken),
            'created_at' => now()
        ]
    );
    
    echo "✅ New token created!\n\n";
    
    $url = route('shop-owner.password.setup', ['token' => $plainToken, 'email' => $email]);
    echo "🔗 PASSWORD SETUP URL:\n";
    echo "{$url}\n\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    echo "📋 INSTRUCTIONS:\n";
    echo "1. Copy the URL above\n";
    echo "2. Open an incognito/private browser window\n";
    echo "3. Paste the URL and press Enter\n";
    echo "4. You should see the password setup page\n\n";
}

echo "Shop Owner Info:\n";
echo "  Email: {$owner->email}\n";
echo "  Business: {$owner->business_name}\n";
echo "  Status: {$owner->status->value}\n";
echo "  Has Password: " . ($owner->password ? "Yes" : "No") . "\n\n";
