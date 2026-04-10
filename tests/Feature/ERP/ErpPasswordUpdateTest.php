<?php

namespace Tests\Feature\ERP;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ErpPasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function erp_user_password_is_updated_with_valid_current_password(): void
    {
        $employee = User::factory()->create([
            'password' => Hash::make('CurrentPass1!'),
        ]);

        $response = $this->actingAs($employee, 'user')
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.31'])
            ->post('/erp/password', [
                'current_password' => 'CurrentPass1!',
                'password' => 'NewStrongPass1!',
                'password_confirmation' => 'NewStrongPass1!',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertTrue(Hash::check('NewStrongPass1!', $employee->fresh()->password));
    }

    #[Test]
    public function erp_password_rejects_wrong_current_password(): void
    {
        $employee = User::factory()->create([
            'password' => Hash::make('CurrentPass1!'),
        ]);

        $response = $this->actingAs($employee, 'user')->post('/erp/password', [
            'current_password' => 'WrongPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);

        $response->assertSessionHasErrors('current_password');
    }

    #[Test]
    public function erp_password_route_is_throttled_after_five_attempts(): void
    {
        $employee = User::factory()->create([
            'password' => Hash::make('CurrentPass1!'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($employee, 'user')->post('/erp/password', [
                'current_password' => 'WrongPass1!',
                'password' => 'NewStrongPass1!',
                'password_confirmation' => 'NewStrongPass1!',
            ]);
        }

        $last = $this->actingAs($employee, 'user')->post('/erp/password', [
            'current_password' => 'WrongPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);

        $last->assertStatus(429);
    }
}
