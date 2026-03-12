<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_cannot_access_hr_module_routes(): void
    {
        // Create a SUPER_ADMIN user
        $superAdmin = User::factory()->create([
            'role' => 'SUPER_ADMIN',
        ]);

        // Authenticate via Sanctum token
        $token = $superAdmin->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
                         ->getJson('/api/hr/employees');

        $response->assertStatus(403)
                 ->assertJsonFragment(['error' => 'UNAUTHORIZED_ROLE']);
    }

    public function test_hr_handler_can_access_hr_module_routes(): void
    {
        // Create an approved shop owner
        $shopOwner = ShopOwner::factory()->create([
            'status' => 'approved',
        ]);

        // Create an HR user assigned to the shop
        $hrUser = User::factory()->create([
            'role' => 'HR',
            'shop_owner_id' => $shopOwner->id,
        ]);

        // Authenticate via Sanctum token
        $token = $hrUser->createToken('test')->plainTextToken;
        // Access HR employees index
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
                         ->getJson('/api/hr/employees');

        $response->assertStatus(200)
                 ->assertJsonFragment(['message' => 'Employees retrieved successfully']);
    }
}
