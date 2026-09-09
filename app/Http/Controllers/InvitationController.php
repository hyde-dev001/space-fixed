<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Employee;
use App\Mail\EmployeeInvitation;
use App\Services\EmployeeInvitationService;
use App\Services\EmployeeSecurityService;
use App\Support\EmployeePasswordRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class InvitationController extends Controller
{
    public function __construct(
        private readonly EmployeeInvitationService $invitations,
        private readonly EmployeeSecurityService $security,
    ) {}

    private function canManageEmployeeAccounts(mixed $authUser): bool
    {
        return $authUser instanceof User
            && $authUser->isEmployeeAccount()
            && $authUser->shop_owner_id !== null
            && $authUser->can('manage-employee-accounts');
    }

    /**
     * Show invitation acceptance page
     */
    public function show($token)
    {
        $user = $this->invitations->find((string) $token);

        if (!$user || !$user->invite_expires_at) {
            return Inertia::render('Auth/InvitationInvalid', [
                'error' => 'Invalid invitation link',
            ]);
        }

        if (now()->greaterThan($user->invite_expires_at)) {
            return Inertia::render('Auth/InvitationExpired', [
                'email' => $user->email,
                'expired_at' => $user->invite_expires_at->toDateTimeString(),
            ]);
        }

        if ($user->password !== null) {
            return Inertia::render('Auth/InvitationAlreadyAccepted', [
                'email' => $user->email,
            ]);
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'email' => $user->email,
            'name' => $user->name,
            'expires_at' => $user->invite_expires_at->toDateTimeString(),
        ]);
    }

    /**
     * Accept invitation and set password
     */
    public function accept(Request $request, $token)
    {
        $user = $this->invitations->find((string) $token);

        if (!$user || !$user->invite_expires_at) {
            return Inertia::render('Auth/InvitationInvalid', [
                'error' => 'Invalid invitation link',
            ]);
        }

        if (now()->greaterThan($user->invite_expires_at)) {
            return Inertia::render('Auth/InvitationExpired', [
                'email' => $user->email,
                'expired_at' => $user->invite_expires_at->toDateTimeString(),
            ]);
        }

        if ($user->password !== null) {
            return Inertia::render('Auth/InvitationAlreadyAccepted', [
                'email' => $user->email,
            ]);
        }

        $validated = $request->validate(['password' => EmployeePasswordRules::rules()]);
        $tokenHash = hash('sha256', (string) $token);

        $accepted = DB::transaction(function () use ($user, $validated, $token, $tokenHash): bool {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (!$lockedUser || $lockedUser->password !== null
                || !$lockedUser->invite_expires_at
                || now()->greaterThan($lockedUser->invite_expires_at)) {
                return false;
            }

            $tokenMatches = hash_equals((string) $lockedUser->invite_token_hash, $tokenHash)
                || hash_equals((string) $lockedUser->invite_token, (string) $token);
            if (! $tokenMatches) {
                return false;
            }

            $employee = Employee::query()
                ->where('shop_owner_id', $lockedUser->shop_owner_id)
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $lockedUser->email)])
                ->lockForUpdate()
                ->first();

            if (!$employee) {
                return false;
            }

            $lockedUser->forceFill([
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
                'force_password_change' => false,
            ])->save();
            $this->invitations->clear($lockedUser);
            $this->security->invalidateAccess($lockedUser);
            $this->security->audit(
                $lockedUser,
                'employee_invitation_accepted',
                'Employee invitation accepted.',
                $employee,
            );

            return true;
        });

        if (!$accepted) {
            return Inertia::render('Auth/InvitationInvalid', [
                'error' => 'This invitation is no longer valid.',
            ]);
        }

        return redirect('/login')->with('success', 'Your account has been activated! Please log in with your work email and new password.');
    }

    /**
     * Force-reset an employee password and issue a fresh invitation link.
     */
    public function resetEmployeePassword(Request $request, $employeeId)
    {
        $authUser = Auth::guard('user')->user();

        if (!$this->canManageEmployeeAccounts($authUser)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::query()
            ->whereKey($employeeId)
            ->where('shop_owner_id', $authUser->shop_owner_id)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        $user = User::query()
            ->where('email', $employee->email)
            ->where('shop_owner_id', $authUser->shop_owner_id)
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Linked user account not found'], 404);
        }

        if ((int) $user->id === (int) $authUser->id) {
            return response()->json([
                'error' => 'You cannot reset the password of the account you are currently using.',
            ], 422);
        }

        $invitation = DB::transaction(function () use ($user, $employee, $authUser): array {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->where('shop_owner_id', $authUser->shop_owner_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->security->invalidateAccess($lockedUser);
            $invitation = $this->invitations->issue($lockedUser, (int) $authUser->id, true);
            $this->security->audit(
                $lockedUser,
                'employee_credential_reset_initiated',
                'Employee credential reset initiated by authorized management.',
                $employee,
            );

            return $invitation;
        });

        return response()->json([
            'success' => true,
            'message' => 'Password reset initiated. Share the new setup link with the employee.',
            'invite_url' => url("/accept-invitation/{$invitation['token']}"),
            'invite_expires_at' => $invitation['expires_at']->toIso8601String(),
            'work_email' => $user->email,
            'employee_name' => $user->name,
        ]);
    }
    /**
     * Regenerate invitation link (for HR/Shop Owner)
     */
    public function regenerate(Request $request, $employeeId)
    {
        $authUser = Auth::guard('user')->user();

        if (!$this->canManageEmployeeAccounts($authUser)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::query()
            ->whereKey($employeeId)
            ->where('shop_owner_id', $authUser->shop_owner_id)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        $user = User::query()
            ->where('email', $employee->email)
            ->where('shop_owner_id', $authUser->shop_owner_id)
            ->first();

        if (!$user) {
            return response()->json(['error' => 'User account not found'], 404);
        }

        if ((int) $user->id === (int) $authUser->id || $user->password !== null) {
            return response()->json([
                'error' => 'Only pending employee invitations can be regenerated.',
            ], 422);
        }

        $invitation = DB::transaction(function () use ($user, $employee, $authUser): array {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->where('shop_owner_id', $authUser->shop_owner_id)
                ->lockForUpdate()
                ->firstOrFail();

            $invitation = $this->invitations->issue($lockedUser, (int) $authUser->id);
            $this->security->audit(
                $lockedUser,
                'employee_invitation_regenerated',
                'Employee invitation regenerated by authorized management.',
                $employee,
            );

            return $invitation;
        });

        return response()->json([
            'success' => true,
            'invite_url' => url("/accept-invitation/{$invitation['token']}"),
            'invite_expires_at' => $invitation['expires_at']->toIso8601String(),
            'work_email' => $user->email,
            'employee_name' => $user->name,
        ]);
    }
    /**
     * Send invitation email to a personal email address
     */
    public function sendInvitationEmail(Request $request, $employeeId)
    {
        $authUser = Auth::guard('user')->user();

        if (!$this->canManageEmployeeAccounts($authUser)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'personal_email' => 'required|email',
        ]);

        $employee = Employee::query()
            ->where('id', $employeeId)
            ->where('shop_owner_id', $authUser->shop_owner_id)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        $user = User::query()
            ->where('email', $employee->email)
            ->where('shop_owner_id', $authUser->shop_owner_id)
            ->first();

        if (!$user) {
            return response()->json(['error' => 'User account not found'], 404);
        }

        if ($user->password !== null) {
            return response()->json(['error' => 'The employee has already accepted the invitation.'], 422);
        }

        $invitation = DB::transaction(function () use ($user, $employee, $authUser): array {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->where('shop_owner_id', $authUser->shop_owner_id)
                ->lockForUpdate()
                ->firstOrFail();

            $invitation = $this->invitations->issue($lockedUser, (int) $authUser->id);
            $this->security->audit(
                $lockedUser,
                'employee_invitation_resent',
                'Employee invitation sent to a personal email address.',
                $employee,
            );

            return $invitation;
        });

        $inviteUrl = url("/accept-invitation/{$invitation['token']}");
        $shopName = 'SoleSpace';
        $expiresAt = $invitation['expires_at']->format('M d, Y h:i A');

        $personalEmail = $request->personal_email;
        $employeeName = $user->name;
        $workEmail = $user->email;

        $htmlEmployeeName = htmlspecialchars((string) $employeeName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $htmlInviteUrl = htmlspecialchars((string) $inviteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $htmlShopName = htmlspecialchars((string) $shopName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $htmlExpiresAt = htmlspecialchars((string) $expiresAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $htmlWorkEmail = htmlspecialchars((string) $workEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        try {
            Mail::send([], [], function ($message) use ($htmlEmployeeName, $htmlInviteUrl, $htmlShopName, $htmlExpiresAt, $personalEmail, $htmlWorkEmail) {
                $message->to($personalEmail)
                    ->subject("Your {$htmlShopName} Account Invitation")
                    ->html("
                        <html>
                        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                            <h2 style='color: #4F46E5;'>Welcome to {$htmlShopName}!</h2>
                                
                            <p>Hi <strong>{$htmlEmployeeName}</strong>,</p>
                                
                            <p>You've been invited to join our team at <strong>{$htmlShopName}</strong>!</p>
                                
                                <div style='background: #F3F4F6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                                    <p style='margin: 0 0 10px 0;'><strong>Your work email:</strong> {$htmlWorkEmail}</p>
                                    <p style='margin: 0;'><strong>Invitation expires:</strong> {$htmlExpiresAt}</p>
                                </div>
                                
                                <p>Click the button below to set up your account and create your password:</p>
                                
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='{$htmlInviteUrl}'
                                       style='display: inline-block; background: #4F46E5; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                                        Set Up My Account
                                    </a>
                                </div>
                                
                                <p style='font-size: 12px; color: #666;'>
                                    Or copy and paste this link into your browser:<br>
                                    <a href='{$htmlInviteUrl}' style='color: #4F46E5; word-break: break-all;'>{$htmlInviteUrl}</a>
                                </p>
                                
                                <hr style='border: none; border-top: 1px solid #E5E7EB; margin: 20px 0;'>
                                
                                <p style='font-size: 12px; color: #666;'>
                                    <strong>Important:</strong> This invitation link will expire on {$htmlExpiresAt}.
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
                'invite_url' => $inviteUrl,
                'invite_expires_at' => $invitation['expires_at']->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send invitation email: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send email.'], 500);
        }
    }
    
    /**
     * Resend invitation email (for HR/Shop Owner)
     */
    public function resendInvite(Request $request, $employeeId)
    {
        $authUser = Auth::guard('user')->user();

        if (!$this->canManageEmployeeAccounts($authUser)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $employee = Employee::query()
            ->whereKey($employeeId)
            ->where('shop_owner_id', $authUser->shop_owner_id)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        $user = User::query()
            ->where('email', $employee->email)
            ->where('shop_owner_id', $authUser->shop_owner_id)
            ->first();

        if (!$user || $user->password !== null) {
            return response()->json(['error' => 'No pending invitation found'], 404);
        }

        $invitation = DB::transaction(function () use ($user, $employee, $authUser): array {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->where('shop_owner_id', $authUser->shop_owner_id)
                ->lockForUpdate()
                ->firstOrFail();

            $invitation = $this->invitations->issue($lockedUser, (int) $authUser->id);
            $this->security->audit(
                $lockedUser,
                'employee_invitation_resent',
                'Employee invitation resent by authorized management.',
                $employee,
            );

            return $invitation;
        });

        try {
            $mailUser = $user->fresh();
            Mail::to($mailUser->email)->send(
                new EmployeeInvitation($mailUser, url("/invite/{$invitation['token']}")),
            );

            return response()->json([
                'success' => true,
                'email' => $mailUser->email,
                'message' => 'Invitation email resent successfully',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to resend employee invitation email.', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);

            return response()->json(['error' => 'Failed to send email'], 500);
        }
    }
}
