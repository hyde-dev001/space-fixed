<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedSessionService;

class SuperAdminAuthController extends Controller
{
    private const GENERIC_AUTH_ERROR = 'These credentials are invalid.';

    private const DUMMY_PASSWORD_HASH = '$2y$10$5n3DruMVEXy/QDrfseoa.uJ3ed2F8YjGuWk8rbM.tE0uNTd85ew.C';

    public function __construct(
        private readonly PrivilegedSessionService $sessions,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    /**
     * Show the super admin login form
     */
    public function showLoginForm()
    {
        if (Auth::guard('super_admin')->check()) {
            $stage = (string) request()->session()->get('privileged_auth_stage');

            if ($stage === 'setup') {
                return redirect('/admin/mfa/setup');
            }

            if ($stage === 'mfa_challenge') {
                return redirect('/admin/mfa/challenge');
            }

            return redirect()->route('admin.system-monitoring');
        }

        return Inertia::render('superAdmin/Auth/SuperAdminLogin');
    }

    /**
     * Handle super admin login
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower(trim((string) $credentials['email']));
        $admin = SuperAdmin::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
        $passwordHash = $admin?->getAuthPassword() ?: self::DUMMY_PASSWORD_HASH;
        $passwordMatches = Hash::check((string) $credentials['password'], (string) $passwordHash);

        if (! $admin instanceof SuperAdmin || ! $admin->isActive() || ! $passwordMatches) {
            $this->audit->privilegedLoginFailed($request, $admin);

            return $this->failedLogin($request);
        }

        Auth::guard('super_admin')->login($admin, false);
        $request->session()->regenerate();
        $request->session()->put([
            'privileged_auth_stage' => $admin->hasCompletedMfaSetup() ? 'mfa_challenge' : 'setup',
            'privileged_super_admin_id' => $admin->id,
            'privileged_security_version' => (int) $admin->security_version,
            'privileged_remember_requested' => $request->boolean('remember'),
        ]);
        $this->audit->privilegedLoginSucceeded($request, $admin);

        return $admin->hasCompletedMfaSetup()
            ? redirect('/admin/mfa/challenge')
            : redirect('/admin/mfa/setup');
    }

    /**
     * Handle super admin logout
     */
    public function logout(Request $request)
    {
        $this->sessions->forgetCurrent($request);
        Auth::guard('super_admin')->logout();
        $request->session()->regenerateToken();

        // Check if request is AJAX/JSON
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Logged out successfully']);
        }

        return redirect()->route('admin.login');
    }

    private function failedLogin(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::GENERIC_AUTH_ERROR], 422);
        }

        return redirect()
            ->route('admin.login')
            ->withErrors(['email' => self::GENERIC_AUTH_ERROR])
            ->onlyInput('email');
    }

    /**
     * Show super admin profile
     */
    public function showProfile()
    {
        return Inertia::render('superAdmin/Settings/Profile', [
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
