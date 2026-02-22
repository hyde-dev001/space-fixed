<?php

namespace App\Http\Controllers;

use App\Models\ShopOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class ShopOwnerPasswordSetupController extends Controller
{
    /**
     * Show the password setup form
     */
    public function show(Request $request)
    {
        // Log incoming request for debugging
        \Log::info('Password setup request received', [
            'has_token' => $request->has('token'),
            'has_email' => $request->has('email'),
            'email' => $request->email,
        ]);

        $request->validate([
            'token' => 'required',
            'email' => 'required|email'
        ]);

        // Verify the token
        $passwordReset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        \Log::info('Token verification', [
            'found' => $passwordReset ? 'yes' : 'no',
            'email' => $request->email,
        ]);

        if (!$passwordReset || !hash_equals(hash('sha256', $request->token), $passwordReset->token)) {
            \Log::warning('Password setup - Invalid token', ['email' => $request->email]);
            return redirect()->route('shop-owner.login.form')->withErrors([
                'email' => 'This password setup link is invalid or has expired.'
            ]);
        }

        // Check if token is expired (48 hours)
        if (now()->diffInHours($passwordReset->created_at) > 48) {
            \Log::warning('Password setup - Expired token', ['email' => $request->email]);
            return redirect()->route('shop-owner.login.form')->withErrors([
                'email' => 'This password setup link has expired. Please contact support.'
            ]);
        }

        // Find shop owner
        $shopOwner = ShopOwner::where('email', $request->email)->first();

        if (!$shopOwner) {
            \Log::warning('Password setup - Shop owner not found', ['email' => $request->email]);
            return redirect()->route('shop-owner.login.form')->withErrors([
                'email' => 'Shop owner account not found.'
            ]);
        }

        // Check if shop owner is approved
        if ($shopOwner->status->value !== 'approved') {
            \Log::warning('Password setup - Not approved', ['email' => $request->email, 'status' => $shopOwner->status->value]);
            return redirect()->route('shop-owner.pending-approval')->with([
                'error' => 'Your application is still under review.'
            ]);
        }

        // Check if password already set
        if ($shopOwner->password) {
            \Log::info('Password setup - Password already set', ['email' => $request->email]);
            return redirect()->route('shop-owner.login.form')->with([
                'message' => 'Your password has already been set. Please login.'
            ]);
        }

        \Log::info('Password setup - Rendering page', ['email' => $request->email]);

        return Inertia::render('Auth/SetupPassword', [
            'email' => $request->email,
            'token' => $request->token,
            'shopOwner' => [
                'business_name' => $shopOwner->business_name,
                'first_name' => $shopOwner->first_name,
            ]
        ]);
    }

    /**
     * Process the password setup
     */
    public function store(Request $request)
    {
        \Log::info('Password setup store - Request received', [
            'email' => $request->email,
            'has_password' => $request->has('password'),
            'has_password_confirmation' => $request->has('password_confirmation'),
        ]);

        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        \Log::info('Password setup store - Validation passed');

        // Verify the token again
        $passwordReset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$passwordReset || !hash_equals(hash('sha256', $request->token), $passwordReset->token)) {
            \Log::warning('Password setup store - Invalid token', ['email' => $request->email]);
            return back()->withErrors([
                'email' => 'This password setup link is invalid or has expired.'
            ]);
        }

        \Log::info('Password setup store - Token verified');

        // Find shop owner
        $shopOwner = ShopOwner::where('email', $request->email)->first();

        if (!$shopOwner || $shopOwner->status->value !== 'approved') {
            \Log::warning('Password setup store - Shop owner not found or not approved', [
                'email' => $request->email,
                'found' => $shopOwner ? 'yes' : 'no',
                'status' => $shopOwner ? $shopOwner->status->value : 'N/A',
            ]);
            return back()->withErrors([
                'email' => 'Unable to set up password. Please contact support.'
            ]);
        }

        \Log::info('Password setup store - Shop owner verified as approved');

        // Set the password
        $shopOwner->update([
            'password' => Hash::make($request->password),
        ]);

        // Delete the token
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        // Log the activity
        \Log::info('Shop owner password set up successfully', [
            'shop_owner_id' => $shopOwner->id,
            'email' => $shopOwner->email,
        ]);

        return redirect()->route('shop-owner.login.form')->with([
            'success' => 'Password set up successfully! You can now login to your account.'
        ]);
    }
}
