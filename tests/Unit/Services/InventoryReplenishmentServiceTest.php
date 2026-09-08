<?php

namespace Tests\Unit\Services;

use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\ShopOwner;
use App\Services\InventoryReplenishmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReplenishmentServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_enumerates_each_size_as_an_independent_effective_target(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'reorder_level' => 10,
            'reorder_quantity' => 40,
            'auto_stock_request_enabled' => true,
        ]);
        $black = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 23,
        ]);
        $white = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'White',
            'quantity' => 20,
        ]);
        $blackEight = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ]);
        $blackNine = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '9',
            'size_system' => 'US',
            'quantity' => 20,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ]);
        $whiteEight = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $white->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 20,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ]);

        $targets = app(InventoryReplenishmentService::class)->effectiveTargets($item->fresh());

        $this->assertCount(3, $targets);
        $this->assertSame(
            [$blackEight->id, $blackNine->id, $whiteEight->id],
            $targets->pluck('inventory_size_id')->sort()->values()->all()
        );
        $this->assertSame('black', $targets->firstWhere('inventory_size_id', $blackEight->id)['requested_color']);
        $this->assertSame('US 8', $targets->firstWhere('inventory_size_id', $blackEight->id)['requested_size']);
        $this->assertSame(3, $targets->firstWhere('inventory_size_id', $blackEight->id)['quantity']);
    }

    /** @test */
    public function it_uses_color_targets_only_when_that_color_has_no_sizes(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $withSizes = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 10,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $withSizes->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 10,
        ]);
        $withoutSizes = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'White',
            'quantity' => 2,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 12,
        ]);

        $targets = app(InventoryReplenishmentService::class)->effectiveTargets($item->fresh());

        $this->assertCount(2, $targets);
        $this->assertNull($targets->firstWhere('inventory_color_variant_id', $withoutSizes->id)['inventory_size_id']);
        $this->assertSame('color', $targets->firstWhere('inventory_color_variant_id', $withoutSizes->id)['type']);
        $this->assertFalse($targets->contains(fn (array $target): bool => $target['type'] === 'item'));
    }

    /** @test */
    public function it_uses_the_parent_as_the_only_target_without_children(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 7,
            'reorder_quantity' => 14,
        ]);

        $targets = app(InventoryReplenishmentService::class)->effectiveTargets($item->fresh());

        $this->assertCount(1, $targets);
        $this->assertSame('item', $targets->first()['type']);
        $this->assertSame($item->id, $targets->first()['inventory_item_id']);
        $this->assertSame(7, $targets->first()['reorder_level']);
    }
}
