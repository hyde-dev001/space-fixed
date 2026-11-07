<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeInvitationPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_invitation_password_requires_at_least_twelve_characters(): void
    {
        User::factory()->create([
            'email' => 'invited-employee@example.com',
            'password' => null,
            'invite_token' => 'employee-invite-token',
            'invite_expires_at' => now()->addDay(),
        ]);

        $this->post(route('invitation.accept-invitation.submit', ['token' => 'employee-invite-token']), [
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertSessionHasErrors('password');
    }
}
