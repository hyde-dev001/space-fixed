<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Models\ShopOwner;
use App\Services\OwnerShell\CanonicalOwnerShellService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

final class OwnerErpFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_owner_can_open_the_existing_workspace_with_fixed_telemetry(): void
    {
        $owner = $this->eligibleOwner();
        $this->enableCanonicalFallback($owner);
        Log::spy();

        $response = $this->actingAs($owner, 'shop_owner')->get(route('shop-owner.shell.erp-fallback', [
            'reason' => 'user_preference',
            'source' => 'operate.retail',
        ]));

        $response->assertRedirect(route('shop-owner.erp.workspace'));
        Log::shouldHaveReceived('info')
            ->with('shop_owner_erp_fallback_used', Mockery::on(
                static fn (array $context): bool => $context['shop_id'] === $owner->getKey()
                    && $context['reason'] === 'user_preference'
                    && $context['source'] === 'operate.retail'
                    && $context['session_id'] === session()->getId(),
            ))
            ->once();
    }

    public function test_existing_presentation_cannot_use_the_fallback(): void
    {
        $owner = $this->eligibleOwner();
        config([
            'owner_shell.enabled' => false,
            'owner_shell.allowlisted_shop_ids' => [],
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.erp-fallback', [
                'reason' => 'user_preference',
                'source' => 'home',
            ]))
            ->assertNotFound();
    }

    public function test_canonical_owner_cannot_use_the_fallback_when_the_erp_workspace_is_disabled(): void
    {
        $owner = $this->eligibleOwner();
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.owner_erp_workspace_enabled' => false,
            'shop_modules.enforcement_enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.erp-fallback', [
                'reason' => 'verification',
                'source' => 'reports',
            ]))
            ->assertNotFound();
    }

    public function test_canonical_owner_outside_workspace_eligibility_cannot_use_the_fallback(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $this->enableCanonicalFallback($owner);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.erp-fallback', [
                'reason' => 'missing_destination',
                'source' => 'operate.retail',
            ]))
            ->assertNotFound();
    }

    public function test_reason_and_source_must_use_server_owned_fixed_keys(): void
    {
        $owner = $this->eligibleOwner();
        $this->enableCanonicalFallback($owner);

        $this->from('/shop-owner/home')
            ->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.erp-fallback', [
                'reason' => 'arbitrary text',
                'source' => 'https://external.example/return',
            ]))
            ->assertRedirect('/shop-owner/home')
            ->assertSessionHasErrors(['reason', 'source']);
    }

    public function test_a_shop_id_cannot_be_used_to_switch_the_authenticated_fallback_tenant(): void
    {
        $authenticatedOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $otherOwner = $this->eligibleOwner();
        $this->enableCanonicalFallback($authenticatedOwner);
        config(['owner_shell.allowlisted_shop_ids' => [$authenticatedOwner->getKey(), $otherOwner->getKey()]]);

        $this->actingAs($authenticatedOwner, 'shop_owner')
            ->get(route('shop-owner.shell.erp-fallback', [
                'reason' => 'user_preference',
                'source' => 'home',
                'shop_owner_id' => $otherOwner->getKey(),
            ]))
            ->assertNotFound();
    }

    public function test_canonical_metadata_points_to_the_fixed_fallback_endpoint(): void
    {
        $owner = $this->eligibleOwner();
        $this->enableCanonicalFallback($owner);

        $metadata = app(CanonicalOwnerShellService::class)->forOwner($owner)->toArray();
        $fallbackUrl = $metadata['compatibility']['fallback_url'];

        $this->assertIsString($fallbackUrl);
        $this->assertStringStartsWith('/shop-owner/erp/fallback?', $fallbackUrl);
        $this->assertStringContainsString('reason=user_preference', $fallbackUrl);
        $this->assertStringContainsString('source=home', $fallbackUrl);
    }

    private function eligibleOwner(): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
    }

    private function enableCanonicalFallback(ShopOwner $owner): void
    {
        config([
            'owner_shell.enabled' => true,
            'owner_shell.allowlisted_shop_ids' => [$owner->getKey()],
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
    }
}
