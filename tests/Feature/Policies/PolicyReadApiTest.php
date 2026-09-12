<?php

namespace Tests\Feature\Policies;

use App\Models\PolicyAcceptance;
use App\Models\ShopOwner;
use App\Models\ShopPolicyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_fetch_active_policy_for_shop(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();

        ShopPolicyVersion::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'version_number' => 1,
            'status' => 'published',
            'business_type_scope' => 'both',
            'registration_clause_mode' => 'individual_business_clause',
            'policy_sections_json' => [
                'refund_payment_terms' => 'Live terms',
            ],
            'content_hash' => hash('sha256', 'Live terms'),
            'published_at' => now(),
        ]);

        $this->getJson("/api/policies/shops/{$shopOwner->id}/active")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version_number', 1);
    }

    public function test_shop_without_active_policy_returns_an_empty_successful_response(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();

        $this->getJson("/api/policies/shops/{$shopOwner->id}/active")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
    }

    public function test_customer_prefill_returns_true_when_same_user_already_accepted_active_version(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        /** @var User $customer */
        $customer = User::factory()->createOne();

        $version = ShopPolicyVersion::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'version_number' => 3,
            'status' => 'published',
            'business_type_scope' => 'repair',
            'registration_clause_mode' => 'individual_business_clause',
            'policy_sections_json' => [
                'refund_payment_terms' => 'Policy text',
                'repair_service_terms' => 'Repair policy',
            ],
            'content_hash' => hash('sha256', 'repair-policy-v3'),
            'published_at' => now(),
        ]);

        PolicyAcceptance::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'shop_policy_version_id' => $version->id,
            'actor_guard' => 'user',
            'actor_user_id' => $customer->id,
            'context_type' => 'repair_request',
            'context_id' => 123,
            'accepted_at' => now(),
            'accepted_snapshot_hash' => $version->content_hash,
        ]);

        $this->actingAs($customer, 'user')
            ->getJson("/api/policies/shops/{$shopOwner->id}/prefill")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.prefill_checked', true)
            ->assertJsonPath('data.shop_policy_version_id', $version->id);
    }

    public function test_active_policy_filters_retail_and_repair_terms_by_requested_flow(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        ShopPolicyVersion::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'version_number' => 4,
            'status' => 'published',
            'business_type_scope' => 'both',
            'registration_clause_mode' => 'individual_business_clause',
            'policy_sections_json' => [
                'refund_payment_terms' => 'Shared refund terms',
                'retail_terms' => 'Retail terms',
                'repair_service_terms' => 'Repair terms',
                'custom_terms_retail_1' => 'Retail custom terms',
                'custom_terms_repair_1' => 'Repair custom terms',
                'custom_terms_1' => 'Legacy retail terms',
                '__section_title__retail_terms' => 'Retail title',
                '__section_title__repair_service_terms' => 'Repair title',
                '__section_title__custom_terms_repair_1' => 'Repair custom title',
            ],
            'content_hash' => hash('sha256', 'both-policy-v4'),
            'published_at' => now(),
        ]);

        $this->getJson("/api/policies/shops/{$shopOwner->id}/active?flow=retail")
            ->assertOk()
            ->assertJsonPath('data.policy_sections_json.refund_payment_terms', 'Shared refund terms')
            ->assertJsonPath('data.policy_sections_json.retail_terms', 'Retail terms')
            ->assertJsonPath('data.policy_sections_json.custom_terms_retail_1', 'Retail custom terms')
            ->assertJsonPath('data.policy_sections_json.custom_terms_1', 'Legacy retail terms')
            ->assertJsonPath('data.policy_sections_json.__section_title__retail_terms', 'Retail title')
            ->assertJsonMissingPath('data.policy_sections_json.repair_service_terms')
            ->assertJsonMissingPath('data.policy_sections_json.custom_terms_repair_1')
            ->assertJsonMissingPath('data.policy_sections_json.__section_title__repair_service_terms');

        $this->getJson("/api/policies/shops/{$shopOwner->id}/active?flow=repair")
            ->assertOk()
            ->assertJsonPath('data.policy_sections_json.refund_payment_terms', 'Shared refund terms')
            ->assertJsonPath('data.policy_sections_json.repair_service_terms', 'Repair terms')
            ->assertJsonPath('data.policy_sections_json.custom_terms_repair_1', 'Repair custom terms')
            ->assertJsonPath('data.policy_sections_json.__section_title__repair_service_terms', 'Repair title')
            ->assertJsonPath('data.policy_sections_json.__section_title__custom_terms_repair_1', 'Repair custom title')
            ->assertJsonMissingPath('data.policy_sections_json.retail_terms')
            ->assertJsonMissingPath('data.policy_sections_json.custom_terms_retail_1')
            ->assertJsonMissingPath('data.policy_sections_json.custom_terms_1')
            ->assertJsonMissingPath('data.policy_sections_json.__section_title__retail_terms');
    }
}
