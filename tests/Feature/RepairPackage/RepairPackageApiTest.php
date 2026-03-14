<?php

namespace Tests\Feature\RepairPackage;

use App\Models\RepairPackage;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RepairPackageApiTest extends TestCase
{
    use RefreshDatabase;

    private function createService(ShopOwner $shopOwner, array $overrides = []): RepairService
    {
        return RepairService::create(array_merge([
            'name' => 'Deep Clean',
            'category' => 'Care',
            'price' => 500,
            'duration' => '45 min',
            'description' => 'Basic service',
            'status' => 'Active',
            'shop_owner_id' => $shopOwner->id,
        ], $overrides));
    }

    private function createPackageBooking(User $customer, ShopOwner $shopOwner, RepairPackage $package, array $overrides = []): RepairRequest
    {
        return RepairRequest::create(array_merge([
            'request_id' => 'REP-T-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
            'customer_name' => 'Package Metrics Customer',
            'email' => $customer->email,
            'phone' => '09170000000',
            'description' => 'Analytics booking',
            'shop_owner_id' => $shopOwner->id,
            'repair_package_id' => $package->id,
            'user_id' => $customer->id,
            'images' => ['repair-requests/test.jpg'],
            'total' => $package->package_price,
            'package_price' => $package->package_price,
            'add_ons_total' => 0,
            'final_total' => $package->package_price,
            'pricing_breakdown' => [
                'mode' => 'package',
                'package_id' => $package->id,
                'package_name' => $package->name,
            ],
            'status' => 'new_request',
            'delivery_method' => 'walk_in',
        ], $overrides));
    }

    public function test_shop_owner_can_create_repair_package_with_own_services(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $s1 = $this->createService($shopOwner, ['name' => 'Deep Clean', 'price' => 500]);
        $s2 = $this->createService($shopOwner, ['name' => 'Sole Reglue', 'price' => 700]);

        $response = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-packages', [
            'name' => 'Starter Restore Bundle',
            'description' => 'Includes two services',
            'package_price' => 1000,
            'status' => 'active',
            'service_ids' => [$s1->id, $s2->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Starter Restore Bundle');

        $this->assertDatabaseHas('repair_packages', [
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Starter Restore Bundle',
        ]);

        $packageId = $response->json('data.id');
        $this->assertDatabaseHas('repair_package_service', [
            'repair_package_id' => $packageId,
            'repair_service_id' => $s1->id,
        ]);
        $this->assertDatabaseHas('repair_package_service', [
            'repair_package_id' => $packageId,
            'repair_service_id' => $s2->id,
        ]);
    }

    public function test_shop_owner_cannot_include_other_shop_services_in_package(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $otherShopOwner = ShopOwner::factory()->approved()->create();

        $ownService = $this->createService($shopOwner, ['name' => 'Own Service']);
        $foreignService = $this->createService($otherShopOwner, ['name' => 'Foreign Service']);

        $response = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-packages', [
            'name' => 'Invalid Mixed Package',
            'package_price' => 900,
            'service_ids' => [$ownService->id, $foreignService->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['service_ids']]);

        $failedPackage = RepairPackage::withTrashed()
            ->where('shop_owner_id', $shopOwner->id)
            ->where('name', 'Invalid Mixed Package')
            ->first();

        $this->assertNotNull($failedPackage);
        $this->assertNotNull($failedPackage->deleted_at);
    }

    public function test_shop_owner_cannot_access_other_shop_package(): void
    {
        $shopA = ShopOwner::factory()->approved()->create();
        $shopB = ShopOwner::factory()->approved()->create();

        $a1 = $this->createService($shopA, ['name' => 'A Service 1']);
        $a2 = $this->createService($shopA, ['name' => 'A Service 2']);

        $package = RepairPackage::create([
            'shop_owner_id' => $shopA->id,
            'name' => 'A Exclusive',
            'package_price' => 1000,
            'status' => 'active',
        ]);
        $package->syncIncludedServices([$a1->id, $a2->id]);

        $this->actingAs($shopB, 'shop_owner')
            ->getJson("/api/repair-packages/{$package->id}")
            ->assertStatus(403);

        $this->actingAs($shopB, 'shop_owner')
            ->putJson("/api/repair-packages/{$package->id}", [
                'name' => 'B Hack Edit',
                'service_ids' => [$a1->id, $a2->id],
            ])
            ->assertStatus(403);

        $this->actingAs($shopB, 'shop_owner')
            ->deleteJson("/api/repair-packages/{$package->id}")
            ->assertStatus(403);
    }

    public function test_index_is_scoped_to_authenticated_shop_owner(): void
    {
        $shopA = ShopOwner::factory()->approved()->create();
        $shopB = ShopOwner::factory()->approved()->create();

        $a1 = $this->createService($shopA, ['name' => 'A Service 1']);
        $a2 = $this->createService($shopA, ['name' => 'A Service 2']);
        $b1 = $this->createService($shopB, ['name' => 'B Service 1']);
        $b2 = $this->createService($shopB, ['name' => 'B Service 2']);

        $packageA = RepairPackage::create([
            'shop_owner_id' => $shopA->id,
            'name' => 'Package A',
            'package_price' => 999,
            'status' => 'active',
        ]);
        $packageA->syncIncludedServices([$a1->id, $a2->id]);

        $packageB = RepairPackage::create([
            'shop_owner_id' => $shopB->id,
            'name' => 'Package B',
            'package_price' => 899,
            'status' => 'active',
        ]);
        $packageB->syncIncludedServices([$b1->id, $b2->id]);

        $response = $this->actingAs($shopA, 'shop_owner')->getJson('/api/repair-packages');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Package A', $names);
        $this->assertNotContains('Package B', $names);
    }

    public function test_public_package_index_returns_only_active_packages_for_selected_shop(): void
    {
        $shopA = ShopOwner::factory()->approved()->create();
        $shopB = ShopOwner::factory()->approved()->create();

        $a1 = $this->createService($shopA, ['name' => 'A Service 1', 'price' => 400]);
        $a2 = $this->createService($shopA, ['name' => 'A Service 2', 'price' => 700]);
        $b1 = $this->createService($shopB, ['name' => 'B Service 1', 'price' => 500]);
        $b2 = $this->createService($shopB, ['name' => 'B Service 2', 'price' => 900]);

        $activeA = RepairPackage::create([
            'shop_owner_id' => $shopA->id,
            'name' => 'Active A',
            'package_price' => 900,
            'status' => 'active',
        ]);
        $activeA->syncIncludedServices([$a1->id, $a2->id]);

        $inactiveA = RepairPackage::create([
            'shop_owner_id' => $shopA->id,
            'name' => 'Inactive A',
            'package_price' => 1000,
            'status' => 'inactive',
        ]);
        $inactiveA->syncIncludedServices([$a1->id, $a2->id]);

        $activeB = RepairPackage::create([
            'shop_owner_id' => $shopB->id,
            'name' => 'Active B',
            'package_price' => 1200,
            'status' => 'active',
        ]);
        $activeB->syncIncludedServices([$b1->id, $b2->id]);

        $response = $this->getJson('/api/repair-packages/public?shop_id=' . $shopA->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Active A', $names);
        $this->assertNotContains('Inactive A', $names);
        $this->assertNotContains('Active B', $names);
    }

    public function test_customer_can_submit_repair_request_using_selected_package(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create([
            'email' => 'package-customer@example.com',
        ]);

        $s1 = $this->createService($shopOwner, ['name' => 'Deep Clean', 'price' => 500]);
        $s2 = $this->createService($shopOwner, ['name' => 'Sole Reglue', 'price' => 800]);

        $package = RepairPackage::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Customer Bundle',
            'description' => 'Best value package',
            'package_price' => 1100,
            'status' => 'active',
        ]);
        $package->syncIncludedServices([$s1->id, $s2->id]);

        $response = $this->actingAs($customer, 'user')->post('/api/repair-requests', [
            'customer_name' => 'Test Customer',
            'email' => 'package-customer@example.com',
            'phone' => '09170000000',
            'description' => 'Need full restore package',
            'shop_owner_id' => $shopOwner->id,
            'repair_package_id' => $package->id,
            'total' => 99999,
            'service_type' => 'walkin',
            'images' => [
                UploadedFile::fake()->create('shoe-front.jpg', 120, 'image/jpeg'),
            ],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.repair_package_id', $package->id)
            ->assertJsonPath('data.total', 1100);

        $this->assertDatabaseHas('repair_requests', [
            'repair_package_id' => $package->id,
            'shop_owner_id' => $shopOwner->id,
        ]);

        $createdRepair = \App\Models\RepairRequest::query()
            ->where('repair_package_id', $package->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($createdRepair);
        $this->assertEquals(1100.0, (float) $createdRepair->total);
        $this->assertDatabaseHas('repair_request_service', [
            'repair_request_id' => $createdRepair->id,
            'repair_service_id' => $s1->id,
        ]);
        $this->assertDatabaseHas('repair_request_service', [
            'repair_request_id' => $createdRepair->id,
            'repair_service_id' => $s2->id,
        ]);
    }

    public function test_customer_can_submit_package_with_add_ons_and_server_recomputes_total(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();

        $includedA = $this->createService($shopOwner, ['name' => 'Deep Clean', 'price' => 450]);
        $includedB = $this->createService($shopOwner, ['name' => 'Sole Reglue', 'price' => 650]);
        $addOn = $this->createService($shopOwner, ['name' => 'Lace Replacement', 'price' => 200]);

        $package = RepairPackage::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Premium Restore',
            'package_price' => 900,
            'status' => 'active',
        ]);
        $package->syncIncludedServices([$includedA->id, $includedB->id]);

        $response = $this->actingAs($customer, 'user')->post('/api/repair-requests', [
            'customer_name' => 'Package Add-on Customer',
            'email' => $customer->email,
            'phone' => '09171234567',
            'description' => 'Package plus extra work',
            'shop_owner_id' => $shopOwner->id,
            'repair_package_id' => $package->id,
            'add_on_service_ids' => [$addOn->id],
            'total' => 1,
            'service_type' => 'walkin',
            'images' => [
                UploadedFile::fake()->create('shoe-side.jpg', 120, 'image/jpeg'),
            ],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.repair_package_id', $package->id)
            ->assertJsonPath('data.total', 1100);

        $createdRepair = \App\Models\RepairRequest::query()
            ->where('repair_package_id', $package->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($createdRepair);
        $this->assertEquals(900.0, (float) $createdRepair->package_price);
        $this->assertEquals(200.0, (float) $createdRepair->add_ons_total);
        $this->assertEquals(1100.0, (float) $createdRepair->final_total);
        $this->assertEquals(1100.0, (float) $createdRepair->total);
        $this->assertCount(2, $createdRepair->included_services_snapshot);
        $this->assertCount(1, $createdRepair->add_on_services_snapshot);
        $this->assertEquals('package', $createdRepair->pricing_breakdown['mode']);

        $this->assertDatabaseHas('repair_request_service', [
            'repair_request_id' => $createdRepair->id,
            'repair_service_id' => $includedA->id,
        ]);
        $this->assertDatabaseHas('repair_request_service', [
            'repair_request_id' => $createdRepair->id,
            'repair_service_id' => $includedB->id,
        ]);
        $this->assertDatabaseHas('repair_request_service', [
            'repair_request_id' => $createdRepair->id,
            'repair_service_id' => $addOn->id,
        ]);
    }

    public function test_package_request_can_be_moved_to_processing_and_keeps_package_breakdown(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create([
            'email' => 'processing-package@example.com',
        ]);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $includedA = $this->createService($shopOwner, ['name' => 'Deep Clean', 'price' => 500]);
        $includedB = $this->createService($shopOwner, ['name' => 'Sole Reglue', 'price' => 800]);

        $package = RepairPackage::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Processing Bundle',
            'description' => 'For workflow verification',
            'package_price' => 1100,
            'status' => 'active',
        ]);
        $package->syncIncludedServices([$includedA->id, $includedB->id]);

        $createResponse = $this->actingAs($customer, 'user')->post('/api/repair-requests', [
            'customer_name' => 'Workflow Package Customer',
            'email' => 'processing-package@example.com',
            'phone' => '09175550000',
            'description' => 'Need package request processed',
            'shop_owner_id' => $shopOwner->id,
            'repair_package_id' => $package->id,
            'total' => 99999,
            'service_type' => 'walkin',
            'images' => [
                UploadedFile::fake()->create('workflow-package.jpg', 120, 'image/jpeg'),
            ],
        ], [
            'Accept' => 'application/json',
        ]);

        $createResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.repair_package_id', $package->id)
            ->assertJsonPath('data.total', 1100);

        $repairRequest = RepairRequest::query()
            ->where('repair_package_id', $package->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($repairRequest);
        $this->assertContains($repairRequest->status, ['new_request', 'assigned_to_repairer', 'assignment_failed']);

        $processResponse = $this->actingAs($repairer, 'user')->postJson(
            "/api/repair-requests/{$repairRequest->request_id}/status",
            ['status' => 'in-progress']
        );

        $processResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Status updated successfully');

        $repairRequest->refresh();

        $this->assertSame('in-progress', $repairRequest->status);
        $this->assertNotNull($repairRequest->started_at);
        $this->assertEquals(1100.0, (float) $repairRequest->total);
        $this->assertEquals(1100.0, (float) $repairRequest->package_price);
        $this->assertEquals(0.0, (float) $repairRequest->add_ons_total);
        $this->assertEquals(1100.0, (float) $repairRequest->final_total);
        $this->assertSame('package', $repairRequest->pricing_breakdown['mode']);
        $this->assertSame('Processing Bundle', $repairRequest->pricing_breakdown['package_name']);
        $this->assertCount(2, $repairRequest->included_services_snapshot);
    }

    public function test_customer_cannot_submit_package_add_on_that_is_already_included(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();

        $includedService = $this->createService($shopOwner, ['name' => 'Deep Clean', 'price' => 450]);
        $otherIncludedService = $this->createService($shopOwner, ['name' => 'Repaint', 'price' => 300]);

        $package = RepairPackage::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Starter Pack',
            'package_price' => 700,
            'status' => 'active',
        ]);
        $package->syncIncludedServices([$includedService->id, $otherIncludedService->id]);

        $response = $this->actingAs($customer, 'user')->post('/api/repair-requests', [
            'customer_name' => 'Invalid Add-on Customer',
            'email' => $customer->email,
            'phone' => '09171234567',
            'description' => 'Trying duplicate add-on',
            'shop_owner_id' => $shopOwner->id,
            'repair_package_id' => $package->id,
            'add_on_service_ids' => [$includedService->id],
            'total' => 9999,
            'service_type' => 'walkin',
            'images' => [
                UploadedFile::fake()->create('shoe-top.jpg', 120, 'image/jpeg'),
            ],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Included package services cannot be submitted as add-ons.');
    }

    public function test_customer_cannot_submit_package_add_on_from_other_shop(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $otherShopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();

        $includedA = $this->createService($shopOwner, ['name' => 'Deep Clean', 'price' => 450]);
        $includedB = $this->createService($shopOwner, ['name' => 'Repaint', 'price' => 300]);
        $foreignAddOn = $this->createService($otherShopOwner, ['name' => 'Foreign Add-on', 'price' => 250]);

        $package = RepairPackage::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Starter Pack',
            'package_price' => 700,
            'status' => 'active',
        ]);
        $package->syncIncludedServices([$includedA->id, $includedB->id]);

        $response = $this->actingAs($customer, 'user')->post('/api/repair-requests', [
            'customer_name' => 'Foreign Add-on Customer',
            'email' => $customer->email,
            'phone' => '09171234567',
            'description' => 'Trying wrong shop add-on',
            'shop_owner_id' => $shopOwner->id,
            'repair_package_id' => $package->id,
            'add_on_service_ids' => [$foreignAddOn->id],
            'total' => 9999,
            'service_type' => 'walkin',
            'images' => [
                UploadedFile::fake()->create('shoe-bottom.jpg', 120, 'image/jpeg'),
            ],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Some add-on services are invalid for the selected package/shop.');
    }

    public function test_shop_owner_can_view_scoped_package_analytics(): void
    {
        $shopA = ShopOwner::factory()->approved()->create();
        $shopB = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();

        $a1 = $this->createService($shopA, ['name' => 'Deep Clean', 'price' => 500]);
        $a2 = $this->createService($shopA, ['name' => 'Sole Reglue', 'price' => 700]);
        $b1 = $this->createService($shopB, ['name' => 'B Service 1', 'price' => 400]);
        $b2 = $this->createService($shopB, ['name' => 'B Service 2', 'price' => 600]);

        $packageA = RepairPackage::create([
            'shop_owner_id' => $shopA->id,
            'name' => 'Package A',
            'package_price' => 900,
            'status' => 'active',
        ]);
        $packageA->syncIncludedServices([$a1->id, $a2->id]);

        $packageB = RepairPackage::create([
            'shop_owner_id' => $shopB->id,
            'name' => 'Package B',
            'package_price' => 800,
            'status' => 'active',
        ]);
        $packageB->syncIncludedServices([$b1->id, $b2->id]);

        $this->createPackageBooking($customer, $shopA, $packageA, [
            'total' => 1100,
            'package_price' => 900,
            'add_ons_total' => 200,
            'final_total' => 1100,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
            'status' => 'completed',
        ]);

        $this->createPackageBooking($customer, $shopA, $packageA, [
            'total' => 900,
            'package_price' => 900,
            'add_ons_total' => 0,
            'final_total' => 900,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
            'status' => 'new_request',
        ]);

        $this->createPackageBooking($customer, $shopB, $packageB, [
            'total' => 1000,
            'package_price' => 800,
            'add_ons_total' => 200,
            'final_total' => 1000,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($shopA, 'shop_owner')->getJson('/api/repair-packages/analytics');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.overview.total_packages', 1)
            ->assertJsonPath('data.overview.total_bookings', 2)
            ->assertJsonPath('data.overview.package_revenue', 2000)
            ->assertJsonPath('data.overview.add_on_revenue', 200)
            ->assertJsonPath('data.overview.add_on_attach_rate', 50)
            ->assertJsonPath('data.top_packages.0.name', 'Package A')
            ->assertJsonPath('data.top_packages.0.booking_count', 2)
            ->assertJsonPath('data.top_packages.0.revenue', 2000);

        $this->assertCount(1, $response->json('data.top_packages'));
    }
}
