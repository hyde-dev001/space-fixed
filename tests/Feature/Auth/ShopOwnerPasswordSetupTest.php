<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopOwnerPasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_owner_password_setup_requires_at_least_twelve_characters(): void
    {
        $this->post(route('shop-owner.password.setup.store'), [
            'token' => 'setup-token',
            'email' => 'shop-owner@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertSessionHasErrors('password');
    }
}
