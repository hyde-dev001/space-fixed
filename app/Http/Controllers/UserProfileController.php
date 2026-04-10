<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * UserProfileController
 * 
 * Handles ERP user profile and password management
 */
class UserProfileController extends Controller
{
    /**
     * Show the user profile page
     * 
     * Displays the profile page where users can manage their password
     * if they need to change it on first login
     * 
     * @param Request $request
     * @return \Inertia\Response
     */
    public function show(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        return Inertia::render('ERP/Profile', [
            'user' => $user,
            'requiresPasswordChange' => $user->force_password_change ?? false,
        ]);
    }

    /**
     * Update the user's password
     * 
     * Validates the current password and updates to the new password
     * Clears the force_password_change flag if set
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = Auth::guard('user')->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect']);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'force_password_change' => false,
        ]);

        // Stay on profile and let the client decide where to go next
        return back()->with('success', 'Password updated successfully');
    }
}
