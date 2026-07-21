<?php

namespace Tests\Feature\UserSide;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressGeocodingProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.nominatim.url' => 'https://nominatim.test',
            'services.nominatim.user_agent' => 'SoleSpace Tests/1.0 (tests@example.com)',
        ]);
        Cache::clear();
        $this->withServerVariables([
            'REMOTE_ADDR' => sprintf('198.19.%d.%d', random_int(0, 255), random_int(1, 254)),
        ]);
    }

    public function test_guest_search_uses_configured_nominatim_request_and_returns_raw_json(): void
    {
        $payload = [[
            'lat' => '14.5995',
            'lon' => '120.9842',
            'display_name' => 'Manila',
            'address' => ['city' => 'Manila'],
        ]];
        $options = [];
        Http::fake(function (ClientRequest $request, array $requestOptions) use ($payload, &$options) {
            $options = $requestOptions;

            return Http::response($payload);
        });

        $this->getJson('/api/address/geocode?q=Ermita%20Manila')
            ->assertOk()
            ->assertExactJson($payload);

        Http::assertSent(function (ClientRequest $request) {
            return str_starts_with($request->url(), 'https://nominatim.test/search?')
                && $request['q'] === 'Ermita Manila'
                && $request['format'] === 'jsonv2'
                && $request['addressdetails'] === 1
                && $request['countrycodes'] === 'ph'
                && $request['limit'] === 1
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('User-Agent', 'SoleSpace Tests/1.0 (tests@example.com)');
        });
        $this->assertSame(5, $options['timeout']);
    }

    public function test_customer_reverse_lookup_uses_bounded_coordinates_and_returns_raw_json(): void
    {
        $payload = [
            'lat' => '14.5995',
            'lon' => '120.9842',
            'display_name' => 'Manila',
            'address' => ['city' => 'Manila'],
        ];
        Http::fake(["https://nominatim.test/*" => Http::response($payload)]);

        $this->getJson('/api/address/geocode?latitude=14.5995&longitude=120.9842')
            ->assertOk()
            ->assertExactJson($payload);

        Http::assertSent(fn (ClientRequest $request) =>
            str_starts_with($request->url(), 'https://nominatim.test/reverse?')
            && $request['lat'] === 14.5995
            && $request['lon'] === 120.9842
            && $request['format'] === 'jsonv2'
            && $request['addressdetails'] === 1
            && ! isset($request['countrycodes'])
            && ! isset($request['limit'])
        );
    }

    public function test_public_proxy_requires_valid_exclusive_input(): void
    {
        Http::fake();

        foreach ([
            [],
            ['q' => ' x '],
            ['q' => str_repeat('x', 201)],
            ['q' => 'Manila', 'latitude' => 14.5, 'longitude' => 121],
            ['latitude' => 14.5],
            ['longitude' => 121],
            ['latitude' => 4.49, 'longitude' => 121],
            ['latitude' => 21.51, 'longitude' => 121],
            ['latitude' => 14.5, 'longitude' => 115.99],
            ['latitude' => 14.5, 'longitude' => 127.01],
            ['q' => 'Manila', 'limit' => 0],
            ['q' => 'Manila', 'limit' => 6],
            ['q' => 'Manila', 'limit' => 1.5],
            ['latitude' => 14.5, 'longitude' => 121, 'limit' => 5],
        ] as $index => $query) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)]);
            $this->getJson('/api/address/geocode?'.http_build_query($query))->assertUnprocessable();
        }

        Http::assertNothingSent();
    }

    public function test_public_proxy_throttles_the_eleventh_cached_request_per_ip(): void
    {
        $payload = [['lat' => '14.5995', 'lon' => '120.9842', 'address' => []]];
        Http::fake(["https://nominatim.test/*" => Http::response($payload)]);
        $ip = sprintf('198.18.%d.%d', random_int(0, 255), random_int(1, 254));
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $this->getJson('/api/address/geocode?q=Manila')->assertOk();

        $statuses = [];
        foreach (range(2, 11) as $request) {
            $statuses[] = $this->getJson('/api/address/geocode?q=Manila')->status();
        }
        $this->assertSame([...array_fill(0, 9, 200), 429], $statuses);

        Http::assertSentCount(1);
    }

    public function test_search_limit_is_bounded_forwarded_and_cache_specific(): void
    {
        $payload = collect(range(1, 5))->map(fn (int $index) => [
            'lat' => (string) (14 + $index / 100),
            'lon' => (string) (120 + $index / 100),
            'display_name' => "Candidate {$index}",
            'address' => ['city' => "City {$index}"],
        ])->all();
        Http::fake(fn (ClientRequest $request) => Http::response(array_slice($payload, 0, (int) $request['limit'])));

        $this->getJson('/api/address/geocode?q=Manila&limit=1')->assertOk()->assertJsonCount(1);
        Cache::forget('nominatim:last-dispatch-ms');
        $this->getJson('/api/address/geocode?q=Manila&limit=5')
            ->assertOk()
            ->assertExactJson($payload);
        $this->getJson('/api/address/geocode?q=Manila&limit=1')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/address/geocode?q=Manila&limit=5')->assertOk()->assertJsonCount(5);

        Http::assertSentCount(2);
        $this->assertSame(
            [1, 5],
            Http::recorded()->map(fn (array $record) => $record[0]['limit'])->all(),
        );
    }

    public function test_response_cache_forgets_the_oldest_entries_and_refetches_them(): void
    {
        config(['services.nominatim.cache_max_entries' => 2]);
        Http::fake(fn (ClientRequest $request) => Http::response([[
            'lat' => $request['q'] === 'Alpha' ? '14.1' : '14.2',
            'lon' => '120.1',
            'display_name' => $request['q'],
            'address' => ['city' => $request['q']],
        ]]));

        $this->getJson('/api/address/geocode?q=Alpha')->assertOk();
        Cache::forget('nominatim:last-dispatch-ms');
        $this->getJson('/api/address/geocode?q=Beta')->assertOk();
        Cache::forget('nominatim:last-dispatch-ms');
        $this->getJson('/api/address/geocode?q=Alpha')->assertOk();

        Http::assertSentCount(3);
        $this->assertCount(2, Cache::get('nominatim:response-keys'));
    }

    public function test_normalized_request_is_cached_before_the_limiter(): void
    {
        $payload = [['lat' => '14.5995', 'lon' => '120.9842', 'address' => []]];
        Http::fake(["https://nominatim.test/*" => Http::response($payload)]);

        $this->getJson('/api/address/geocode?q=%20Ermita%20%20Manila%20')->assertOk()->assertExactJson($payload);
        $this->getJson('/api/address/geocode?q=ermita%20manila')->assertOk()->assertExactJson($payload);

        Http::assertSentCount(1);
    }

    public function test_search_result_primes_its_reverse_lookup_for_registration(): void
    {
        $payload = [[
            'lat' => '14.5995',
            'lon' => '120.9842',
            'display_name' => 'Manila',
            'address' => ['city' => 'Manila'],
        ]];
        Http::fake(["https://nominatim.test/*" => Http::response($payload)]);

        $this->getJson('/api/address/geocode?q=Manila')->assertOk();
        $this->getJson('/api/address/geocode?latitude=14.5995&longitude=120.9842')
            ->assertOk()
            ->assertExactJson($payload[0]);

        Http::assertSentCount(1);
    }

    public function test_shared_limiter_rejects_an_immediate_uncached_request_from_another_customer(): void
    {
        Http::fake(["https://nominatim.test/*" => Http::response([])]);

        $this->getJson('/api/address/geocode?q=Manila')
            ->assertOk();
        $this->getJson('/api/address/geocode?q=Cebu')
            ->assertStatus(429)
            ->assertExactJson([
                'message' => 'Address lookup is busy. Please try again shortly.',
                'retry_after' => 1,
            ]);

        Http::assertSentCount(1);
    }

    public function test_shared_dispatch_lock_rejects_without_contacting_upstream(): void
    {
        Http::fake();
        $lock = Cache::lock('nominatim:dispatch-lock', 10);
        $this->assertTrue($lock->get());

        try {
            $this->getJson('/api/address/geocode?q=Manila')
                ->assertStatus(429)
                ->assertJsonPath('retry_after', 1);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_upstream_http_failure_returns_sanitized_502(): void
    {
        Http::fake(["https://nominatim.test/*" => Http::response(['private' => 'upstream detail'], 503)]);

        $this->getJson('/api/address/geocode?q=Manila')
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Address lookup is unavailable. Please try again.']);
    }

    public function test_upstream_connection_failure_returns_sanitized_502(): void
    {
        Http::fake(["https://nominatim.test/*" => Http::failedConnection('private timeout detail')]);

        $this->getJson('/api/address/geocode?q=Manila')
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Address lookup is unavailable. Please try again.']);
    }

    public function test_invalid_search_shape_is_not_cached(): void
    {
        Http::fakeSequence()
            ->push(['lat' => '14.5995', 'lon' => '120.9842'])
            ->push([['lat' => '14.5995', 'lon' => '120.9842', 'address' => []]]);

        $this->getJson('/api/address/geocode?q=Manila')->assertStatus(502);
        $this->travel(1)->seconds();
        $this->getJson('/api/address/geocode?q=Manila')->assertOk();

        Http::assertSentCount(2);
    }

    public function test_invalid_reverse_shape_is_not_cached(): void
    {
        Http::fakeSequence()
            ->push([['lat' => '14.5995', 'lon' => '120.9842', 'address' => []]])
            ->push(['lat' => '14.5995', 'lon' => '120.9842', 'address' => []]);

        $url = '/api/address/geocode?latitude=14.5995&longitude=120.9842';
        $this->getJson($url)->assertStatus(502);
        $this->travel(1)->seconds();
        $this->getJson($url)->assertOk();

        Http::assertSentCount(2);
    }

    public function test_invalid_operation_specific_entries_return_sanitized_502(): void
    {
        foreach ([
            [['lat' => 'not-a-number', 'lon' => '120.9842', 'address' => []]],
            [['lat' => '14.5995', 'lon' => '120.9842']],
        ] as $payload) {
            Cache::clear();
            Http::fake(["https://nominatim.test/*" => Http::response($payload)]);
            $this->getJson('/api/address/geocode?q=Manila')->assertStatus(502);
        }

        foreach ([
            ['lat' => 'not-a-number', 'lon' => '120.9842', 'address' => []],
            ['lat' => '14.5995', 'lon' => '120.9842'],
        ] as $payload) {
            Cache::clear();
            Http::fake(["https://nominatim.test/*" => Http::response($payload)]);
            $this->getJson('/api/address/geocode?latitude=14.5995&longitude=120.9842')->assertStatus(502);
        }
    }

    public function test_executable_sources_do_not_call_nominatim_directly(): void
    {
        foreach ([app_path(), resource_path('js')] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $this->assertStringNotContainsString(
                        'nominatim.openstreetmap.org',
                        (string) file_get_contents($file->getPathname()),
                        $file->getPathname(),
                    );
                }
            }
        }

        $shopSettings = (string) file_get_contents(resource_path('js/Pages/ShopOwner/Settings/shopSetting.tsx'));
        $this->assertStringContainsString('/api/address/geocode?q=${encodeURIComponent(addressSearch)}&limit=5', $shopSettings);
        $this->assertStringContainsString('addressResults.map(', $shopSettings);
    }
}
