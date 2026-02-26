<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Events\Verified;
use App\Models\ShopOwner;
use App\Models\User;
use Inertia\Inertia;

class EmailVerificationController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     * Handles both regular users and shop owners.
     */
    public function verify(Request $request, $id, $hash)
    {
        // Try to find user first (regular customer)
        $user = User::find($id);
        
        if ($user) {
            // Validate hash
            if (!hash_equals((string) $hash, sha1($user->email))) {
                return Inertia::render('UserSide/Auth/VerifyEmail', [
                    'status' => 'invalid',
                    'message' => 'Invalid verification link.'
                ]);
            }

            // Check if already verified
            if ($user->hasVerifiedEmail()) {
                return Inertia::render('UserSide/Auth/VerifyEmail', [
                    'status' => 'already-verified'
                ]);
            }

            // Mark as verified
            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }

            return Inertia::render('UserSide/Auth/VerifyEmail', [
                'status' => 'verified'
            ]);
        }

        // Try to find shop owner
        $shopOwner = ShopOwner::find($id);
        
        if ($shopOwner) {
            // Validate hash
            if (!hash_equals((string) $hash, sha1($shopOwner->email))) {
                return Inertia::render('UserSide/Auth/VerifyEmail', [
                    'status' => 'invalid',
                    'message' => 'Invalid verification link.'
                ]);
            }

            // Check if already verified
            if ($shopOwner->hasVerifiedEmail()) {
                // Redirect to pending approval page if exists, otherwise show already verified
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Email already verified! Your application is under review.'
                    ]);
                }
                
                return redirect()->route('shop-owner.pending-approval')
                    ->with('message', 'Email already verified! Your application is under review.');
            }

            // Mark as verified
            if ($shopOwner->markEmailAsVerified()) {
                event(new Verified($shopOwner));
            }

            // Log the shop owner in if not already
            if (!Auth::guard('shop_owner')->check()) {
                Auth::guard('shop_owner')->login($shopOwner);
            }

            // Redirect to pending approval page
            return redirect()->route('shop-owner.pending-approval')
                ->with('success', 'Email verified successfully! Your application is under review.');
        }

        // User/Shop owner not found
        return Inertia::render('UserSide/Auth/VerifyEmail', [
            'status' => 'invalid',
            'message' => 'User not found.'
        ]);
    }
}
