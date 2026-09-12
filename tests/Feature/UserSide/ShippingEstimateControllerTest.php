<?php

namespace Tests\Feature\UserSide;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\AddressCoordinateService;
use App\Services\Logistics\DeliveryScheduleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

class ShippingEstimateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cold_cache_legacy_shop_and_customer_addresses_are_geocoded_with_global_spacing(): void
    {
        config([
            'cache.default' => 'array',
            'services.nominatim.url' => 'https://nominatim.test',
        ]);
        Cache::clear();
        $shop = ShopOwner::factory()->create([
            'shop_address' => '10 Legacy Shop Street, Manila',
            'business_address' => '10 Legacy Shop Street, Manila',
            'shop_latitude' => null,
            'shop_longitude' => null,
        ]);
        $product = $this->productFor($shop);
        $dispatches = [];
        Http::fake(function (ClientRequest $request) use (&$dispatches) {
            if (str_starts_with($request->url(), 'https://nominatim.test/search?')) {
                $dispatches[] = (int) Cache::get('nominatim:last-dispatch-ms');

                return Http::response(str_contains((string) $request['q'], 'Legacy Shop')
                    ? [['lat' => '14.5995', 'lon' => '120.9842']]
                    : [['lat' => '14.6091', 'lon' => '121.0223']]);
            }

            return Http::response(['routes' => [['distance' => 5000]]]);
        });

        $this->postJson('/api/shipping/estimate', [
            'item_pids' => [$product->id],
            'shipping_address_line' => '20 Customer Street',
            'shipping_barangay' => 'Ermita',
            'shipping_city' => 'Manila',
            'shipping_region' => 'NCR',
            'shipping_postal_code' => '1000',
        ])->assertOk()
            ->assertJsonPath('has_estimate', true)
            ->assertJsonPath('source', 'osm-osrm');

        $this->assertCount(2, $dispatches);
        $this->assertGreaterThanOrEqual(1000, $dispatches[1] - $dispatches[0]);
    }

    public function test_estimate_reuses_shared_geocoder_and_preserves_response(): void
    {
        $shop = ShopOwner::factory()->create([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'coverage_radius_km' => 10]);
        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Test Shoe',
            'price' => 1000,
        ]);
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Customer',
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'address_line' => '1 Test Street',
            'latitude' => 14.60,
            'longitude' => 120.98,
        ]);
        $this->mock(AddressCoordinateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('geocode')->once()->andReturn([
                'latitude' => 1,
                'longitude' => 1,
            ]);
        });
        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'routes' => [['distance' => 5000]],
            ]),
        ]);

        $this->actingAs($customer, 'user')->postJson('/api/shipping/estimate', [
            'shop_owner_id' => [['malformed']],
            'item_pids' => [$product->id],
            'address_id' => $address->id,
            'shipping_address_line' => '1 Test Street',
            'shipping_barangay' => 'Ermita',
            'shipping_city' => 'Manila',
            'shipping_region' => 'NCR',
            'shipping_postal_code' => '1000',
        ])->assertOk()->assertJson([
            'success' => true,
            'has_estimate' => true,
            'distance_km' => 5,
            'source' => 'osm-osrm',
            'shipping_summary' => 'To be calculated after order',
            'shop_owned' => [
                'available' => true,
                'reason' => null,
                'coverage_radius_km' => 10.0,
            ],
        ]);
    }

    public function test_estimate_accepts_repeated_product_ids_for_variants_from_one_shop(): void
    {
        $shop = ShopOwner::factory()->create([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        $product = $this->productFor($shop);
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'routes' => [['distance' => 5000]],
            ]),
        ]);
        $payload = $this->payload([$product->id, $product->id], $address->id);
        $payload['shipping_latitude'] = 14.60;
        $payload['shipping_longitude'] = 120.98;

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $payload)
            ->assertOk()
            ->assertJsonPath('has_estimate', true);
    }

    public function test_estimate_prefers_bounded_draft_coordinates_over_the_saved_address_pin(): void
    {
        $shop = ShopOwner::factory()->create([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'coverage_radius_km' => 10]);
        $product = $this->productFor($shop);
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        $address->update(['latitude' => 10.3157, 'longitude' => 123.8854]);
        $this->mock(AddressCoordinateService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('geocode');
        });
        Http::fake(['router.project-osrm.org/*' => Http::response(['routes' => [['distance' => 5000]]])]);

        $payload = $this->payload([$product->id], $address->id);
        $payload['shipping_latitude'] = 14.60;
        $payload['shipping_longitude'] = 120.98;

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $payload)
            ->assertOk()
            ->assertJsonPath('has_estimate', true)
            ->assertJsonPath('shop_owned.available', true);
    }

    public function test_estimate_requires_a_ph_bounded_draft_coordinate_pair(): void
    {
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        $product = $this->productFor(ShopOwner::factory()->create());

        foreach ([
            ['shipping_latitude' => 14.60],
            ['shipping_latitude' => 4.49, 'shipping_longitude' => 120.98],
            ['shipping_latitude' => 14.60, 'shipping_longitude' => 127.01],
        ] as $draft) {
            $this->actingAs($customer, 'user')
                ->postJson('/api/shipping/estimate', [...$this->payload([$product->id], $address->id), ...$draft])
                ->assertUnprocessable();
        }
    }

    public function test_estimate_rejects_an_address_owned_by_another_customer(): void
    {
        $shop = ShopOwner::factory()->create();
        $product = $this->productFor($shop);
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $otherAddress = $this->addressFor(User::factory()->create(['shop_owner_id' => null]));
        Http::fake();

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload([$product->id], $otherAddress->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_id');

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload([$product->id], 0))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_id');
    }

    public function test_estimate_rejects_products_from_multiple_shops(): void
    {
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        $first = $this->productFor(ShopOwner::factory()->create(), 'First Shoe');
        $second = $this->productFor(ShopOwner::factory()->create(), 'Second Shoe');
        Http::fake();

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload([$first->id, $second->id], $address->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_pids');
    }

    public function test_estimate_rejects_unknown_products_mixed_with_a_valid_product(): void
    {
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        $product = $this->productFor(ShopOwner::factory()->create());
        Http::fake();

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload([$product->id, $product->id + 1000], $address->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_pids');
    }

    public function test_estimate_rejects_products_without_a_shop_mixed_with_a_valid_product(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('shop_owner_id')->nullable()->change();
        });
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        $product = $this->productFor(ShopOwner::factory()->create());
        $orphan = Product::create(['shop_owner_id' => null, 'name' => 'Orphan Shoe', 'price' => 1000]);
        Http::fake();

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload([$product->id, $orphan->id], $address->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_pids');
    }

    public function test_estimate_rejects_soft_deleted_products_mixed_with_a_live_product(): void
    {
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        $shop = ShopOwner::factory()->create();
        $live = $this->productFor($shop, 'Live Shoe');
        $deleted = $this->productFor($shop, 'Deleted Shoe');
        $deleted->delete();
        Http::fake();

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload([$live->id, $deleted->id], $address->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_pids');
    }

    public function test_estimate_rejects_more_than_one_hundred_products(): void
    {
        $shop = ShopOwner::factory()->create();
        DB::table('products')->insert(collect(range(1, 101))->map(fn (int $number) => [
            'shop_owner_id' => $shop->id,
            'name' => "Test Shoe {$number}",
            'slug' => "test-shoe-{$number}",
            'price' => 1000,
        ])->all());
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload(Product::query()->pluck('id')->all(), $address->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_pids');
    }

    public function test_estimate_rejects_nested_product_identifiers(): void
    {
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        $product = $this->productFor(ShopOwner::factory()->create());

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload([[$product->id]], $address->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_pids');
    }

    public function test_fallback_includes_conservative_shop_owned_coverage_when_products_are_missing_or_empty(): void
    {
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        $coverage = [
            'available' => false,
            'reason' => 'logistics_unavailable',
            'distance_km' => null,
            'coverage_radius_km' => null,
        ];

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload([], $address->id))
            ->assertOk()
            ->assertJsonPath('shop_owned', $coverage);

        $payload = $this->payload([], $address->id);
        unset($payload['item_pids']);

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $payload)
            ->assertOk()
            ->assertJsonPath('shop_owned', $coverage);
    }

    public function test_estimate_fails_closed_when_shop_owned_coverage_throws(): void
    {
        $shop = ShopOwner::factory()->create([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        $product = $this->productFor($shop);
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $address = $this->addressFor($customer);
        $this->mock(DeliveryScheduleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('coverage')->once()->andThrow(new \RuntimeException('unavailable'));
        });
        $this->mock(AddressCoordinateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('geocode')->once()->andReturn(['latitude' => 14.60, 'longitude' => 120.98]);
        });
        Http::fake(['router.project-osrm.org/*' => Http::response(['routes' => [['distance' => 5000]]])]);

        $this->actingAs($customer, 'user')
            ->postJson('/api/shipping/estimate', $this->payload([$product->id], $address->id))
            ->assertOk()
            ->assertJsonPath('shop_owned', [
                'available' => false,
                'reason' => 'logistics_unavailable',
                'distance_km' => null,
                'coverage_radius_km' => null,
            ]);
    }

    private function productFor(ShopOwner $shop, string $name = 'Test Shoe'): Product
    {
        return Product::create(['shop_owner_id' => $shop->id, 'name' => $name, 'price' => 1000]);
    }

    private function addressFor(User $user): UserAddress
    {
        return UserAddress::create([
            'user_id' => $user->id,
            'name' => 'Customer',
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'address_line' => '1 Test Street',
            'latitude' => 14.60,
            'longitude' => 120.98,
        ]);
    }

    private function payload(array $productIds, int $addressId): array
    {
        return [
            'item_pids' => $productIds,
            'address_id' => $addressId,
            'shipping_address_line' => '1 Test Street',
            'shipping_barangay' => 'Ermita',
            'shipping_city' => 'Manila',
            'shipping_region' => 'NCR',
            'shipping_postal_code' => '1000',
        ];
    }
}
