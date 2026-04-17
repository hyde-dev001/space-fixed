<?php

namespace Tests\Feature\Policies;

use App\Models\Order;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\ShopPolicyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutPolicyAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function checkoutPayload(Product $product, User $customer): array
    {
        return [
            'items' => [
                [
                    'id' => 'cart-item-1',
                    'pid' => $product->id,
                    'qty' => 1,
                    'name' => $product->name,
                    'price' => 1500,
                    'size' => null,
                    'color' => null,
                    'image' => null,
                ],
            ],
            'total_amount' => 1500,
            'shipping_fee' => 50,
            'customer_name' => 'Policy Checkout Customer',
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'shipping_address' => '123 Policy St, Test City',
            'payment_method' => 'paymongo',
        ];
    }

    public function test_checkout_create_order_requires_policy_acceptance_for_active_shop_policy(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_policy_checkout_required',
        ]);

        /** @var User $customer */
        $customer = User::factory()->createOne();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Policy Shoe',
            'slug' => 'policy-shoe-' . random_int(1000, 9999),
            'description' => 'Policy enforcement product',
            'price' => 1500,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);

        ShopPolicyVersion::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'version_number' => 1,
            'status' => 'published',
            'business_type_scope' => 'retail',
            'registration_clause_mode' => 'individual_business_clause',
            'policy_sections_json' => [
                'refund_payment_terms' => 'Retail terms',
                'retail_terms' => 'Retail section',
            ],
            'content_hash' => hash('sha256', 'retail-v1'),
            'published_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->checkoutPayload($product, $customer));

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_checkout_create_order_records_policy_acceptance_when_payload_matches_active_version(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_policy_checkout_record',
        ]);

        /** @var User $customer */
        $customer = User::factory()->createOne();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Policy Shoe',
            'slug' => 'policy-shoe-record-' . random_int(1000, 9999),
            'description' => 'Policy enforcement product',
            'price' => 1500,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);

        $version = ShopPolicyVersion::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'version_number' => 2,
            'status' => 'published',
            'business_type_scope' => 'retail',
            'registration_clause_mode' => 'individual_business_clause',
            'policy_sections_json' => [
                'refund_payment_terms' => 'Retail terms',
                'retail_terms' => 'Retail section',
            ],
            'content_hash' => hash('sha256', 'retail-v2'),
            'published_at' => now(),
        ]);

        $payload = $this->checkoutPayload($product, $customer);
        $payload['accepted_shop_policy_version_id'] = $version->id;
        $payload['policy_accepted'] = true;

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $payload);

        $response->assertOk()->assertJsonPath('success', true);

        $orderId = (int) $response->json('order.id');
        $this->assertGreaterThan(0, $orderId);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame((int) $version->id, (int) $order->accepted_shop_policy_version_id);

        $this->assertDatabaseHas('policy_acceptances', [
            'shop_owner_id' => $shopOwner->id,
            'shop_policy_version_id' => $version->id,
            'actor_user_id' => $customer->id,
            'context_type' => 'order',
            'context_id' => $order->id,
        ]);
    }

    public function test_checkout_create_order_without_active_policy_does_not_require_acceptance_payload(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_policy_checkout_no_active',
        ]);

        /** @var User $customer */
        $customer = User::factory()->createOne();

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'No Policy Shoe',
            'slug' => 'no-policy-shoe-' . random_int(1000, 9999),
            'description' => 'No active policy checkout product',
            'price' => 1500,
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->checkoutPayload($product, $customer));

        $response->assertOk()->assertJsonPath('success', true);

        $orderId = (int) $response->json('order.id');
        $this->assertGreaterThan(0, $orderId);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'shop_owner_id' => $shopOwner->id,
        ]);
    }
}