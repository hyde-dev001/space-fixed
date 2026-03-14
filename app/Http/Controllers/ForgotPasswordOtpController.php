<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetOtpMail;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class ForgotPasswordOtpController extends Controller
{
    private const OTP_TTL_MINUTES = 10;
    private const OTP_MAX_ATTEMPTS = 5;
    private const RESET_TTL_MINUTES = 15;

    public function sendOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim($validated['email']));
        $account = $this->findResettableAccount($email);

        if ($account) {
            $otp = (string) random_int(100000, 999999);

            $payload = [
                'otp_hash' => Hash::make($otp),
                'attempts' => 0,
                'verified' => false,
                'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES)->timestamp,
            ];

            Cache::put($this->cacheKey($email), $payload, now()->addMinutes(self::OTP_TTL_MINUTES));

            try {
                Mail::to($email)->send(new PasswordResetOtpMail($otp));
            } catch (\Throwable $e) {
                Log::error('Failed to send password reset OTP', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);

                return back()->withErrors([
                    'email' => 'Unable to send OTP email right now. Please try again later.',
                ])->withInput();
            }
        }

        // Do not disclose whether account exists.
        return redirect()->route('password.otp', ['email' => $email])->with('status', 'otp-sent');
    }

    public function resendOtp(Request $request): RedirectResponse
    {
        return $this->sendOtp($request);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $email = strtolower(trim($validated['email']));
        $otp = $validated['otp'];

        $entry = Cache::get($this->cacheKey($email));

        if (!$entry || !is_array($entry)) {
            return back()->withErrors([
                'otp' => 'OTP is invalid or expired. Please request a new one.',
            ]);
        }

        if (($entry['attempts'] ?? 0) >= self::OTP_MAX_ATTEMPTS) {
            Cache::forget($this->cacheKey($email));

            return back()->withErrors([
                'otp' => 'Too many failed attempts. Please request a new OTP.',
            ]);
        }

        $expiresAt = (int) ($entry['expires_at'] ?? 0);
        if ($expiresAt <= now()->timestamp) {
            Cache::forget($this->cacheKey($email));

            return back()->withErrors([
                'otp' => 'OTP has expired. Please request a new one.',
            ]);
        }

        if (!Hash::check($otp, (string) ($entry['otp_hash'] ?? ''))) {
            $entry['attempts'] = ((int) ($entry['attempts'] ?? 0)) + 1;
            $secondsLeft = max(1, $expiresAt - now()->timestamp);
            Cache::put($this->cacheKey($email), $entry, now()->addSeconds($secondsLeft));

            return back()->withErrors([
                'otp' => 'Incorrect OTP. Please try again.',
            ]);
        }

        // OTP verified: allow password reset for a short time window.
        $entry['verified'] = true;
        $entry['verified_at'] = now()->timestamp;
        $entry['reset_expires_at'] = now()->addMinutes(self::RESET_TTL_MINUTES)->timestamp;
        Cache::put($this->cacheKey($email), $entry, now()->addMinutes(self::RESET_TTL_MINUTES));

        return redirect()->route('password.new', ['email' => $email])->with('status', 'otp-verified');
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $email = strtolower(trim($validated['email']));
        $entry = Cache::get($this->cacheKey($email));

        if (!$entry || !is_array($entry) || !($entry['verified'] ?? false)) {
            return redirect()->route('password.request')->withErrors([
                'email' => 'Please verify your OTP first before resetting your password.',
            ]);
        }

        $resetExpiresAt = (int) ($entry['reset_expires_at'] ?? 0);
        if ($resetExpiresAt <= now()->timestamp) {
            Cache::forget($this->cacheKey($email));

            return redirect()->route('password.request')->withErrors([
                'email' => 'Your password reset session expired. Please request a new OTP.',
            ]);
        }

        $account = $this->findResettableAccount($email);

        if (!$account) {
            return redirect()->route('password.request')->withErrors([
                'email' => 'Account is not eligible for password reset.',
            ]);
        }

        $account->password = Hash::make($validated['password']);
        $account->save();

        Cache::forget($this->cacheKey($email));

        return redirect()->route('user.login.form')->with('success', 'Password reset successful. You can now log in.');
    }

    private function cacheKey(string $email): string
    {
        return 'password_reset_otp:' . sha1($email);
    }

    private function findResettableAccount(string $email): User|ShopOwner|null
    {
        $user = User::where('email', $email)->first();

        if ($user && !empty($user->password)) {
            return $user;
        }

        $shopOwner = ShopOwner::where('email', $email)->first();

        if ($shopOwner && !empty($shopOwner->password)) {
            return $shopOwner;
        }

        return null;
    }
}
