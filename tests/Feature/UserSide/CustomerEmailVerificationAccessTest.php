<?php

namespace Tests\Feature\UserSide;

use App\Models\ShopOwner;
use App\Models\Product;
use App\Models\RepairService;
use App\Models\User;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CustomerEmailVerificationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_customer_can_use_non_cod_customer_workflows(): void
    {
        $customer = User::factory()->unverified()->create([
            'shop_owner_id' => null,
            'status' => 'active',
            'identity_verification_status' => User::IDENTITY_APPROVED,
        ]);
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_unverified_customer',
        ]);
        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Unverified Customer Shoe',
            'slug' => 'unverified-customer-shoe-'.random_int(1000, 9999),
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($customer, 'user')
            ->get('/checkout')
            ->assertOk();

        $this->actingAs($customer, 'user')
            ->getJson(route('user.addresses.index'))
            ->assertOk();

        $this->actingAs($customer, 'user')
            ->postJson('/api/cart/add', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', [
                'items' => [[
                    'id' => 'unverified-cart-item',
                    'pid' => $product->id,
                    'qty' => 1,
                    'name' => $product->name,
                    'price' => 1000,
                ]],
                'total_amount' => 1000,
                'shipping_fee' => 0,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'shipping_address' => '1 Customer Street',
                'payment_method' => 'paymongo',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($customer, 'user')
            ->get(route('verification.notice'))
            ->assertOk();
    }

    public function test_unverified_customer_can_still_browse_the_public_catalog(): void
    {
        $customer = User::factory()->unverified()->create([
            'shop_owner_id' => null,
            'status' => 'active',
        ]);

        $this->actingAs($customer, 'user')
            ->get('/products')
            ->assertOk();
    }

    public function test_unverified_customer_can_open_the_repair_request_form(): void
    {
        $customer = User::factory()->unverified()->create([
            'shop_owner_id' => null,
            'status' => 'active',
        ]);

        $this->actingAs($customer, 'user')
            ->get(route('repair-process'))
            ->assertOk();
    }

    public function test_unverified_customer_can_submit_a_repair_request(): void
    {
        $customer = User::factory()->unverified()->create([
            'shop_owner_id' => null,
            'status' => 'active',
            'identity_verification_status' => User::IDENTITY_PENDING_REVIEW,
        ]);
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $service = RepairService::query()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Unverified customer repair',
            'category' => 'Cleaning',
            'price' => 100,
            'duration' => '1 day',
            'description' => 'Repair access test service',
            'status' => 'active',
        ]);

        $this->actingAs($customer, 'user')
            ->post('/api/repair-requests', [
                'customer_name' => $customer->name,
                'email' => $customer->email,
                'phone' => '09171234567',
                'shoe_type' => 'Sneakers',
                'shop_owner_id' => $shop->id,
                'services' => [$service->id],
                'images' => [UploadedFile::fake()->create('shoe.jpg', 100, 'image/jpeg')],
                'total' => 100,
                'service_type' => 'walkin',
                'return_delivery_method' => 'walk_in',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_verified_customer_can_access_customer_workflows(): void
    {
        $customer = User::factory()->create([
            'shop_owner_id' => null,
            'status' => 'active',
        ]);

        $this->actingAs($customer, 'user')
            ->get('/checkout')
            ->assertOk();

        $this->actingAs($customer, 'user')
            ->getJson(route('user.addresses.index'))
            ->assertOk();
    }

    public function test_employee_user_is_not_blocked_by_customer_email_gate(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $employee = User::factory()->unverified()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);

        $this->actingAs($employee, 'user')
            ->getJson(route('user.me'))
            ->assertOk();
    }

    public function test_role_classified_employee_without_tenant_link_is_not_blocked(): void
    {
        $employee = User::factory()->unverified()->create([
            'shop_owner_id' => null,
            'role' => 'STAFF',
            'status' => 'active',
        ]);

        $this->actingAs($employee, 'user')
            ->getJson(route('user.me'))
            ->assertOk();
    }

    public function test_shop_owner_verification_route_is_not_blocked_by_customer_gate(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'email_verified_at' => null,
        ]);

        $this->actingAs($shopOwner, 'shop_owner')
            ->get(route('verification.notice'))
            ->assertOk();
    }

    public function test_verification_link_verifies_only_the_signed_customer_account(): void
    {
        $target = User::factory()->unverified()->create([
            'status' => 'active',
        ]);
        $authenticatedCustomer = User::factory()->unverified()->create([
            'status' => 'active',
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['accountType' => 'user', 'id' => $target->id, 'hash' => sha1($target->email)],
        );

        $this->actingAs($authenticatedCustomer, 'user')
            ->get($verificationUrl)
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'Email verified successfully. You may now sign in.');

        $this->assertTrue($target->fresh()->hasVerifiedEmail());
        $this->assertFalse($authenticatedCustomer->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($authenticatedCustomer, 'user');
    }

    public function test_invalid_verification_hash_does_not_verify_the_authenticated_customer(): void
    {
        $customer = User::factory()->unverified()->create([
            'status' => 'active',
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['accountType' => 'user', 'id' => $customer->id, 'hash' => sha1('different@example.test')],
        );

        $this->get($verificationUrl)
            ->assertForbidden();

        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    public function test_expired_verification_link_fails_closed(): void
    {
        $customer = User::factory()->unverified()->create([
            'status' => 'active',
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            ['accountType' => 'user', 'id' => $customer->id, 'hash' => sha1($customer->email)],
        );

        $this->get($verificationUrl)
            ->assertForbidden();

        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    public function test_already_verified_customer_verification_is_idempotent(): void
    {
        $customer = User::factory()->create([
            'status' => 'active',
        ]);
        $verifiedAt = $customer->email_verified_at;
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['accountType' => 'user', 'id' => $customer->id, 'hash' => sha1($customer->email)],
        );

        $this->get($verificationUrl)
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'Your email is already verified.');

        $this->assertEquals($verifiedAt?->timestamp, $customer->fresh()->email_verified_at?->timestamp);
    }

    public function test_unverified_customer_can_logout_from_verification_only_session(): void
    {
        $customer = User::factory()->unverified()->create([
            'status' => 'active',
        ]);

        $this->actingAs($customer, 'user')
            ->post(route('user.logout'))
            ->assertRedirect();

        $this->assertGuest('user');
    }

    public function test_verification_resend_is_throttled(): void
    {
        $customer = User::factory()->unverified()->create([
            'status' => 'active',
        ]);

        $this->actingAs($customer, 'user');

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('verification.send'))
                ->assertRedirect();
        }

        $this->post(route('verification.send'))
            ->assertTooManyRequests();
    }

    public function test_verification_resend_failure_returns_a_recoverable_error(): void
    {
        $customer = User::factory()->unverified()->create([
            'status' => 'active',
        ]);

        $this->app->instance(NotificationDispatcher::class, new class implements NotificationDispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new \RuntimeException('mail transport unavailable');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new \RuntimeException('mail transport unavailable');
            }
        });

        $this->actingAs($customer, 'user')
            ->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHasErrors([
                'verification' => 'We could not send the verification email. Please check the email settings and try again later.',
            ]);
    }
}
