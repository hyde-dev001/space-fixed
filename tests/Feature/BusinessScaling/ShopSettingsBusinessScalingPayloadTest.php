<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\ShopOwnerUpgradeRequestDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShopSettingsBusinessScalingPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_payload_exposes_legal_transitions_state_and_safe_request_metadata(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);
        $request = ShopOwnerUpgradeRequest::factory()->pending()->create([
            'shop_owner_id' => $owner->id,
            'current_registration_type' => 'individual',
            'current_business_type' => 'retail',
            'requested_registration_type' => 'company',
            'requested_business_type' => 'both',
        ]);
        ShopOwnerUpgradeRequestDocument::create([
            'shop_owner_upgrade_request_id' => $request->id,
            'document_type' => 'valid_id',
            'disk' => 'local',
            'path' => 'private/evidence.pdf',
            'checksum_sha256' => hash('sha256', 'evidence'),
            'mime_type' => 'application/pdf',
            'size' => 8,
            'source_status' => 'uploaded',
        ]);

        $page = $this->settingsPage($owner)['props']['shop_settings']['business_scaling'];

        $this->assertSame('individual', $page['current']['registration_type']);
        $this->assertSame('retail', $page['current']['business_type']);
        $this->assertSame('individual_to_company', $page['available_account_transitions'][0]['key']);
        $this->assertSame('retail_to_both', $page['available_capability_transitions'][0]['key']);
        $this->assertSame('individual_retail_to_company_both', $page['available_combined_transitions'][0]['key']);
        $this->assertSame($request->id, $page['pending_request']['id']);
        $this->assertSame('uploaded', $page['pending_request']['documents'][0]['source_status']);
        $this->assertArrayNotHasKey('path', $page['pending_request']['documents'][0]);
        $this->assertArrayHasKey('module_catalog', $page);
        $this->assertTrue($page['modules']['retail_operations']['accessible']);
        $this->assertFalse($page['modules']['repair_operations']['eligible']);
    }

    public function test_settings_payload_shows_terminal_reason_and_hides_upgrade_choices_when_none_remain(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $request = ShopOwnerUpgradeRequest::factory()->rejected()->create([
            'shop_owner_id' => $owner->id,
            'current_registration_type' => 'company',
            'current_business_type' => 'both',
            'requested_registration_type' => 'company',
            'requested_business_type' => 'both',
            'decision_reason' => 'Evidence needs correction.',
        ]);

        $page = $this->settingsPage($owner)['props']['shop_settings']['business_scaling'];

        $this->assertSame([], $page['available_account_transitions']);
        $this->assertSame([], $page['available_capability_transitions']);
        $this->assertSame([], $page['available_combined_transitions']);
        $this->assertSame('rejected', $page['latest_terminal_request']['status']);
        $this->assertSame('Evidence needs correction.', $page['latest_terminal_request']['decision_reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPage(ShopOwner $owner): array
    {
        $this->actingAs($owner, 'shop_owner');
        $response = $this->get('/shop-owner/settings');
        $response->assertOk();

        preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $page = json_decode(
            html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            true,
        );
        $this->assertIsArray($page);

        return $page;
    }
}
