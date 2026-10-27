<?php
require __DIR__.'/failed_delivery_fixture.php';

use App\Models\{Employee, Order, OrderItem, Product, ShopOwner, User};
use App\Models\Logistics\{DeliveryAssignment, LogisticsSetting, Shipment, ShipmentLeg};
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\{Permission, Role};

if (($argv[1] ?? '') === '--setup') {
    Artisan::call('migrate', ['--force' => true]);
    $fixture = recoveryFixture('retail_delivery');
    $staff = User::factory()->create(['shop_owner_id' => $fixture['shop_id'], 'email' => 'parcel-staff-'.\Illuminate\Support\Str::uuid().'@recovery.example.test',
        'password' => 'Local-Recovery-Test-72!', 'role' => 'STAFF', 'status' => 'active', 'force_password_change' => false]);
    $staff->forceFill(['employee_totp_secret' => 'JBSWY3DPEHPK3PXP', 'employee_totp_enabled_at' => now()])->save();
    $role = Role::findOrCreate('Staff', 'user');
    $staff->assignRole($role);
    foreach (['access-staff-job-orders', 'access-profile', 'access-notification-center'] as $permission) $staff->givePermissionTo(Permission::findOrCreate($permission, 'user'));
    Employee::factory()->active()->create(['shop_owner_id' => $fixture['shop_id'], 'email' => $staff->email, 'department' => 'Operations', 'position' => 'Staff']);
    $fixture['actors']['staff'] = ['id' => $staff->id, 'email' => $staff->email];
    $initial = ShipmentLeg::findOrFail($fixture['leg_id']);
    $initial->update(['status' => 'cancelled', 'rider_progress_state' => 'rider_released']);
    $initial->shipment->update(['status' => 'cancelled']);
    \App\Models\ShopOwnerModule::updateOrCreate(['shop_owner_id' => $fixture['shop_id'], 'module_key' => 'retail_operations'], ['enabled' => true]);
    LogisticsSetting::where('shop_owner_id', $fixture['shop_id'])->update(['morning_start' => '00:00', 'morning_end' => '23:59', 'afternoon_start' => '00:00', 'afternoon_end' => '23:59']);
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
    exit;
}
$fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$shop = ShopOwner::findOrFail($fixture['shop_id']);
if ($shop->email === null || ! str_ends_with($fixture['actors']['staff']['email'], '@recovery.example.test')) throw new RuntimeException('Unexpected browser fixture identity.');
$order = Order::factory()->create(['shop_owner_id' => $shop->id, 'payment_method' => 'paymongo_card', 'payment_status' => 'paid', 'status' => 'shipped',
    'paymongo_payment_id' => 'synthetic-captured-'.\Illuminate\Support\Str::uuid(), 'delivery_method' => 'shop_owned']);
$product = Product::create(['shop_owner_id' => $shop->id, 'stock_quantity' => 8, 'name' => 'Synthetic original parcel', 'slug' => 'parcel-'.\Illuminate\Support\Str::uuid(), 'price' => 100, 'is_active' => true]);
$item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'price' => 100, 'quantity' => 1, 'subtotal' => 100]);
$shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $order->id, 'purpose' => 'retail_delivery', 'status' => 'active']);
$leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'picked_up', 'picked_up_at' => now(), 'custody_state' => 'rider',
    'custody_rider_profile_id' => $fixture['actors']['a']['rider_id'], 'custody_confirmed_at' => now(), 'requires_pickup_proof' => false,
    'origin_snapshot' => ['type' => 'shop', 'name' => 'Synthetic shop'], 'destination_snapshot' => ['type' => 'customer', 'name' => 'Synthetic destination']]);
$assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $fixture['actors']['a']['rider_id'], 'status' => 'accepted']);
echo json_encode(['leg_id' => $leg->id, 'assignment_id' => $assignment->id, 'item_id' => $item->id, 'order_id' => $order->id, 'stock' => $product->stock_quantity], JSON_THROW_ON_ERROR);
