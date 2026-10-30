<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShopOwnerNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function individual_and_company_owners_can_load_and_update_notification_preferences(): void
    {
        foreach ([
            ['registration_type' => 'individual', 'business_type' => 'both'],
            ['registration_type' => 'company', 'business_type' => 'both'],
        ] as $account) {
            $owner = ShopOwner::factory()->approved()->create($account);

            $this->actingAs($owner, 'shop_owner')
                ->get('/shop-owner/notifications/settings')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Notifications/ShopOwnerPreferences', false));

            $this->actingAs($owner, 'shop_owner')
                ->getJson('/api/shop-owner/notifications/preferences')
                ->assertOk()
                ->assertJsonPath('shop_owner_id', $owner->id)
                ->assertJsonPath('preferences', []);

            $this->actingAs($owner, 'shop_owner')
                ->putJson('/api/shop-owner/notifications/preferences', [
                    'preferences' => ['new_repair_request' => false],
                    'sound_enabled' => false,
                ])
                ->assertOk()
                ->assertJsonPath('preferences.preferences.new_repair_request', false)
                ->assertJsonPath('preferences.sound_enabled', false);

            $this->actingAs($owner, 'shop_owner')
                ->get('/shop-owner/settings/profile')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('ShopOwner/Settings/shopSetting', false));
        }
    }
}
