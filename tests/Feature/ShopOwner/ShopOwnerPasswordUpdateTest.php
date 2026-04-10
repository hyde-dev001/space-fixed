<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopOwnerPasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_owner_can_update_password_with_correct_current_password(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('CurrentPass1!'),
        ]);

        $response = $this->actingAs($owner, 'shop_owner')->post('/shop-owner/shop-profile/password', [
            'current_password' => 'CurrentPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $owner->refresh();
        $this->assertTrue(Hash::check('NewStrongPass1!', $owner->password));
    }

    #[Test]
    public function shop_owner_password_route_is_throttled_after_five_attempts(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('CurrentPass1!'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner, 'shop_owner')->post('/shop-owner/shop-profile/password', [
                'current_password' => 'WrongPass1!',
                'password' => 'NewStrongPass1!',
                'password_confirmation' => 'NewStrongPass1!',
            ]);
        }

        $last = $this->actingAs($owner, 'shop_owner')->post('/shop-owner/shop-profile/password', [
            'current_password' => 'WrongPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);

        $last->assertStatus(429);
    }
}
