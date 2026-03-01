<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Employee;
use App\Mail\EmployeeInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Inertia\Inertia;

class InvitationController extends Controller
{
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
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ],
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.'
        ]);

        // Set password and clear invitation token
        $user->update([
            'password' => Hash::make($validated['password']),
            'invite_token' => null,
            'invite_expires_at' => null,
            'email_verified_at' => now(),
        ]);

        return redirect('/user/login')->with('success', 'Your account has been activated! Please log in with your work email and new password.');
    }
    
    /**
     * Regenerate invitation link (for HR/Shop Owner)
     */
    public function regenerate(Request $request, $employeeId)
    {
        $employee = Employee::findOrFail($employeeId);
        $user = User::where('email', $employee->email)->first();
        
        if (!$user) {
            return response()->json(['error' => 'User account not found'], 404);
        }
        
        // Generate new token
        $newToken = Str::random(64);
        $newExpiry = Carbon::now()->addDays(7);
        
        $user->update([
            'invite_token' => $newToken,
            'invite_expires_at' => $newExpiry,
            'invited_at' => now(),
            'invited_by' => auth()->id(),
        ]);
        
        return response()->json([
            'success' => true,
            'invite_url' => url("/accept-invitation/{$newToken}"),
            'invite_expires_at' => $newExpiry->toIso8601String(),
            'work_email' => $user->email,
            'employee_name' => $user->name,
        ]);
    }
    
    /**
     * Send invitation email to a personal email address
     */
    public function sendInvitationEmail(Request $request, $employeeId)
    {
        $request->validate([
            'personal_email' => 'required|email',
        ]);

        $employee = Employee::findOrFail($employeeId);
        $user = User::where('email', $employee->email)->first();

        if (!$user) {
            return response()->json(['error' => 'User account not found'], 404);
        }

        if (!$user->invite_token || !$user->invite_expires_at) {
            return response()->json(['error' => 'No active invitation found. Please regenerate the invitation first.'], 400);
        }

        if (Carbon::now()->greaterThan($user->invite_expires_at)) {
            return response()->json(['error' => 'Invitation has expired. Please regenerate a new invitation.'], 400);
        }

        $inviteUrl = url("/accept-invitation/{$user->invite_token}");
        $shopName = 'SoleSpace';
        $expiresAt = $user->invite_expires_at->format('M d, Y h:i A');
        $personalEmail = $request->personal_email;
        $employeeName = $user->name;
        $workEmail = $user->email;

        try {
            \Mail::send([], [], function ($message) use ($employeeName, $inviteUrl, $shopName, $expiresAt, $personalEmail, $workEmail) {
                $message->to($personalEmail)
                    ->subject("Your {$shopName} Account Invitation")
                    ->html("
                        <html>
                        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                                <h2 style='color: #4F46E5;'>Welcome to {$shopName}!</h2>
                                
                                <p>Hi <strong>{$employeeName}</strong>,</p>
                                
                                <p>You've been invited to join our team at <strong>{$shopName}</strong>!</p>
                                
                                <div style='background: #F3F4F6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                                    <p style='margin: 0 0 10px 0;'><strong>Your work email:</strong> {$workEmail}</p>
                                    <p style='margin: 0;'><strong>Invitation expires:</strong> {$expiresAt}</p>
                                </div>
                                
                                <p>Click the button below to set up your account and create your password:</p>
                                
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='{$inviteUrl}' 
                                       style='display: inline-block; background: #4F46E5; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                                        Set Up My Account
                                    </a>
                                </div>
                                
                                <p style='font-size: 12px; color: #666;'>
                                    Or copy and paste this link into your browser:<br>
                                    <a href='{$inviteUrl}' style='color: #4F46E5; word-break: break-all;'>{$inviteUrl}</a>
                                </p>
                                
                                <hr style='border: none; border-top: 1px solid #E5E7EB; margin: 20px 0;'>
                                
                                <p style='font-size: 12px; color: #666;'>
                                    <strong>Important:</strong> This invitation link will expire on {$expiresAt}.
                                    If you need a new invitation, please contact your administrator.
                                </p>
                            </div>
                        </body>
                        </html>
                    ");
            });

            return response()->json([
                'success' => true,
                'message' => "Invitation email sent successfully to {$personalEmail}",
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to send invitation email: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send email: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Resend invitation email (for HR/Shop Owner)
     */
    public function resendInvite($employeeId)
    {
        $employee = Employee::findOrFail($employeeId);
        $user = User::where('email', $employee->email)->first();
        
        if (!$user || !$user->invite_token) {
            return response()->json(['error' => 'No pending invitation found'], 404);
        }
        
        if ($user->password !== null) {
            return response()->json(['error' => 'User has already accepted invitation'], 400);
        }
        
        $inviteUrl = url("/invite/{$user->invite_token}");
        
        try {
            Mail::to($user->email)->send(new EmployeeInvitation($user, $inviteUrl));
            
            return response()->json([
                'success' => true,
                'email' => $user->email,
                'message' => 'Invitation email resent successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to resend invitation email: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send email'], 500);
        }
    }
}
