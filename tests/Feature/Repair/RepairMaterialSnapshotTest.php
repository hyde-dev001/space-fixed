<?php

namespace Tests\Feature\Repair;

use App\Models\InventoryItem;
use App\Models\RepairPackage;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepairMaterialSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;
    private User $customer;
    private User $repairer;
    private InventoryItem $glue;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $this->customer = User::factory()->create(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $this->repairer = User::factory()->create(['shop_owner_id' => $this->shop->id, 'role' => 'STAFF']);
        $this->repairer->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Repairer', 'user'));
        $this->clockInEmployee($this->repairer);
        $this->glue = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shop->id, 'category' => 'repair_materials', 'available_quantity' => 100,
        ]);
    }

    private function service(float $quantity = 0, bool $critical = false, float $tolerance = 20): RepairService
    {
        $service = RepairService::create([
            'shop_owner_id' => $this->shop->id, 'name' => 'Service '.str()->uuid(),
            'category' => 'General', 'price' => 500, 'duration' => '1 day', 'status' => 'Active',
        ]);
        if ($quantity > 0) {
            $service->materialTemplateItems()->create([
                'shop_owner_id' => $this->shop->id, 'inventory_item_id' => $this->glue->id,
                'template_type' => 'repair_service', 'default_quantity' => $quantity, 'is_critical' => $critical, 'tolerance_percent' => $tolerance,
            ]);
        }

        return $service;
    }

    private function book(array $services, array $overrides = []): RepairRequest
    {
        $response = $this->actingAs($this->customer, 'user')->post('/api/repair-requests', array_merge([
            'customer_name' => 'Snapshot Customer', 'email' => $this->customer->email,
            'phone' => '09170000000', 'shoe_type' => 'Sneakers', 'shop_owner_id' => $this->shop->id,
            'services' => $services ?: null, 'total' => 1000, 'service_type' => 'walkin',
            'images' => [UploadedFile::fake()->create('shoe.jpg', 100, 'image/jpeg')],
        ], $overrides), ['Accept' => 'application/json']);
        $response->assertOk()->assertJsonPath('success', true);
        $repair = RepairRequest::where('request_id', $response->json('data.request_id'))->firstOrFail();
        $repair->update(['assigned_repairer_id' => $this->repairer->id]);

        return $repair;
    }

    private function validateStart(RepairRequest $repair): void
    {
        $this->actingAs($this->repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/materials/validate-start")
            ->assertOk();
    }

    public function test_booking_aggregates_service_materials_without_consuming_stock(): void
    {
        $a = $this->service(1);
        $b = $this->service(2, true, 30);
        $repair = $this->book([$a->id, $b->id]);
        $this->assertCount(1, $repair->materialPlanItems);
        $line = $repair->materialPlanItems->sole();
        $this->assertSame(3.0, $line->planned_quantity);
        $this->assertSame(0.0, $line->actual_quantity);
        $this->assertTrue($line->is_critical);
        $this->assertSame(30.0, $line->tolerance_percent);
        $this->assertSame(100, (int) $this->glue->fresh()->available_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('booking', $repair->fresh()->material_plan_snapshot['source']);
    }

    public function test_template_changes_and_deletion_do_not_rewrite_booked_plans(): void
    {
        $service = $this->service(2, true);
        $repair = $this->book([$service->id]);
        $service->materialTemplateItems()->update(['default_quantity' => 9, 'is_critical' => false]);
        $this->validateStart($repair);
        $this->assertSame(2.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $service->materialTemplateItems()->delete();
        $service->delete();
        $this->validateStart($repair);
        $this->assertSame(2.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->assertTrue($repair->materialPlanItems()->sole()->is_critical);
    }

    public function test_empty_booking_snapshot_stays_empty_after_a_template_is_added(): void
    {
        $service = $this->service();
        $repair = $this->book([$service->id]);
        $service->materialTemplateItems()->create([
            'shop_owner_id' => $this->shop->id, 'inventory_item_id' => $this->glue->id,
            'template_type' => 'repair_service', 'default_quantity' => 4, 'is_critical' => true, 'tolerance_percent' => 20,
        ]);
        $this->validateStart($repair);
        $this->assertCount(0, $repair->materialPlanItems()->get());
        $this->assertSame([], $repair->fresh()->material_plan_snapshot['items']);
    }

    public function test_package_materials_and_unique_included_and_addon_services_are_additive(): void
    {
        $a = $this->service(1);
        $b = $this->service(2);
        $addon = $this->service(3);
        $package = RepairPackage::create([
            'shop_owner_id' => $this->shop->id, 'name' => 'Bundle', 'package_price' => 800, 'status' => 'active',
        ]);
        $package->syncIncludedServices([$a->id, $b->id]);
        $package->materialTemplateItems()->create([
            'shop_owner_id' => $this->shop->id, 'inventory_item_id' => $this->glue->id,
            'template_type' => 'repair_package', 'default_quantity' => 4, 'is_critical' => true, 'tolerance_percent' => 25,
        ]);
        // Partial legacy relationships must still include package and snapshot-only services.
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $this->shop->id, 'assigned_repairer_id' => $this->repairer->id,
            'status' => 'pending', 'repair_package_id' => $package->id,
            'included_services_snapshot' => [['id' => $a->id], ['id' => $b->id]],
            'add_on_services_snapshot' => [['id' => $addon->id]],
        ]);
        $repair->services()->sync([$a->id, $addon->id]);
        $this->validateStart($repair);
        $this->assertSame(10.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->assertSame('legacy_templates', $repair->fresh()->material_plan_snapshot['source']);
    }

    public function test_existing_legacy_planned_quantity_and_actual_usage_are_preserved(): void
    {
        $service = $this->service(9);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $this->shop->id, 'assigned_repairer_id' => $this->repairer->id, 'status' => 'in_progress',
        ]);
        $repair->services()->sync([$service->id]);
        $repair->materialPlanItems()->create([
            'inventory_item_id' => $this->glue->id, 'planned_quantity' => 2, 'actual_quantity' => 1,
            'is_critical' => true, 'tolerance_percent' => 15, 'variance_note' => 'Historical note',
        ]);
        $this->validateStart($repair);
        $line = $repair->materialPlanItems()->sole();
        $this->assertSame(2.0, $line->planned_quantity);
        $this->assertSame(1.0, $line->actual_quantity);
        $this->assertSame('Historical note', $line->variance_note);
        $this->assertSame('legacy_plan', $repair->fresh()->material_plan_snapshot['source']);
    }

    public function test_actual_walkin_acceptance_permits_unpaid_service_edit_and_replaces_plan(): void
    {
        $old = $this->service(1);
        $replacement = $this->service(3);
        $repair = $this->book([$old->id]);
        $this->actingAs($this->repairer, 'user')->postJson("/api/repairer/repairs/{$repair->id}/accept")
            ->assertOk();
        $this->assertSame('pending', $repair->fresh()->status);
        $this->assertNotNull($repair->fresh()->conversation_id);
        $this->actingAs($this->customer, 'user')->patchJson("/api/customer/repairs/{$repair->id}/services", [
            'service_ids' => [$replacement->id],
        ])->assertOk();
        $this->assertSame(3.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->assertSame('service_edit', $repair->fresh()->material_plan_snapshot['source']);
        $this->assertSame(100, (int) $this->glue->fresh()->available_quantity);
    }

    public function test_service_edit_cannot_erase_recorded_material_usage(): void
    {
        $old = $this->service(1);
        $replacement = $this->service(3);
        $repair = $this->book([$old->id]);
        $conversation = \App\Models\Conversation::create([
            'shop_owner_id' => $this->shop->id, 'customer_id' => $this->customer->id,
            'status' => 'open', 'priority' => 'medium',
        ]);
        $repair->update(['status' => 'repairer_accepted', 'conversation_id' => $conversation->id]);
        $repair->materialUsages()->create([
            'inventory_item_id' => $this->glue->id, 'quantity_used' => 1,
            'used_by' => $this->repairer->id, 'used_at' => now(),
        ]);
        $this->actingAs($this->customer, 'user')->patchJson("/api/customer/repairs/{$repair->id}/services", [
            'service_ids' => [$replacement->id],
        ])->assertStatus(409);
        $this->assertSame([$old->id], $repair->services()->pluck('repair_services.id')->all());
        $this->assertDatabaseCount('repair_material_usages', 1);
    }


    public function test_reversed_usage_history_does_not_reopen_the_service_editor(): void
    {
        $old = $this->service(1);
        $replacement = $this->service(3);
        $repair = $this->book([$old->id]);
        $conversation = \App\Models\Conversation::create([
            'shop_owner_id' => $this->shop->id, 'customer_id' => $this->customer->id,
            'status' => 'open', 'priority' => 'medium',
        ]);
        $repair->update(['status' => 'repairer_accepted', 'conversation_id' => $conversation->id]);
        $movement = $this->glue->decrementStock(1, 'repair_usage', 'Historical usage', $this->repairer->id);
        $movement->update(['reference_type' => 'repair_request', 'reference_id' => $repair->id]);
        $reversal = $this->glue->fresh()->incrementStock(1, 'return', 'Historical reversal', $this->repairer->id);
        $reversal->update(['reference_type' => 'repair_request', 'reference_id' => $repair->id]);
        $this->assertSame(100, (int) $this->glue->fresh()->available_quantity);
        $this->assertDatabaseCount('repair_material_usages', 0);
        $this->actingAs($this->customer, 'user')->patchJson("/api/customer/repairs/{$repair->id}/services", [
            'service_ids' => [$replacement->id],
        ])->assertStatus(409);
        $this->assertSame(1.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_accepted_unpaid_service_eligibility_is_shared_by_list_and_detail(): void
    {
        $service = $this->service(1);
        $repair = $this->book([$service->id]);
        $this->actingAs($this->repairer, 'user')->postJson("/api/repairer/repairs/{$repair->id}/accept")->assertOk();
        $this->actingAs($this->customer, 'user')->getJson('/api/customer/repairs')
            ->assertOk()->assertJsonPath('data.0.can_modify_services', true);
        $this->getJson("/api/customer/repairs/{$repair->id}")
            ->assertOk()->assertJsonPath('data.can_modify_services', true);
    }


    public function test_archived_package_and_service_can_initialize_a_scoped_legacy_plan(): void
    {
        $service = $this->service(2);
        $package = RepairPackage::create([
            'shop_owner_id' => $this->shop->id, 'name' => 'Archived bundle', 'package_price' => 500, 'status' => 'active',
        ]);
        $package->syncIncludedServices([$service->id]);
        $package->materialTemplateItems()->create([
            'shop_owner_id' => $this->shop->id, 'inventory_item_id' => $this->glue->id,
            'template_type' => 'repair_package', 'default_quantity' => 3,
        ]);
        $service->delete();
        $package->delete();
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $this->shop->id, 'assigned_repairer_id' => $this->repairer->id,
            'status' => 'pending', 'repair_package_id' => $package->id,
            'included_services_snapshot' => [['id' => $service->id]],
        ]);
        $this->validateStart($repair);
        $this->assertSame(5.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->assertSame('legacy_templates', $repair->fresh()->material_plan_snapshot['source']);
    }

    public function test_completed_legacy_job_without_a_plan_is_not_reconstructed_from_current_templates(): void
    {
        $service = $this->service(4);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $this->shop->id, 'assigned_repairer_id' => $this->repairer->id, 'status' => 'completed',
        ]);
        $repair->services()->sync([$service->id]);
        $this->validateStart($repair);
        $this->assertCount(0, $repair->materialPlanItems()->get());
        $this->assertNull($repair->fresh()->material_plan_snapshot);
    }

    public function test_two_stale_legacy_reads_reuse_the_first_persisted_plan(): void
    {
        $service = $this->service(2);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $this->shop->id, 'assigned_repairer_id' => $this->repairer->id, 'status' => 'pending',
        ]);
        $repair->services()->sync([$service->id]);
        $first = $repair->fresh();
        $second = $repair->fresh();
        $planner = app(\App\Services\RepairMaterialPlanningService::class);
        $planner->ensurePlan($first);
        $service->materialTemplateItems()->update(['default_quantity' => 9]);
        $planner->ensurePlan($second);
        $this->assertSame(2.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->assertSame($first->material_plan_snapshot, $second->material_plan_snapshot);
        $this->assertDatabaseCount('repair_material_plan_items', 1);
    }

    public function test_booking_package_captures_all_sources_once(): void
    {
        $a = $this->service(1);
        $b = $this->service(2);
        $addon = $this->service(3);
        $package = RepairPackage::create([
            'shop_owner_id' => $this->shop->id, 'name' => 'Booking bundle', 'package_price' => 800, 'status' => 'active',
        ]);
        $package->syncIncludedServices([$a->id, $b->id]);
        $package->materialTemplateItems()->create([
            'shop_owner_id' => $this->shop->id, 'inventory_item_id' => $this->glue->id,
            'template_type' => 'repair_package', 'default_quantity' => 4,
        ]);
        $repair = $this->book([], ['repair_package_id' => $package->id, 'add_on_service_ids' => [$addon->id]]);
        $this->assertSame(10.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->assertSame([$a->id, $b->id, $addon->id], $repair->fresh()->material_plan_snapshot['service_ids']);
        $this->assertSame('booking', $repair->fresh()->material_plan_snapshot['source']);
        $this->assertSame(100, (int) $this->glue->fresh()->available_quantity);
    }


    public function test_replaying_booking_snapshot_does_not_refresh_live_templates(): void
    {
        $service = $this->service(2);
        $repair = $this->book([$service->id]);
        $originalSnapshot = $repair->fresh()->material_plan_snapshot;
        $service->materialTemplateItems()->update(['default_quantity' => 9]);
        app(\App\Services\RepairMaterialPlanningService::class)->snapshot($repair);
        $this->assertSame(2.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->assertSame($originalSnapshot, $repair->fresh()->material_plan_snapshot);
    }


    public function test_foreign_package_and_corrupt_foreign_inventory_templates_are_excluded(): void
    {
        $other = ShopOwner::factory()->approved()->create();
        $foreignInventory = InventoryItem::factory()->create(['shop_owner_id' => $other->id]);
        $foreignPackage = RepairPackage::create([
            'shop_owner_id' => $other->id, 'name' => 'Foreign archived package', 'package_price' => 500, 'status' => 'active',
        ]);
        $foreignPackage->materialTemplateItems()->create([
            'shop_owner_id' => $other->id, 'inventory_item_id' => $foreignInventory->id,
            'template_type' => 'repair_package', 'default_quantity' => 10,
        ]);
        $foreignPackage->delete();
        $service = $this->service(1);
        $service->materialTemplateItems()->create([
            'shop_owner_id' => $this->shop->id, 'inventory_item_id' => $foreignInventory->id,
            'template_type' => 'repair_service', 'default_quantity' => 20,
        ]);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $this->shop->id, 'assigned_repairer_id' => $this->repairer->id,
            'status' => 'pending', 'repair_package_id' => $foreignPackage->id,
        ]);
        $repair->services()->sync([$service->id]);
        $this->validateStart($repair);
        $this->assertSame($this->glue->id, $repair->materialPlanItems()->sole()->inventory_item_id);
        $this->assertSame(1.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->assertNull($repair->fresh()->material_plan_snapshot['package_id']);
    }


    #[\PHPUnit\Framework\Attributes\DataProvider('serviceEditLocks')]
    public function test_service_editor_retains_origin_payment_and_progress_locks(array $overrides): void
    {
        $old = $this->service(1);
        $replacement = $this->service(2);
        $repair = $this->book([$old->id]);
        $conversation = \App\Models\Conversation::create([
            'shop_owner_id' => $this->shop->id, 'customer_id' => $this->customer->id,
            'status' => 'open', 'priority' => 'medium',
        ]);
        $repair->update(array_merge(['status' => 'repairer_accepted', 'conversation_id' => $conversation->id], $overrides));
        $this->actingAs($this->customer, 'user')->patchJson("/api/customer/repairs/{$repair->id}/services", [
            'service_ids' => [$replacement->id],
        ])->assertStatus(409);
        $this->assertSame([$old->id], $repair->services()->pluck('repair_services.id')->all());
        $this->assertSame(1.0, $repair->materialPlanItems()->sole()->planned_quantity);
        $this->getJson('/api/customer/repairs')->assertOk()->assertJsonPath('data.0.can_modify_services', false);
        $this->getJson("/api/customer/repairs/{$repair->id}")->assertOk()->assertJsonPath('data.can_modify_services', false);
    }

    public static function serviceEditLocks(): array
    {
        return [
            'confirmed' => [['customer_confirmed_at' => '2026-10-01 10:00:00']],
            'started' => [['started_at' => '2026-10-01 10:00:00']],
            'received' => [['received_at' => '2026-10-01 10:00:00']],
            'paid amount' => [['total_paid_amount' => 1]],
            'partial payment status' => [['payment_status' => 'partially_paid']],
            'POS origin' => [['origin_channel' => 'pos']],
            'warranty job' => [['is_warranty_job' => true]],
            'warranty billing' => [['billing_mode' => 'warranty_no_charge']],
        ];
    }


    public function test_permitted_edit_removes_only_the_obsolete_zero_usage_plan(): void
    {
        $old = $this->service(1);
        $replacement = $this->service();
        $otherMaterial = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shop->id, 'category' => 'repair_materials', 'available_quantity' => 50,
        ]);
        $replacement->materialTemplateItems()->create([
            'shop_owner_id' => $this->shop->id, 'inventory_item_id' => $otherMaterial->id,
            'template_type' => 'repair_service', 'default_quantity' => 2,
        ]);
        $repair = $this->book([$old->id]);
        $this->actingAs($this->repairer, 'user')->postJson("/api/repairer/repairs/{$repair->id}/accept")->assertOk();
        $this->actingAs($this->customer, 'user')->patchJson("/api/customer/repairs/{$repair->id}/services", [
            'service_ids' => [$replacement->id],
        ])->assertOk();
        $line = $repair->materialPlanItems()->sole();
        $this->assertSame($otherMaterial->id, $line->inventory_item_id);
        $this->assertSame(2.0, $line->planned_quantity);
        $this->assertSame(0.0, $line->actual_quantity);
        $this->assertSame(100, (int) $this->glue->fresh()->available_quantity);
        $this->assertSame(50, (int) $otherMaterial->fresh()->available_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

}
