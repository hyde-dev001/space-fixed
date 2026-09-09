<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\OwnerAttentionAdapterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RepairRejectAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_rejections_are_not_registered_in_the_live_owner_queue(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);

        $adapters = app(OwnerAttentionAdapterRegistry::class)->adaptersForOwner(
            $owner,
            'needs_my_decision',
            'repair_rejections',
        );

        $this->assertSame([], $adapters);
    }
}
