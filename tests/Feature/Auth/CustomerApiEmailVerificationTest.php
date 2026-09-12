<?php

namespace Tests\Feature\Auth;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerApiEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_registration_creates_an_unverified_customer_and_sends_verification_email_without_a_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'API Customer',
            'email' => 'api.customer@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('token');

        $customer = User::query()->where('email', 'api.customer@example.test')->firstOrFail();

        $this->assertFalse($customer->hasVerifiedEmail());
        $this->assertGuest('user');
        Notification::assertSentTo($customer, VerifyEmail::class, function (VerifyEmail $notification) use ($customer): bool {
            $verificationUrl = $notification->toMail($customer)->actionUrl;

            return str_contains($verificationUrl, '/email/verify/user/'.$customer->id.'/'.sha1($customer->email));
        });
    }

    public function test_api_login_does_not_issue_a_token_to_an_unverified_customer(): void
    {
        $customer = User::factory()->unverified()->create([
            'email' => 'unverified.api.customer@example.test',
            'password' => 'Password123!',
            'shop_owner_id' => null,
            'role' => null,
            'status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'email' => $customer->email,
            'password' => 'Password123!',
        ])->assertForbidden()
            ->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED')
            ->assertJsonMissingPath('token');

        $this->assertGuest('user');
        $this->assertCount(0, $customer->tokens()->get());
    }

    public function test_api_login_still_issues_a_token_to_a_verified_customer(): void
    {
        $customer = User::factory()->create([
            'email' => 'verified.api.customer@example.test',
            'password' => 'Password123!',
            'shop_owner_id' => null,
            'role' => null,
            'status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'email' => $customer->email,
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('user.id', $customer->id)
            ->assertJsonStructure(['token']);

        $this->assertCount(1, $customer->tokens()->get());
    }

    public function test_api_login_does_not_apply_customer_email_gate_to_an_employee_user(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $employee = User::factory()->unverified()->create([
            'email' => 'unverified.api.employee@example.test',
            'password' => 'Password123!',
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'email' => $employee->email,
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('user.id', $employee->id)
            ->assertJsonStructure(['token']);
    }
}
