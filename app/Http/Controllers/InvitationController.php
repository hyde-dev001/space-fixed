<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmployeeAccountLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Inertia\Inertia;

class InvitationController extends Controller
{
    public function __construct(
        private readonly EmployeeAccountLinkService $accountLinks,
    ) {
    }

    /**
     * Show invitation acceptance page
     */
    public function show($token)
    {
        // Find user by token
        $user = User::where('invite_token', $token)
                    ->whereNotNull('invite_token')
                    ->first();
        
        // Validate token exists
        if (!$user) {
            return Inertia::render('Auth/InvitationInvalid', [
                'error' => 'Invalid invitation link'
            ]);
        }
        
        // Check if token expired
        if (Carbon::now()->greaterThan($user->invite_expires_at)) {
            return Inertia::render('Auth/InvitationExpired', [
                'email' => $user->email,
                'expired_at' => $user->invite_expires_at->toDateTimeString()
            ]);
        }
        
        // Check if already accepted
        if ($user->password !== null) {
            return Inertia::render('Auth/InvitationAlreadyAccepted', [
                'email' => $user->email
            ]);
        }
        
        // Show password setup form
        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'email' => $user->email,
            'name' => $user->name,
            'expires_at' => $user->invite_expires_at->toDateTimeString()
        ]);
    }
    
    /**
     * Accept invitation and set password
     */
    public function accept(Request $request, $token)
    {
        // Find user first (before validation so we can show proper error pages)
        $user = User::where('invite_token', $token)
                    ->whereNotNull('invite_token')
                    ->first();

        if (!$user) {
            return Inertia::render('Auth/InvitationInvalid', [
                'error' => 'Invalid invitation link'
            ]);
        }

        if (Carbon::now()->greaterThan($user->invite_expires_at)) {
            return Inertia::render('Auth/InvitationExpired', [
                'email' => $user->email,
                'expired_at' => $user->invite_expires_at->toDateTimeString()
            ]);
        }

        if ($user->password !== null) {
            return Inertia::render('Auth/InvitationAlreadyAccepted', [
                'email' => $user->email
            ]);
        }

        // Validate input
        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@\$!%*?&])[A-Za-z\d@\$!%*?&]/',
            ],
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.'
        ]);

        // Set password and clear invitation token, and clear the force_password_change flag
        $user->update([
            'password' => Hash::make($validated['password']),
            'invite_token' => null,
            'invite_expires_at' => null,
            'email_verified_at' => now(),
            'force_password_change' => false,
        ]);

        return redirect('/login')->with('success', 'Your account has been activated! Please log in with your work email and new password.');
    }
    
    /**
     * Force-reset an employee password and issue a fresh invitation link.
     */
    public function resetEmployeePassword(Request $request, $employeeId)
    {
        $authUser = Auth::guard('user')->user();

        if (!$authUser) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $link = $this->accountLinks->issueForEmployeeId((int) $employeeId, $authUser, true);

        return response()->json([
            'success' => true,
            'message' => 'Password reset initiated. Share the new setup link with the employee.',
            ...$link,
        ]);
    }

    /**
     * Regenerate invitation link (for HR/Shop Owner)
     */
    public function regenerate(Request $request, $employeeId)
    {
        $authUser = Auth::guard('user')->user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $link = $this->accountLinks->issueForEmployeeId((int) $employeeId, $authUser, false);

        return response()->json([
            'success' => true,
            ...$link,
        ]);
    }
    
    /**
     * Send invitation email to a personal email address
     */
    public function sendInvitationEmail(Request $request, $employeeId)
    {
        $authUser = Auth::guard('user')->user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'personal_email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $link = $this->accountLinks->sendToPersonalEmailForEmployeeId(
                (int) $employeeId,
                $authUser,
                (string) $validated['personal_email'],
            );

            return response()->json([
                'success' => true,
                ...$link,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'details' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to send employee invitation email.', [
                'employee_id' => (int) $employeeId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to send email.'], 500);
        }
    }
    
    /**
     * Resend invitation email (for HR/Shop Owner)
     */
    public function resendInvite($employeeId)
    {
        $authUser = Auth::guard('user')->user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $link = $this->accountLinks->resendToWorkEmailForEmployeeId((int) $employeeId, $authUser);

            return response()->json([
                'success' => true,
                ...$link,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'details' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to resend employee invitation email.', [
                'employee_id' => (int) $employeeId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to send email.'], 500);
        }
    }
}
