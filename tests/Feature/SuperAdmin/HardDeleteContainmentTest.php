<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

class HardDeleteContainmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_routine_hard_delete_route_names_and_controller_methods_are_absent(): void
    {
        foreach (['admin.admins.delete', 'admin.shops.delete', 'admin.users.delete'] as $routeName) {
            $this->assertNull(Route::getRoutes()->getByName($routeName), "Forbidden route still exists: {$routeName}");
        }

        $controller = new ReflectionClass(\App\Http\Controllers\SuperAdminController::class);
        foreach (['deleteAdmin', 'deleteShop', 'deleteUser'] as $method) {
            $this->assertFalse($controller->hasMethod($method), "Forbidden controller method still exists: {$method}");
        }
    }

    public function test_delete_requests_are_not_registered_and_records_remain_untouched(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $shop = ShopOwner::factory()->approved()->create();
        $document = ShopDocument::create([
            'shop_owner_id' => $shop->id,
            'document_type' => 'mayors_permit',
            'file_path' => 'shop_documents/retained.pdf',
            'status' => 'approved',
        ]);
        $user = User::factory()->create();
        $address = UserAddress::create([
            'user_id' => $user->id,
            'name' => 'Customer',
            'phone' => '555-0100',
            'region' => 'Region',
            'province' => 'Province',
            'city' => 'City',
            'barangay' => 'Barangay',
            'postal_code' => '1000',
            'address_line' => '1 Main Street',
            'is_default' => true,
        ]);

        $this->actingAs($admin, 'super_admin');

        foreach ([
            "/admin/admins/{$admin->id}",
            "/admin/shops/{$shop->id}",
            "/admin/users/{$user->id}",
        ] as $uri) {
            $this->assertContains($this->delete($uri)->getStatusCode(), [404, 405]);
        }

        $this->assertDatabaseHas('super_admins', ['id' => $admin->id]);
        $this->assertDatabaseHas('shop_owners', ['id' => $shop->id]);
        $this->assertDatabaseHas('shop_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('user_addresses', ['id' => $address->id]);
    }
}
