<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ForgotPasswordOtpFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_otp_for_existing_account(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'otp-user@example.com',
            'password' => Hash::make('OldPass123'),
        ]);

        $response = $this->post(route('password.otp.send'), [
            'email' => $user->email,
        ]);

        $response->assertRedirect(route('password.otp', ['email' => $user->email]));
        $response->assertSessionHas('status', 'otp-sent');

        Mail::assertSent(PasswordResetOtpMail::class, function (PasswordResetOtpMail $mail) use ($user) {
            return $mail->hasTo($user->email) && preg_match('/^\d{6}$/', $mail->otp) === 1;
        });

        $this->assertNotNull(Cache::get('password_reset_otp:' . sha1($user->email)));
    }

    public function test_it_verifies_otp_and_resets_password(): void
    {
        Mail::fake();

        $email = 'flow-user@example.com';
        User::factory()->create([
            'email' => $email,
            'password' => Hash::make('OldPass123'),
        ]);

        $this->post(route('password.otp.send'), ['email' => $email]);

        $sentOtp = null;
        Mail::assertSent(PasswordResetOtpMail::class, function (PasswordResetOtpMail $mail) use (&$sentOtp, $email) {
            if ($mail->hasTo($email)) {
                $sentOtp = $mail->otp;
                return true;
            }

            return false;
        });

        $this->assertNotNull($sentOtp);

        $verifyResponse = $this->post(route('password.otp.verify'), [
            'email' => $email,
            'otp' => $sentOtp,
        ]);

        $verifyResponse->assertRedirect(route('password.new', ['email' => $email]));
        $verifyResponse->assertSessionHas('status', 'otp-verified');

        $resetResponse = $this->post(route('password.otp.reset'), [
            'email' => $email,
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ]);

        $resetResponse->assertRedirect(route('user.login.form'));
        $resetResponse->assertSessionHas('success');

        $user = User::where('email', $email)->firstOrFail();
        $this->assertTrue(Hash::check('NewPass123', $user->password));
        $this->assertNull(Cache::get('password_reset_otp:' . sha1($email)));
    }

    public function test_non_existing_email_does_not_send_mail_but_returns_generic_flow(): void
    {
        Mail::fake();

        $email = 'does-not-exist@example.com';

        $response = $this->post(route('password.otp.send'), [
            'email' => $email,
        ]);

        $response->assertRedirect(route('password.otp', ['email' => $email]));
        $response->assertSessionHas('status', 'otp-sent');

        Mail::assertNothingSent();
        $this->assertNull(Cache::get('password_reset_otp:' . sha1($email)));
    }
}
