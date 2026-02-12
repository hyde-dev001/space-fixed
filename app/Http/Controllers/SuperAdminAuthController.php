<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use App\Models\SuperAdmin;

class SuperAdminAuthController extends Controller
{
    /**
     * Show the super admin login form
     */
    public function showLoginForm()
    {
        // Redirect to dashboard if already logged in
        if (Auth::guard('super_admin')->check()) {
            return redirect()->route('admin.system-monitoring');
        }
        
        return Inertia::render('superAdmin/SuperAdminLogin');
    }

    /**
     * Handle super admin login
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (Auth::guard('super_admin')->attempt($credentials, $request->remember)) {
            $request->session()->regenerate();
            return redirect()->route('admin.system-monitoring');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Handle super admin logout
     */
    public function logout(Request $request)
    {
        Auth::guard('super_admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Check if request is AJAX/JSON
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Logged out successfully']);
        }

        return redirect()->route('admin.login');
    }

    /**
     * Show super admin profile
     */
    public function showProfile()
    {
        return Inertia::render('superAdmin/Profile', [
            'admin' => Auth::guard('super_admin')->user()
        ]);
    }

    /**
     * Update super admin password
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $admin = Auth::guard('super_admin')->user();

        if (!Hash::check($request->current_password, $admin->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect']);
        }

        $admin->update([
            'password' => Hash::make($request->password)
        ]);

        return back()->with('success', 'Password updated successfully');
    }
}
