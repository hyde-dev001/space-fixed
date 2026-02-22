<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Events\Verified;
use App\Models\ShopOwner;
use App\Models\User;

class EmailVerificationController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function verify(Request $request, $id, $hash)
    {
        // Try to find shop owner first
        $shopOwner = ShopOwner::findOrFail($id);
        
        if ($shopOwner) {
            if (!hash_equals((string) $hash, sha1($shopOwner->email))) {
                abort(403, 'Invalid verification link.');
            }

            if ($shopOwner->hasVerifiedEmail()) {
                return redirect()->route('shop-owner.pending-approval')
                    ->with('message', 'Email already verified!');
            }

            if ($shopOwner->markEmailAsVerified()) {
                event(new Verified($shopOwner));
            }

            // Log the shop owner in if not already
            if (!Auth::guard('shop_owner')->check()) {
                Auth::guard('shop_owner')->login($shopOwner);
            }

            return redirect()->route('shop-owner.pending-approval')
                ->with('success', 'Email verified successfully! Your application is under review.');
        }

        abort(404, 'User not found.');
    }
}
