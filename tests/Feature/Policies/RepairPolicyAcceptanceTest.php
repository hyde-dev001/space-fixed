<?php

namespace Tests\Feature\Policies;

use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\ShopPolicyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RepairPolicyAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_request_requires_policy_acceptance_payload(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var User $customer */
        $customer = User::factory()->createOne();

        $service = RepairService::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Sole reglue',
            'category' => 'Restoration',
            'price' => 100,
            'duration' => '2 days',
            'description' => 'Repair test service',
            'status' => 'active',
        ]);

        ShopPolicyVersion::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'version_number' => 1,
            'status' => 'published',
            'business_type_scope' => 'repair',
            'registration_clause_mode' => 'individual_business_clause',
            'policy_sections_json' => [
                'refund_payment_terms' => 'x',
                'repair_service_terms' => 'y',
            ],
            'content_hash' => hash('sha256', 'repair-v1'),
            'published_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->post('/api/repair-requests', [
            'customer_name' => 'Policy Test',
            'email' => $customer->email,
            'phone' => '09171234567',
            'shoe_type' => 'Sneakers',
            'shop_owner_id' => $shopOwner->id,
            'services' => [$service->id],
            'images' => [UploadedFile::fake()->image('repair.jpg')],
            'total' => 100,
            'service_type' => 'walkin',
            'return_delivery_method' => 'walk_in',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_repair_request_records_acceptance_when_policy_payload_matches_active_version(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var User $customer */
        $customer = User::factory()->createOne();

        $service = RepairService::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Deep clean',
            'category' => 'Cleaning',
            'price' => 120,
            'duration' => '1 day',
            'description' => 'Repair test service',
            'status' => 'active',
        ]);

        $version = ShopPolicyVersion::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'version_number' => 2,
            'status' => 'published',
            'business_type_scope' => 'repair',
            'registration_clause_mode' => 'individual_business_clause',
            'policy_sections_json' => [
                'refund_payment_terms' => 'x',
                'repair_service_terms' => 'y',
            ],
            'content_hash' => hash('sha256', 'repair-v2'),
            'published_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->post('/api/repair-requests', [
            'customer_name' => 'Policy Test',
            'email' => $customer->email,
            'phone' => '09171234567',
            'shoe_type' => 'Sneakers',
            'shop_owner_id' => $shopOwner->id,
            'services' => [$service->id],
            'images' => [UploadedFile::fake()->image('repair.jpg')],
            'total' => 120,
            'service_type' => 'walkin',
            'return_delivery_method' => 'walk_in',
            'accepted_shop_policy_version_id' => $version->id,
            'policy_accepted' => true,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('repair_requests', [
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'accepted_shop_policy_version_id' => $version->id,
        ]);

        $this->assertDatabaseHas('policy_acceptances', [
            'shop_owner_id' => $shopOwner->id,
            'shop_policy_version_id' => $version->id,
            'actor_user_id' => $customer->id,
            'context_type' => 'repair_request',
        ]);
    }
}