<?php

namespace Tests\Feature\Notifications;

use App\Models\Employee;
use App\Models\Notification;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class NewOrderStaffNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function active_staff_with_staff_order_access_receives_new_product_order_notification(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'email' => 'retail-staff@example.test',
            'status' => 'active',
        ]);

        Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $staff->email,
        ]);

        $staffRole = Role::findOrCreate('Staff', 'user');
        $staffOrderPermission = Permission::findOrCreate('access-staff-job-orders', 'user');
        $staffRole->givePermissionTo($staffOrderPermission);
        $staff->assignRole($staffRole);

        $notifiedCount = app(NotificationService::class)->notifyAllStaffNewOrder($shop->id, [
            'order_id' => 901,
            'order_number' => 'ORD-STAFF-901',
            'total' => '2499.00',
            'items_count' => 1,
            'customer_name' => 'Customer Example',
        ]);

        $this->assertSame(1, $notifiedCount);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $staff->id,
            'type' => 'new_order',
            'title' => 'New Order Received',
            'action_url' => '/erp/staff/job-orders',
            'shop_id' => $shop->id,
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $staff->id)->count());
    }
}
