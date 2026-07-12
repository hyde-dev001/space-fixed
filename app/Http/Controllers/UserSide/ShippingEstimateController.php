<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Services\ShippingEstimateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShippingEstimateController extends Controller
{
    private const CITY_COORDINATE_FALLBACKS = [
        'bacoor' => ['lat' => 14.4598, 'lng' => 120.9290],
        'imus' => ['lat' => 14.4297, 'lng' => 120.9367],
        'dasmariñas' => ['lat' => 14.3294, 'lng' => 120.9367],
        'dasmarinas' => ['lat' => 14.3294, 'lng' => 120.9367],
        'general trias' => ['lat' => 14.3830, 'lng' => 120.8845],
        'trece martires' => ['lat' => 14.2854, 'lng' => 120.8671],
        'tagaytay' => ['lat' => 14.1153, 'lng' => 120.9629],
        'city of cavite' => ['lat' => 14.4830, 'lng' => 120.8980],
        'cavite city' => ['lat' => 14.4830, 'lng' => 120.8980],
    ];

    public function __construct(private readonly ShippingEstimateService $shippingEstimateService)
    {
    }

    public function estimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shop_owner_id' => ['nullable', 'integer', 'exists:shop_owners,id'],
            'item_pids' => ['nullable', 'array'],
            'item_pids.*' => ['integer'],
            'shipping_address_line' => ['nullable', 'string', 'max:255'],
            'shipping_barangay' => ['nullable', 'string', 'max:100'],
            'shipping_city' => ['required', 'string', 'max:100'],
            'shipping_region' => ['required', 'string', 'max:100'],
            'shipping_postal_code' => ['nullable', 'string', 'max:10'],
        ]);

        $shopOwner = $this->resolveShopOwner($validated);
        if (!$shopOwner) {
            return $this->fallbackResponse('Shop information is unavailable.');
        }

        $shopCoordinates = $this->resolveShopCoordinates($shopOwner);
        if (!$shopCoordinates) {
            return $this->fallbackResponse('Shop location is unavailable.');
        }

        $address = $this->buildAddressString($validated);
        $customerCoordinates = $this->geocodeCustomerAddress($validated, $address);
        if (!$customerCoordinates) {
            return $this->fallbackResponse('Unable to resolve customer location.');
        }

        $distanceKm = $this->getRouteDistanceKm(
            $shopCoordinates['lat'],
            $shopCoordinates['lng'],
            $customerCoordinates['lat'],
            $customerCoordinates['lng']
        );

        if ($distanceKm === null) {
            // Fallback to straight-line distance when routing API is unavailable.
            $distanceKm = $this->haversineDistanceKm(
                $shopCoordinates['lat'],
                $shopCoordinates['lng'],
                $customerCoordinates['lat'],
                $customerCoordinates['lng']
            );

            if ($distanceKm <= 0) {
                return $this->fallbackResponse('Unable to calculate route distance.');
            }
        }

        $estimate = $this->shippingEstimateService->calculate($distanceKm, 1.0, 49);

        return response()->json([
            'success' => true,
            'has_estimate' => true,
            'distance_km' => $estimate['distance_km'],
            'base_fee' => $estimate['base_fee'],
            'min_fee' => $estimate['min_fee'],
            'max_fee' => $estimate['max_fee'],
            'allowance' => $estimate['allowance'],
            'source' => 'osm-osrm',
            'shipping_summary' => 'To be calculated after order',
            'display_estimate' => "PHP {$estimate['min_fee']} - PHP {$estimate['max_fee']} (estimated)",
            'distance_label' => number_format((float) $estimate['distance_km'], 1) . ' km from shop',
            'customer_notice' => 'Estimated only. Final shipping fee will be confirmed after order once booking with Lalamove or J&T is completed (third-party carrier).',
            'pay_after_order_notice' => 'Shipping is not included in your checkout total and will be paid upon delivery of your order',
        ]);
    }

    private function resolveShopOwner(array $validated): ?ShopOwner
    {
        if (!empty($validated['shop_owner_id'])) {
            return ShopOwner::query()->find((int) $validated['shop_owner_id']);
        }

        $pids = collect($validated['item_pids'] ?? [])
            ->filter(fn ($pid) => is_numeric($pid))
            ->map(fn ($pid) => (int) $pid)
            ->values();

        if ($pids->isEmpty()) {
            return null;
        }

        $shopOwnerId = Product::query()
            ->whereIn('id', $pids->all())
            ->whereNotNull('shop_owner_id')
            ->value('shop_owner_id');

        return $shopOwnerId ? ShopOwner::query()->find((int) $shopOwnerId) : null;
    }

    private function buildAddressString(array $validated): string
    {
        $parts = [
            $validated['shipping_address_line'] ?? null,
            $validated['shipping_barangay'] ?? null,
            $validated['shipping_city'] ?? null,
            $validated['shipping_region'] ?? null,
            $validated['shipping_postal_code'] ?? null,
            'Philippines',
        ];

        return implode(', ', array_values(array_filter($parts, fn ($part) => filled($part))));
    }

    private function geocodeCustomerAddress(array $validated, string $primaryAddress): ?array
    {
        $queries = [];

        if (filled($primaryAddress)) {
            $queries[] = $primaryAddress;
        }

        $city = trim((string) ($validated['shipping_city'] ?? ''));
        $region = trim((string) ($validated['shipping_region'] ?? ''));
        $postalCode = trim((string) ($validated['shipping_postal_code'] ?? ''));
        $barangay = trim((string) ($validated['shipping_barangay'] ?? ''));
        $addressLine = trim((string) ($validated['shipping_address_line'] ?? ''));

        // Reduce strictness progressively to avoid geocoding misses on noisy inputs.
        if ($addressLine !== '' && $city !== '' && $region !== '') {
            $queries[] = implode(', ', array_filter([$addressLine, $city, $region, 'Philippines']));
        }

        if ($barangay !== '' && $city !== '' && $region !== '') {
            $queries[] = implode(', ', array_filter([$barangay, $city, $region, 'Philippines']));
        }

        if ($city !== '' && $region !== '' && $postalCode !== '') {
            $queries[] = implode(', ', array_filter([$city, $region, $postalCode, 'Philippines']));
        }

        if ($city !== '' && $region !== '') {
            $queries[] = implode(', ', array_filter([$city, $region, 'Philippines']));
        }

        $queries = array_values(array_unique(array_filter($queries)));

        foreach ($queries as $query) {
            $coordinates = $this->geocodeAddress($query);
            if ($coordinates) {
                return $coordinates;
            }
        }

        if ($city !== '') {
            $fallbackCoordinates = $this->resolveCityFallbackCoordinates($city);
            if ($fallbackCoordinates) {
                return $fallbackCoordinates;
            }
        }

        return null;
    }

    private function resolveCityFallbackCoordinates(string $city): ?array
    {
        $normalizedCity = $this->normalizeLocationKey($city);

        return self::CITY_COORDINATE_FALLBACKS[$normalizedCity] ?? null;
    }

    private function normalizeLocationKey(string $value): string
    {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($normalized === false || $normalized === null) {
            $normalized = $value;
        }

        $normalized = strtolower(trim($normalized));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    private function resolveShopCoordinates(ShopOwner $shopOwner): ?array
    {
        if (!empty($shopOwner->shop_latitude) && !empty($shopOwner->shop_longitude)) {
            return [
                'lat' => (float) $shopOwner->shop_latitude,
                'lng' => (float) $shopOwner->shop_longitude,
            ];
        }

        $shopAddress = trim((string) ($shopOwner->shop_address ?: $shopOwner->business_address));
        if ($shopAddress === '') {
            return null;
        }

        return $this->geocodeAddress($shopAddress . ', Philippines');
    }

    private function geocodeAddress(string $address): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'SoleSpace Shipping Estimate/1.0',
                ])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $address,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'countrycodes' => 'ph',
                    'addressdetails' => 0,
                ]);

            if ($response->failed()) {
                return null;
            }

            $result = $response->json();
            if (!is_array($result) || empty($result[0]['lat']) || empty($result[0]['lon'])) {
                return null;
            }

            return [
                'lat' => (float) $result[0]['lat'],
                'lng' => (float) $result[0]['lon'],
            ];
        } catch (\Throwable $exception) {
            Log::warning('Shipping estimate geocoding failed', ['message' => $exception->getMessage()]);
            return null;
        }
    }

    private function getRouteDistanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): ?float
    {
        try {
            $coordinates = sprintf('%s,%s;%s,%s', $fromLng, $fromLat, $toLng, $toLat);

            $response = Http::timeout(10)
                ->acceptJson()
                ->get("https://router.project-osrm.org/route/v1/driving/{$coordinates}", [
                    'overview' => 'false',
                    'alternatives' => 'false',
                    'steps' => 'false',
                ]);

            if ($response->failed()) {
                return null;
            }

            $distanceMeters = (float) data_get($response->json(), 'routes.0.distance', 0);
            if ($distanceMeters <= 0) {
                return null;
            }

            return $distanceMeters / 1000;
        } catch (\Throwable $exception) {
            Log::warning('Shipping estimate route lookup failed', ['message' => $exception->getMessage()]);
            return null;
        }
    }

    private function fallbackResponse(string $reason): JsonResponse
    {
        return response()->json([
            'success' => true,
            'has_estimate' => false,
            'shipping_summary' => 'To be calculated after order',
            'customer_notice' => 'Estimated shipping is unavailable right now. Final fee will be confirmed after order via Lalamove or J&T (third-party carrier).',
            'pay_after_order_notice' => 'Shipping is not included in your checkout total and will be paid upon delivery of your order',
            'reason' => $reason,
        ]);
    }

    private function haversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
