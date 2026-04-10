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
}
