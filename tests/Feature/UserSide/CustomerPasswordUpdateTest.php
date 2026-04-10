<?php

namespace Tests\Feature\UserSide;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerPasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_password_is_updated_with_valid_current_password(): void
    {
        $customer = User::factory()->create([
            'password' => Hash::make('CurrentPass1!'),
            'shop_owner_id' => null,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.21'])
            ->post('/customer-profile/password', [
                'current_password' => 'CurrentPass1!',
                'password' => 'NewStrongPass1!',
                'password_confirmation' => 'NewStrongPass1!',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertTrue(Hash::check('NewStrongPass1!', $customer->fresh()->password));
    }

    #[Test]
    public function customer_password_requires_symbol_in_new_password(): void
    {
        $customer = User::factory()->create([
            'password' => Hash::make('CurrentPass1!'),
            'shop_owner_id' => null,
        ]);

        $response = $this->actingAs($customer, 'user')->post('/customer-profile/password', [
            'current_password' => 'CurrentPass1!',
            'password' => 'NoSymbol123',
            'password_confirmation' => 'NoSymbol123',
        ]);

        $response->assertSessionHasErrors('password');
    }

    #[Test]
    public function customer_password_route_is_throttled_after_five_attempts(): void
    {
        $customer = User::factory()->create([
            'password' => Hash::make('CurrentPass1!'),
            'shop_owner_id' => null,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($customer, 'user')->post('/customer-profile/password', [
                'current_password' => 'WrongPass1!',
                'password' => 'NewStrongPass1!',
                'password_confirmation' => 'NewStrongPass1!',
            ]);
        }

        $last = $this->actingAs($customer, 'user')->post('/customer-profile/password', [
            'current_password' => 'WrongPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);

        $last->assertStatus(429);
    }
}
