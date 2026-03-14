<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CaviteLocationPolicyService
{
    public const DENIAL_MESSAGE = 'Registration is only available for Cavite-based shops.';

    /**
     * Evaluate if a location passes Cavite-only policy.
     *
     * Source-of-truth priority:
     * 1) Coordinates (shop_latitude + shop_longitude)
     * 2) business_address text fallback
     *
     * @param float|int|string|null $latitude
     * @param float|int|string|null $longitude
     * @param string|null $businessAddress
     * @return array
     */
    public function evaluate($latitude, $longitude, ?string $businessAddress): array
    {
        $lat = $this->toFloatOrNull($latitude);
        $lng = $this->toFloatOrNull($longitude);
        $allowedProvinceLabel = $this->allowedProvinceLabel();

        if ($lat !== null && $lng !== null) {
            $matchedProvince = $this->resolveProvinceByCoordinates($lat, $lng);

            return [
                'allowed' => $matchedProvince !== null,
                'source' => 'coordinates',
                'reason' => $matchedProvince !== null ? null : "Shop location must be within {$allowedProvinceLabel} based on map coordinates.",
            ];
        }

        $address = trim((string) ($businessAddress ?? ''));
        if ($address === '') {
            return [
                'allowed' => false,
                'source' => 'address',
                'reason' => "Please provide shop coordinates or a business address within {$allowedProvinceLabel}.",
            ];
        }

        $matchedProvince = $this->resolveProvinceByAddress($address);

        return [
            'allowed' => $matchedProvince !== null,
            'source' => 'address',
            'reason' => $matchedProvince !== null ? null : "Business address must be within {$allowedProvinceLabel}.",
        ];
    }

    /**
     * Registration must include coordinates unless manual-review fallback is enabled.
     *
     * @param float|int|string|null $latitude
     * @param float|int|string|null $longitude
     */
    public function assertRegistrationLocation($latitude, $longitude, ?string $businessAddress, ?Request $request = null, ?Authenticatable $actor = null, array $context = []): array
    {
        $lat = $this->toFloatOrNull($latitude);
        $lng = $this->toFloatOrNull($longitude);
        $auditContext = array_merge($context, [
            'latitude' => $lat,
            'longitude' => $lng,
            'address' => $businessAddress,
        ]);

        if ($lat === null || $lng === null) {
            if (!$this->allowsManualReviewFallback()) {
                $this->recordDeniedAttempt(
                    action: 'shop_owner_registration_location_denied',
                    request: $request,
                    source: 'coordinates',
                    reason: 'Missing required registration coordinates.',
                    actor: $actor,
                    context: $auditContext
                );

                throw ValidationException::withMessages([
                    'shop_latitude' => [$this->denialMessage()],
                    'shop_longitude' => [$this->denialMessage()],
                ]);
            }
        }

        $result = $this->evaluate($latitude, $longitude, $businessAddress);

        if (!$result['allowed']) {
            $this->recordDeniedAttempt(
                action: 'shop_owner_registration_location_denied',
                request: $request,
                source: $result['source'],
                reason: $result['reason'] ?? 'Registration location failed policy evaluation.',
                actor: $actor,
                context: $auditContext
            );

            throw ValidationException::withMessages([
                $result['source'] === 'coordinates' ? 'shop_latitude' : 'business_address' => [
                    $this->denialMessage(),
                ],
            ]);
        }

        return $result;
    }

    /**
     * Validate a location update and return a ready-to-send error payload when invalid.
     *
     * @param float|int|string|null $latitude
     * @param float|int|string|null $longitude
     */
    public function validateUpdateLocation($latitude, $longitude, ?string $businessAddress, ?Request $request = null, ?Authenticatable $actor = null, ?int $shopOwnerId = null, array $context = []): array
    {
        $result = $this->evaluate($latitude, $longitude, $businessAddress);

        if ($result['allowed']) {
            return ['allowed' => true, 'errors' => []] + $result;
        }

        $this->recordDeniedAttempt(
            action: 'shop_owner_location_update_denied',
            request: $request,
            source: $result['source'],
            reason: $result['reason'] ?? 'Location update failed policy evaluation.',
            actor: $actor,
            shopOwnerId: $shopOwnerId,
            context: array_merge($context, [
                'latitude' => $this->toFloatOrNull($latitude),
                'longitude' => $this->toFloatOrNull($longitude),
                'address' => $businessAddress,
            ])
        );

        return [
            'allowed' => false,
            'source' => $result['source'],
            'reason' => $this->denialMessage(),
            'errors' => [
                $result['source'] === 'coordinates' ? 'shop_latitude' : 'business_address' => [
                    $this->denialMessage(),
                ],
            ],
        ];
    }

    public function denialMessage(): string
    {
        return self::DENIAL_MESSAGE;
    }

    public function isLocationPolicyValidationException(ValidationException $exception): bool
    {
        foreach ($exception->errors() as $messages) {
            foreach ((array) $messages as $message) {
                if ($message === $this->denialMessage()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve the first allowed province that contains the given point.
     */
    private function resolveProvinceByCoordinates(float $lat, float $lng): ?string
    {
        foreach ($this->allowedProvinceConfigs() as $provinceName => $provinceConfig) {
            $polygon = $provinceConfig['polygon'] ?? [];
            if ($this->isWithinConfiguredPolygon($lat, $lng, $polygon)) {
                return $provinceName;
            }

            $bounds = $provinceConfig['bounds'] ?? null;
            if (is_array($bounds) && $this->isWithinConfiguredBoundingBox($lat, $lng, $bounds)) {
                return $provinceName;
            }
        }

        return null;
    }

    private function isWithinConfiguredPolygon(float $lat, float $lng, array $polygon): bool
    {
        if (count($polygon) < 3) {
            return false;
        }

        $inside = false;
        $j = count($polygon) - 1;

        for ($i = 0; $i < count($polygon); $i++) {
            $vertexI = $polygon[$i] ?? [];
            $vertexJ = $polygon[$j] ?? [];

            $latI = isset($vertexI['lat']) ? (float) $vertexI['lat'] : null;
            $lngI = isset($vertexI['lng']) ? (float) $vertexI['lng'] : null;
            $latJ = isset($vertexJ['lat']) ? (float) $vertexJ['lat'] : null;
            $lngJ = isset($vertexJ['lng']) ? (float) $vertexJ['lng'] : null;

            if ($latI === null || $lngI === null || $latJ === null || $lngJ === null) {
                $j = $i;
                continue;
            }

            $intersects = (($lngI > $lng) !== ($lngJ > $lng))
                && ($lat < (($latJ - $latI) * ($lng - $lngI) / (($lngJ - $lngI) ?: 0.0000001)) + $latI);

            if ($intersects) {
                $inside = !$inside;
            }

            $j = $i;
        }

        return $inside;
    }

    private function isWithinConfiguredBoundingBox(float $lat, float $lng, array $bounds): bool
    {
        $minLat = isset($bounds['min_lat']) ? (float) $bounds['min_lat'] : null;
        $maxLat = isset($bounds['max_lat']) ? (float) $bounds['max_lat'] : null;
        $minLng = isset($bounds['min_lng']) ? (float) $bounds['min_lng'] : null;
        $maxLng = isset($bounds['max_lng']) ? (float) $bounds['max_lng'] : null;

        if ($minLat === null || $maxLat === null || $minLng === null || $maxLng === null) {
            return false;
        }

        return $lat >= $minLat
            && $lat <= $maxLat
            && $lng >= $minLng
            && $lng <= $maxLng;
    }

    private function allowsManualReviewFallback(): bool
    {
        return (bool) config('location_restrictions.allow_manual_review_fallback', false);
    }

    /**
     * Text fallback rule for addresses.
     */
    private function resolveProvinceByAddress(string $address): ?string
    {
        $normalizedAddress = mb_strtolower($address);

        foreach ($this->allowedProvinceConfigs() as $provinceName => $provinceConfig) {
            $keywords = $provinceConfig['address_keywords'] ?? [];
            $cityNames = $provinceConfig['allowed_city_names'] ?? [];

            foreach (array_merge([$provinceName], $keywords, $cityNames) as $keyword) {
                $needle = mb_strtolower((string) $keyword);
                if ($needle !== '' && str_contains($normalizedAddress, $needle)) {
                    return $provinceName;
                }
            }
        }

        return null;
    }

    private function allowedProvinceLabel(): string
    {
        return implode(', ', $this->allowedProvinceNames()) ?: 'the allowed registration provinces';
    }

    private function allowedProvinceNames(): array
    {
        $provinces = config('location_restrictions.allowed_registration_provinces', ['Cavite']);

        if (!is_array($provinces) || empty($provinces)) {
            return ['Cavite'];
        }

        return array_values(array_filter(array_map(
            static fn ($province) => is_string($province) ? trim($province) : '',
            $provinces,
        )));
    }

    private function allowedProvinceConfigs(): array
    {
        $configuredProvinces = config('location_restrictions.provinces', []);
        $allowedConfigs = [];

        foreach ($this->allowedProvinceNames() as $provinceName) {
            if (isset($configuredProvinces[$provinceName]) && is_array($configuredProvinces[$provinceName])) {
                $allowedConfigs[$provinceName] = $configuredProvinces[$provinceName];
            }
        }

        return $allowedConfigs;
    }

    private function recordDeniedAttempt(
        string $action,
        ?Request $request,
        string $source,
        string $reason,
        ?Authenticatable $actor = null,
        ?int $shopOwnerId = null,
        array $context = []
    ): void {
        $metadata = [
            'message' => $this->denialMessage(),
            'reason' => $reason,
            'source' => $source,
            'allowed_provinces' => $this->allowedProvinceNames(),
            'latitude' => $context['latitude'] ?? null,
            'longitude' => $context['longitude'] ?? null,
            'address' => $context['address'] ?? null,
            'email' => $context['email'] ?? null,
            'business_name' => $context['business_name'] ?? null,
            'route_name' => $request?->route()?->getName(),
            'path' => $request?->path(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ];

        Log::warning('Location policy blocked attempt', [
            'action' => $action,
            'shop_owner_id' => $shopOwnerId,
            'actor_id' => $this->actorId($actor),
            'actor_type' => $actor ? get_class($actor) : null,
            'metadata' => $metadata,
        ]);

        try {
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $this->actorId($actor),
                'action' => $action,
                'target_type' => $context['target_type'] ?? 'location_policy',
                'target_id' => $context['target_id'] ?? $shopOwnerId,
                'metadata' => $metadata,
                'data' => $metadata,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to record location policy audit log', [
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $activity = activity('location_policy')->withProperties($metadata);

            if ($actor !== null) {
                $activity->causedBy($actor);
            }

            $activity->log($action);
        } catch (\Throwable $exception) {
            Log::warning('Failed to record location policy activity log', [
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param mixed $value
     */
    private function toFloatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function actorId(?Authenticatable $actor): ?int
    {
        if ($actor === null) {
            return null;
        }

        $identifier = $actor->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
