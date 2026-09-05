<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ProcurementSettings;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopOwnerApprovalSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_payload_exposes_all_seven_binary_controls_without_limits(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        ProcurementSettings::create([
            'shop_owner_id' => $owner->id,
            'settings_json' => [
                'approval_pages' => $this->approvalPages(true, 5000),
                'unrelated_setting' => ['preserve' => true],
            ],
        ]);

        $approvalPages = $this->settingsPage($owner)['props']['shop_settings']['approval_pages'];

        $this->assertSame([
            'refund_approval',
            'price_approval',
            'payslip_approval',
            'salary_adjustment_approval',
            'purchase_request_approval',
            'expense_approval',
            'repair_reject_approval',
        ], array_keys($approvalPages));

        foreach ($approvalPages as $setting) {
            $this->assertArrayHasKey('enabled', $setting);
            $this->assertArrayNotHasKey('limit', $setting);
        }
    }

    public function test_owner_updates_only_its_settings_and_preserves_unrelated_json_and_legacy_limits(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $legacyPages = $this->approvalPages(true, 2500);

        ProcurementSettings::create([
            'shop_owner_id' => $owner->id,
            'settings_json' => [
                'approval_pages' => $legacyPages,
                'unrelated_setting' => ['preserve' => 'owner'],
            ],
        ]);
        ProcurementSettings::create([
            'shop_owner_id' => $otherOwner->id,
            'settings_json' => [
                'approval_pages' => $this->approvalPages(true, 9000),
                'unrelated_setting' => ['preserve' => 'other'],
            ],
        ]);

        $response = $this->actingAs($owner, 'shop_owner')->putJson('/shop-owner/settings', [
            'approval_pages' => $this->approvalPages(false, null),
        ]);

        $response->assertRedirect();

        $saved = ProcurementSettings::where('shop_owner_id', $owner->id)->firstOrFail();
        $otherSaved = ProcurementSettings::where('shop_owner_id', $otherOwner->id)->firstOrFail();
        $this->assertSame('owner', $saved->settings_json['unrelated_setting']['preserve']);
        $this->assertSame(2500, $saved->settings_json['approval_pages']['refund_approval']['limit']);
        $this->assertSame('other', $otherSaved->settings_json['unrelated_setting']['preserve']);
        $this->assertSame(9000, $otherSaved->settings_json['approval_pages']['refund_approval']['limit']);
    }

    public function test_invalid_approval_boolean_is_rejected(): void
    {
        $owner = ShopOwner::factory()->approved()->create();

        $response = $this->actingAs($owner, 'shop_owner')->putJson('/shop-owner/settings', [
            'approval_pages' => [
                'refund_approval' => ['enabled' => 'not-a-boolean'],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @return array<string, array{enabled: bool, limit: float|null}> */
    private function approvalPages(bool $enabled, ?float $limit): array
    {
        $pages = [];
        foreach ([
            'refund_approval',
            'price_approval',
            'payslip_approval',
            'salary_adjustment_approval',
            'purchase_request_approval',
            'expense_approval',
            'repair_reject_approval',
        ] as $key) {
            $pages[$key] = ['enabled' => $enabled, 'limit' => $limit];
        }

        return $pages;
    }

    /** @return array<string, mixed> */
    private function settingsPage(ShopOwner $owner): array
    {
        $response = $this->actingAs($owner, 'shop_owner')->get('/shop-owner/settings');
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
