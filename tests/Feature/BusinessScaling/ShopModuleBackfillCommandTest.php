<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShopModuleBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_initializes_only_currently_eligible_missing_rows_and_is_idempotent(): void
    {
        $retailOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        $repairOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        $individualOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $disabled = ShopOwnerModule::factory()->create([
            'shop_owner_id' => $retailOwner->id,
            'module_key' => 'inventory',
            'enabled' => false,
        ]);

        $this->artisan('shop-modules:backfill')
            ->assertExitCode(0);

        $this->assertDatabaseHas('shop_owner_modules', [
            'shop_owner_id' => $retailOwner->id,
            'module_key' => 'inventory',
            'enabled' => 0,
        ]);
        $this->assertDatabaseHas('shop_owner_modules', [
            'shop_owner_id' => $repairOwner->id,
            'module_key' => 'repair_operations',
            'enabled' => 1,
        ]);
        $this->assertDatabaseMissing('shop_owner_modules', [
            'shop_owner_id' => $individualOwner->id,
            'module_key' => 'hr_employees',
        ]);

        $count = ShopOwnerModule::query()->count();
        $this->artisan('shop-modules:backfill')
            ->assertExitCode(0);

        $this->assertSame($count, ShopOwnerModule::query()->count());
        $this->assertFalse((bool) $disabled->fresh()->enabled);
    }

    public function test_verify_is_read_only_and_reports_unknown_or_ineligible_state(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->artisan('shop-modules:backfill', ['--verify' => true])
            ->assertExitCode(1);
        $this->assertDatabaseCount('shop_owner_modules', 0);

        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'legacy_unknown',
            'enabled' => true,
        ]);

        $this->artisan('shop-modules:backfill', ['--verify' => true])
            ->assertExitCode(1);
        $this->assertDatabaseCount('shop_owner_modules', 1);

        $individual = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $individual->id,
            'module_key' => 'repair_operations',
            'enabled' => true,
        ]);

        $this->artisan('shop-modules:backfill', ['--verify' => true])
            ->assertExitCode(1);
        $this->assertDatabaseCount('shop_owner_modules', 2);
    }
}
