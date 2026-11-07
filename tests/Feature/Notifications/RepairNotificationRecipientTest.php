<?php

namespace Tests\Feature\Notifications;

use App\Models\Employee;
use App\Models\Notification;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RepairNotificationRecipientTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repair_submission_notifies_the_assigned_repairer_but_not_generic_staff(): void
    {
        Storage::fake('public');

        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);
        $customer = User::factory()->create([
            'identity_verification_status' => User::IDENTITY_APPROVED,
        ]);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
        ]);
        Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $repairer->email,
        ]);
        $repairer->assignRole(Role::findOrCreate('Repairer', 'user'));

        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
        ]);
        Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $staff->email,
        ]);
        $staffRole = Role::findOrCreate('Staff', 'user');
        $staffRole->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $staff->assignRole($staffRole);

        $service = RepairService::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Repair notification service',
            'category' => 'Cleaning',
            'price' => 500,
            'duration' => '2 days',
            'status' => 'active',
        ]);

        $this->actingAs($customer, 'user')
            ->post('/api/repair-requests', [
                'customer_name' => $customer->name,
                'email' => $customer->email,
                'phone' => '09171234567',
                'shoe_type' => 'Sneakers',
                'shop_owner_id' => $shop->id,
                'services' => [$service->id],
                'images' => [UploadedFile::fake()->create('shoe.jpg', 100, 'image/jpeg')],
                'total' => 500,
                'intake_delivery_method' => 'walk_in',
                'return_delivery_method' => 'walk_in',
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $staff->id,
            'type' => 'new_repair_request',
        ]);
        $this->assertSame(0, Notification::query()
            ->where('user_id', $staff->id)
            ->where('action_url', '/erp/staff/job-orders-repair')
            ->count());
        $this->assertSame($repairer->id, (int) \App\Models\RepairRequest::query()->latest('id')->value('assigned_repairer_id'));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $repairer->id,
            'type' => 'repair_assigned_to_me',
            'action_url' => '/erp/staff/job-orders-repair',
        ]);
    }
}
