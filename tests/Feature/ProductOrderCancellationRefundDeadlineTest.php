<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductOrderCancellationRefundDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private function createShopOwner(array $overrides = []): ShopOwner
    {
        return ShopOwner::factory()->approved()->create(array_merge([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ], $overrides));
    }

    private function createOrder(ShopOwner $shopOwner, User $customer, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-DL-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '09171234567',
            'customer_address' => 'Test Address',
            'total_amount' => 1500,
            'shipping_fee' => 150,
            'status' => 'pending',
            'payment_method' => 'paymongo',
            'payment_status' => 'pending',
            'cancellation_refund_window_started_at' => now()->subHours(3),
            'cancellation_refund_window_minutes' => 60,
            'payment_link_created_at' => now()->subHours(3),
            'payment_expires_at' => now()->addHour(),
        ], $overrides));
    }

    #[Test]
    public function cancellation_is_blocked_when_deadline_has_passed(): void
    {
        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();
        /** @var \App\Models\User $customer */

        $order = $this->createOrder($shopOwner, $customer, [
            'status' => 'pending',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/orders/cancel', [
                'order_id' => $order->id,
                'reason' => 'No longer needed',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cancellation deadline has passed for this order.');

        $this->assertNotNull($response->json('deadline_at'));

        $order->refresh();
        $this->assertSame(OrderStatus::PENDING, $order->status);
    }

    #[Test]
    public function refund_request_is_blocked_when_deadline_has_passed(): void
    {
        $shopOwner = $this->createShopOwner([
            'paymongo_secret_key' => 'sk_test_refund_deadline',
        ]);
        $customer = User::factory()->create();
        /** @var \App\Models\User $customer */

        $order = $this->createOrder($shopOwner, $customer, [
            'status' => 'delivered',
            'payment_method' => 'paymongo',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_deadline_123',
        ]);

        $media = [
            UploadedFile::fake()->create('evidence-1.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-2.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-3.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-4.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-5.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-6.mp4', 512, 'video/mp4'),
        ];

        $response = $this->actingAs($customer, 'user')
            ->postJson('/orders/request-refund', [
                'order_id' => $order->id,
                'reason' => 'Product defective or damaged',
                'note' => 'Issue discovered after delivery.',
                'media' => $media,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Refund deadline has passed for this order.');

        $this->assertNotNull($response->json('deadline_at'));
    }

    #[Test]
    public function cancellation_is_blocked_once_order_is_processing(): void
    {
        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();
        /** @var \App\Models\User $customer */

        $order = $this->createOrder($shopOwner, $customer, [
            'status' => 'processing',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'cancellation_refund_window_started_at' => now()->subMinutes(10),
            'cancellation_refund_window_minutes' => 1440,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/orders/cancel', [
                'order_id' => $order->id,
                'reason' => 'No longer needed',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You cannot cancel order once it is processed.');
    }

    #[Test]
    public function cancellation_requires_other_reason_note_when_reason_is_other(): void
    {
        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create();
        /** @var \App\Models\User $customer */

        $order = $this->createOrder($shopOwner, $customer, [
            'status' => 'pending',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/orders/cancel', [
                'order_id' => $order->id,
                'reason' => 'Other',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['other_reason_note']);
    }

    #[Test]
    public function refund_request_requires_other_reason_note_when_reason_is_other(): void
    {
        $shopOwner = $this->createShopOwner([
            'paymongo_secret_key' => 'sk_test_refund_deadline',
        ]);
        $customer = User::factory()->create();
        /** @var \App\Models\User $customer */

        $order = $this->createOrder($shopOwner, $customer, [
            'status' => 'delivered',
            'payment_method' => 'paymongo',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_deadline_123',
            'cancellation_refund_window_started_at' => now()->subMinutes(10),
            'cancellation_refund_window_minutes' => 1440,
        ]);

        $media = [
            UploadedFile::fake()->create('evidence-1.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-2.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-3.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-4.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-5.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('evidence-6.mp4', 512, 'video/mp4'),
        ];

        $response = $this->actingAs($customer, 'user')
            ->postJson('/orders/request-refund', [
                'order_id' => $order->id,
                'reason' => 'other',
                'note' => 'Issue discovered after delivery.',
                'media' => $media,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['other_reason_note']);
    }
}
