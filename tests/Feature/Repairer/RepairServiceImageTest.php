<?php

namespace Tests\Feature\Repairer;

use App\Models\InventoryItem;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepairServiceImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-upload-service', 'user');
        Permission::findOrCreate('access-pricing-services', 'user');
        Role::findOrCreate('Repairer', 'user');
    }

    public function test_repairer_can_create_service_with_a_scoped_public_image(): void
    {
        Storage::fake('public');
        [$shopOwner, $repairer, $material] = $this->serviceActors();

        $response = $this->actingAs($repairer, 'user')->post('/api/repair-services', [
            ...$this->servicePayload($material),
            'image' => UploadedFile::fake()->create('deep-clean.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.image_path');

        $service = RepairService::query()->latest('id')->firstOrFail();
        $this->assertStringStartsWith("repair-services/{$shopOwner->id}/", $service->image_path);
        Storage::disk('public')->assertExists($service->image_path);
        $response->assertJsonPath('data.image_url', Storage::disk('public')->url($service->image_path));
    }

    public function test_repairer_cannot_upload_a_non_image(): void
    {
        Storage::fake('public');
        [, $repairer, $material] = $this->serviceActors();

        $response = $this->actingAs($repairer, 'user')->post('/api/repair-services', [
            ...$this->servicePayload($material),
            'image' => UploadedFile::fake()->create('service.pdf', 20, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_repairer_cannot_upload_an_image_over_five_megabytes(): void
    {
        Storage::fake('public');
        [, $repairer, $material] = $this->serviceActors();

        $response = $this->actingAs($repairer, 'user')->post('/api/repair-services', [
            ...$this->servicePayload($material),
            'image' => UploadedFile::fake()->create('large.jpg', 5121, 'image/jpeg'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_repairer_can_replace_and_remove_a_service_image(): void
    {
        Storage::fake('public');
        [$shopOwner, $repairer] = $this->serviceActors();
        $oldPath = UploadedFile::fake()->create('old.jpg', 100, 'image/jpeg')->store("repair-services/{$shopOwner->id}", 'public');
        $service = $this->createService($shopOwner, ['image_path' => $oldPath]);

        $replaceResponse = $this->actingAs($repairer, 'user')->post("/api/repair-services/{$service->id}", [
            '_method' => 'PUT',
            'name' => $service->name,
            'category' => $service->category,
            'duration' => $service->duration,
            'description' => $service->description,
            'status' => $service->status,
            'image' => UploadedFile::fake()->create('replacement.png', 100, 'image/png'),
        ]);

        $replaceResponse->assertOk()->assertJsonMissingPath('data.image_path');
        $replacementPath = $service->fresh()->image_path;
        $this->assertNotSame($oldPath, $replacementPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($replacementPath);

        $removeResponse = $this->actingAs($repairer, 'user')->post("/api/repair-services/{$service->id}", [
            '_method' => 'PUT',
            'remove_image' => '1',
        ]);

        $removeResponse->assertOk()->assertJsonPath('data.image_url', null);
        $this->assertNull($service->fresh()->image_path);
        Storage::disk('public')->assertMissing($replacementPath);
    }

    public function test_repairer_cannot_change_another_shop_service_image(): void
    {
        Storage::fake('public');
        [$shopOwnerA, $repairerA] = $this->serviceActors();
        [$shopOwnerB, $repairerB] = $this->serviceActors();
        $oldPath = UploadedFile::fake()->create('shop-a.jpg', 100, 'image/jpeg')->store("repair-services/{$shopOwnerA->id}", 'public');
        $service = $this->createService($shopOwnerA, ['image_path' => $oldPath]);

        $response = $this->actingAs($repairerB, 'user')->post("/api/repair-services/{$service->id}", [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->create('shop-b.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertNotFound();
        $this->assertSame($oldPath, $service->fresh()->image_path);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame([], Storage::disk('public')->allFiles("repair-services/{$shopOwnerB->id}"));
        $this->assertNotSame($repairerA->id, $repairerB->id);
    }

    public function test_public_service_payload_contains_image_url_and_null_for_services_without_an_image(): void
    {
        Storage::fake('public');
        [$shopOwner] = $this->serviceActors();
        $imagePath = UploadedFile::fake()->create('care.jpg', 100, 'image/jpeg')->store("repair-services/{$shopOwner->id}", 'public');
        $withImage = $this->createService($shopOwner, ['name' => 'With image', 'image_path' => $imagePath]);
        $withoutImage = $this->createService($shopOwner, ['name' => 'Without image']);

        $response = $this->getJson('/api/repair-services?shop_id=' . $shopOwner->id);

        $response->assertOk()
            ->assertJsonPath('data.0.image_path', null);

        $services = collect($response->json('data'))->keyBy('id');
        $this->assertSame(Storage::disk('public')->url($imagePath), $services[$withImage->id]['image_url']);
        $this->assertNull($services[$withoutImage->id]['image_url']);
        $this->assertArrayNotHasKey('image_path', $services[$withImage->id]);
    }

    private function serviceActors(): array
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
            'role' => 'STAFF',
        ]);
        $repairer->assignRole('Repairer');
        $repairer->givePermissionTo('access-upload-service');
        $this->clockInEmployee($repairer);

        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'category' => 'repair_materials',
            'name' => 'Cleaning solution',
            'sku' => 'MAT-' . $shopOwner->id . '-' . fake()->unique()->numerify('###'),
            'available_quantity' => 20,
            'is_active' => true,
        ]);

        return [$shopOwner, $repairer, $material];
    }

    private function servicePayload(InventoryItem $material): array
    {
        return [
            'name' => 'Deep Clean',
            'category' => 'Care',
            'price' => '600',
            'duration' => '1 to 2 days',
            'description' => 'Detailed shoe cleaning',
            'status' => 'Active',
            'material_templates' => [
                ['inventory_item_id' => $material->id, 'default_quantity' => 1],
            ],
        ];
    }

    private function createService(ShopOwner $shopOwner, array $overrides = []): RepairService
    {
        return RepairService::create(array_merge([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Deep Clean',
            'category' => 'Care',
            'price' => 600,
            'duration' => '1 to 2 days',
            'description' => 'Detailed shoe cleaning',
            'status' => 'Active',
        ], $overrides));
    }
}
