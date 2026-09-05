<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\UserAddress;
use App\Services\AddressCoordinateService;
use App\Services\Logistics\DeliveryScheduleService;
use App\Services\NominatimService;
use App\Services\ShippingEstimateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShippingEstimateController extends Controller
{
    public function __construct(
        private readonly ShippingEstimateService $shippingEstimateService,
        private readonly AddressCoordinateService $coordinates,
        private readonly DeliveryScheduleService $deliverySchedules,
        private readonly NominatimService $nominatim,
    ) {}

    public function estimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_pids' => [
                'bail',
                'nullable',
                'array',
                'max:100',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    foreach ($value as $pid) {
                        if (!is_scalar($pid)) {
                            $fail('The item pids field contains an invalid product identifier.');
                            return;
                        }
                    }
                },
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'item_pids.*' => ['integer'],
            'address_id' => ['nullable', 'integer'],
            'shipping_address_line' => ['nullable', 'string', 'max:255'],
            'shipping_barangay' => ['nullable', 'string', 'max:100'],
            'shipping_city' => ['required', 'string', 'max:100'],
            'shipping_region' => ['required', 'string', 'max:100'],
            'shipping_postal_code' => ['nullable', 'string', 'max:10'],
            'shipping_latitude' => ['nullable', 'required_with:shipping_longitude', 'numeric', 'between:4.5,21.5'],
            'shipping_longitude' => ['nullable', 'required_with:shipping_latitude', 'numeric', 'between:116,127'],
        ]);

        $address = null;
        if (($validated['address_id'] ?? null) !== null) {
            $address = $request->user('user')?->addresses()->find((int) $validated['address_id']);
            if (!$address) {
                throw ValidationException::withMessages(['address_id' => 'The selected address is invalid.']);
            }
        }

        $shopOwner = $this->resolveShopOwner($validated);
        if (!$shopOwner) {
            return $this->fallbackResponse('Shop information is unavailable.');
        }
        $draftCoordinates = isset($validated['shipping_latitude'], $validated['shipping_longitude'])
            ? ['lat' => (float) $validated['shipping_latitude'], 'lng' => (float) $validated['shipping_longitude']]
            : null;
        $coverage = $this->shopOwnedCoverage($shopOwner, $address, $draftCoordinates);

        $shopCoordinates = $this->resolveShopCoordinates($shopOwner);
        if (!$shopCoordinates) {
            return $this->fallbackResponse('Shop location is unavailable.', $coverage);
        }

        $customerCoordinates = $draftCoordinates;
        if (!$customerCoordinates) {
            $resolved = $this->coordinates->geocode($validated);
            $customerCoordinates = $resolved ? [
                'lat' => $resolved['latitude'],
                'lng' => $resolved['longitude'],
            ] : null;
        }
        if (!$customerCoordinates) {
            return $this->fallbackResponse('Unable to resolve customer location.', $coverage);
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
                return $this->fallbackResponse('Unable to calculate route distance.', $coverage);
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
            'shop_owned' => $coverage,
        ]);
    }

    private function resolveShopOwner(array $validated): ?ShopOwner
    {
        $pids = collect($validated['item_pids'] ?? [])
            ->filter(fn ($pid) => is_numeric($pid))
            ->map(fn ($pid) => (int) $pid)
            ->values();

        if ($pids->isEmpty()) {
            return null;
        }

        $shopOwnerIds = Product::query()
            ->whereIn('id', $pids->all())
            ->pluck('shop_owner_id')
            ->unique();

        if ($shopOwnerIds->contains(null)) {
            throw ValidationException::withMessages(['item_pids' => 'Products must belong to a shop.']);
        }

        if ($shopOwnerIds->count() > 1) {
            throw ValidationException::withMessages(['item_pids' => 'Products must belong to one shop.']);
        }

        return $shopOwnerIds->isNotEmpty()
            ? ShopOwner::query()->find((int) $shopOwnerIds->first())
            : null;
    }

    private function shopOwnedCoverage(ShopOwner $shopOwner, ?UserAddress $address, ?array $draftCoordinates): array
    {
        try {
            return $this->deliverySchedules->coverage(
                $shopOwner,
                $draftCoordinates['lat'] ?? ($address?->latitude !== null ? (float) $address->latitude : null),
                $draftCoordinates['lng'] ?? ($address?->longitude !== null ? (float) $address->longitude : null),
            );
        } catch (\Throwable $exception) {
            Log::warning('Shipping estimate logistics coverage failed', ['message' => $exception->getMessage()]);

            return [
                'available' => false,
                'reason' => 'logistics_unavailable',
                'distance_km' => null,
                'coverage_radius_km' => null,
            ];
        }
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
            $result = $this->nominatim->search($address, false, 1, true);
            if (! isset($result[0])) {
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

    private function fallbackResponse(string $reason, ?array $coverage = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'has_estimate' => false,
            'shipping_summary' => 'To be calculated after order',
            'customer_notice' => 'Estimated shipping is unavailable right now. Final fee will be confirmed after order via Lalamove or J&T (third-party carrier).',
            'pay_after_order_notice' => 'Shipping is not included in your checkout total and will be paid upon delivery of your order',
            'reason' => $reason,
            'shop_owned' => $coverage ?? [
                'available' => false,
                'reason' => 'logistics_unavailable',
                'distance_km' => null,
                'coverage_radius_km' => null,
            ],
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
