<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerOperationAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_owner_audit_api_is_scoped_to_the_authenticated_shop_owner(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $audit = app(OwnerOperationAudit::class);
        $audit->record(
            actor: $owner,
            tenantOwnerId: (int) $owner->getKey(),
            module: 'settings',
            action: 'owner-visible-action',
            result: 'succeeded',
        );
        $audit->record(
            actor: $otherOwner,
            tenantOwnerId: (int) $otherOwner->getKey(),
            module: 'settings',
            action: 'other-shop-action',
            result: 'succeeded',
        );

        $response = $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/audit-logs')
            ->assertOk();

        $response->assertJsonPath('logs.total', 1);
        $response->assertJsonPath('logs.data.0.event', 'owner-visible-action');
        $response->assertJsonPath('logs.data.0.description', 'owner-visible-action');
        $this->assertCount(1, $response->json('logs.data'));
    }

    public function test_canonical_owner_audit_page_is_reachable(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/Manager/AuditLogs', false));
    }

    public function test_same_shop_manager_receives_owner_operation_evidence_without_raw_properties(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $manager = User::factory()->for($owner)->create(['role' => 'Manager']);
        Role::findOrCreate('Manager', 'user');
        $manager->assignRole('Manager');

        app(OwnerOperationAudit::class)->record(
            actor: $owner,
            tenantOwnerId: (int) $owner->getKey(),
            module: 'settings',
            action: 'module.updated',
            result: 'succeeded',
            context: [
                'correlation_id' => 'corr-manager-view-001',
                'before' => ['enabled' => false, 'password' => 'private'],
                'after' => ['enabled' => true, 'paymongo_secret_key' => 'private'],
            ],
        );

        $response = $this->actingAs($manager, 'user')
            ->getJson('/api/activity-logs?event=module.updated')
            ->assertOk();

        $log = $response->json('logs.data.0');

        $this->assertSame('module.updated', $log['event']);
        $this->assertSame('Shop Owner', $log['causer']['role']);
        $this->assertArrayNotHasKey('properties', $log);
        $this->assertArrayNotHasKey('private', $response->json());
        $this->assertArrayNotHasKey('paymongo_secret_key', $response->json());
        $this->assertArrayNotHasKey('password', $response->json());
    }

    public function test_routine_canonical_page_load_does_not_create_owner_operation_audit(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.enforcement_enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/home')
            ->assertOk();

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'owner_operation',
        ]);
    }
}
