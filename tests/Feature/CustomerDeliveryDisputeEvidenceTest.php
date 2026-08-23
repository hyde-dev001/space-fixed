<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\DeliveryDispute;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\TestCase;

class CustomerDeliveryDisputeEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_shop_owned_report_stores_exactly_five_images_and_one_opening_parcel_video(): void
    {
        [$order, $customer] = $this->deliveredShopOwnedOrder();

        $response = $this->actingAs($customer, 'user')
            ->post("/orders/{$order->id}/delivery-disputes", [
                'reason' => 'damaged',
                'notes' => 'The item was damaged when I opened the parcel.',
                'media' => $this->validMedia(),
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dispute.status', 'open');

        $dispute = DeliveryDispute::query()->firstOrFail();
        $this->assertCount(6, $dispute->evidence_media);
        $this->assertCount(5, array_filter($dispute->evidence_media, fn (array $media): bool => $media['kind'] === 'image'));
        $this->assertCount(1, array_filter($dispute->evidence_media, fn (array $media): bool => $media['kind'] === 'video'));

        foreach ($dispute->evidence_media as $media) {
            $this->assertStringStartsWith("delivery-dispute-evidence/order-{$order->id}/", $media['path']);
            Storage::disk('local')->assertExists($media['path']);
        }
    }

    public function test_shop_owned_report_rejects_a_media_set_without_exactly_five_images_and_one_video(): void
    {
        [$order, $customer] = $this->deliveredShopOwnedOrder();

        $response = $this->actingAs($customer, 'user')
            ->post("/orders/{$order->id}/delivery-disputes", [
                'reason' => 'damaged',
                'media' => array_slice($this->validMedia(), 0, 5),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('media');

        $this->assertDatabaseCount('delivery_disputes', 0);
    }

    public function test_item_not_received_report_does_not_require_media_evidence(): void
    {
        [$order, $customer] = $this->deliveredShopOwnedOrder();

        $response = $this->actingAs($customer, 'user')
            ->post("/orders/{$order->id}/delivery-disputes", [
                'reason' => 'item_not_received',
                'notes' => 'The parcel was marked delivered, but I did not receive the item.',
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dispute.status', 'open')
            ->assertJsonPath('dispute.reason', 'item_not_received');

        $dispute = DeliveryDispute::query()->firstOrFail();

        $this->assertSame([], $dispute->evidence_media);
    }

    public function test_third_party_order_cannot_use_the_shop_owned_report_endpoint(): void
    {
        [$order, $customer] = $this->deliveredShopOwnedOrder('Third-party Logistics');

        $response = $this->actingAs($customer, 'user')
            ->post("/orders/{$order->id}/delivery-disputes", [
                'reason' => 'damaged',
                'media' => $this->validMedia(),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('order');

        $this->assertDatabaseCount('delivery_disputes', 0);
    }

    public function test_only_shop_staff_or_dispatcher_can_view_private_report_evidence(): void
    {
        [$order, $customer] = $this->deliveredShopOwnedOrder();

        $disputeId = $this->actingAs($customer, 'user')
            ->post("/orders/{$order->id}/delivery-disputes", [
                'reason' => 'damaged',
                'media' => $this->validMedia(),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('dispute.id');

        $dispute = DeliveryDispute::query()->findOrFail($disputeId);
        $media = $dispute->evidence_media[0];

        Permission::findOrCreate('access-staff-job-orders', 'user');
        Permission::findOrCreate('resolve-logistics-exceptions', 'user');
        $dispatcher = User::factory()->create(['shop_owner_id' => $order->shop_owner_id]);
        $dispatcher->givePermissionTo('resolve-logistics-exceptions');
        $staff = User::factory()->create(['shop_owner_id' => $order->shop_owner_id]);
        $staff->givePermissionTo('access-staff-job-orders');

        $this->actingAs($dispatcher, 'user')
            ->get("/api/logistics/delivery-disputes/{$dispute->id}/evidence/{$media['id']}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs($staff, 'user')
            ->get("/api/logistics/delivery-disputes/{$dispute->id}/evidence/{$media['id']}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs($customer, 'user')
            ->get("/api/logistics/delivery-disputes/{$dispute->id}/evidence/{$media['id']}")
            ->assertForbidden();
    }

    public function test_dispatcher_shipment_payload_exposes_report_evidence_without_raw_storage_paths(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'purpose' => 'retail_delivery',
            'status' => 'completed',
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'delivered',
        ]);
        [$order, $customer] = $this->deliveredShopOwnedOrder();
        $order->update([
            'shop_owner_id' => $shop->id,
            'carrier_company' => 'Shop-owned logistics',
        ]);
        $shipment->update(['source_id' => $order->id]);

        $disputeId = $this->actingAs($customer, 'user')
            ->post("/orders/{$order->id}/delivery-disputes", [
                'reason' => 'damaged',
                'media' => $this->validMedia(),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('dispute.id');

        $response = $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/shipments?status=customer_disputes')
            ->assertOk();
        $payload = collect($response->viewData('page')['props']['shipments']['data'])
            ->firstWhere('id', $shipment->id);
        $evidence = $payload['customer_disputes'][0]['evidence'][0];

        $this->assertSame(
            "/api/logistics/delivery-disputes/{$disputeId}/evidence/{$evidence['id']}",
            parse_url($evidence['url'], PHP_URL_PATH),
        );
        $this->assertArrayNotHasKey('path', $evidence);
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function deliveredShopOwnedOrder(string $carrier = 'Shop-owned logistics'): array
    {
        $shop = ShopOwner::factory()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::DELIVERED,
            'carrier_company' => $carrier,
            'customer_receipt_status' => 'pending',
        ]);

        return [$order, $customer];
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function validMedia(): array
    {
        return [
            UploadedFile::fake()->create('evidence-1.jpg', 1024, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-2.jpg', 1024, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-3.jpg', 1024, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-4.jpg', 1024, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-5.jpg', 1024, 'image/jpeg'),
            UploadedFile::fake()->create('opening-parcel.mp4', 1024, 'video/mp4'),
        ];
    }
}
