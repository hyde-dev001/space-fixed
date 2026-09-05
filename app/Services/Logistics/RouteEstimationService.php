<?php

namespace App\Services\Logistics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RouteEstimationService
{
    /** @return array{distance_m: float, duration_s: int, geometry: array<int, array{0: float, 1: float}>, source: string}|null */
    public function estimate(?array $from, ?array $to): ?array
    {
        $origin = $this->coordinates($from);
        $destination = $this->coordinates($to);

        if (! config('logistics_tracking.routing.enabled', false) || ! $origin || ! $destination) {
            return null;
        }

        $key = 'logistics-route:' . md5(json_encode([
            $this->cacheCoordinate($origin['latitude']),
            $this->cacheCoordinate($origin['longitude']),
            $this->cacheCoordinate($destination['latitude']),
            $this->cacheCoordinate($destination['longitude']),
        ]));

        return Cache::remember(
            $key,
            now()->addSeconds(max(1, (int) config('logistics_tracking.routing.cache_seconds', 60))),
            function () use ($origin, $destination): ?array {
                $roadRoute = $this->roadEstimate($origin, $destination);
                if ($roadRoute) {
                    return $roadRoute;
                }

                return (bool) config('logistics_tracking.routing.fallback_to_direct', true)
                    ? $this->directEstimate($origin, $destination)
                    : null;
            },
        );
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function coordinates(?array $point): ?array
    {
        if (! is_numeric($point['latitude'] ?? null) || ! is_numeric($point['longitude'] ?? null)) {
            return null;
        }

        $latitude = (float) $point['latitude'];
        $longitude = (float) $point['longitude'];

        return is_finite($latitude) && is_finite($longitude)
            && $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180
            ? compact('latitude', 'longitude')
            : null;
    }

    private function cacheCoordinate(float $coordinate): string
    {
        return number_format(round($coordinate, 3), 3, '.', '');
    }

    /** @param array{latitude: float, longitude: float} $origin */
    /** @param array{latitude: float, longitude: float} $destination */
    private function directEstimate(array $origin, array $destination): array
    {
        // ponytail: direct fallback keeps tracking usable when the public router is unavailable.
        $distance = $this->distanceMeters(
            $origin['latitude'],
            $origin['longitude'],
            $destination['latitude'],
            $destination['longitude'],
        );
        $speed = max(1, (float) config('logistics_tracking.routing.eta_speed_mps', 8.33));

        return [
            'distance_m' => $distance,
            'duration_s' => (int) ceil($distance / $speed),
            'geometry' => [
                [$origin['latitude'], $origin['longitude']],
                [$destination['latitude'], $destination['longitude']],
            ],
            'source' => 'direct',
        ];
    }

    /** @param array{latitude: float, longitude: float} $origin */
    /** @param array{latitude: float, longitude: float} $destination */
    private function roadEstimate(array $origin, array $destination): ?array
    {
        if (strtolower((string) config('logistics_tracking.routing.provider', 'osrm')) !== 'osrm') {
            return null;
        }

        $baseUrl = rtrim((string) config('logistics_tracking.routing.base_url', ''), '/');
        if ($baseUrl === '') {
            return null;
        }

        $coordinates = implode(';', [
            sprintf('%.8F,%.8F', $origin['longitude'], $origin['latitude']),
            sprintf('%.8F,%.8F', $destination['longitude'], $destination['latitude']),
        ]);

        try {
            $response = Http::acceptJson()
                ->timeout(max(1, (float) config('logistics_tracking.routing.timeout_seconds', 3)))
                ->get("{$baseUrl}/route/v1/driving/{$coordinates}", [
                    'alternatives' => 'false',
                    'overview' => 'full',
                    'geometries' => 'geojson',
                    'steps' => 'false',
                ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('code') !== 'Ok') {
            return null;
        }

        $route = $response->json('routes.0');
        $distance = is_array($route) ? ($route['distance'] ?? null) : null;
        $duration = is_array($route) ? ($route['duration'] ?? null) : null;
        $coordinates = is_array($route) ? data_get($route, 'geometry.coordinates') : null;
        if (! is_numeric($distance) || ! is_numeric($duration) || ! is_array($coordinates)) {
            return null;
        }

        $geometry = [];
        foreach ($coordinates as $coordinate) {
            if (! is_array($coordinate)
                || ! is_numeric($coordinate[0] ?? null)
                || ! is_numeric($coordinate[1] ?? null)) {
                continue;
            }

            $longitude = (float) $coordinate[0];
            $latitude = (float) $coordinate[1];
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                continue;
            }

            $geometry[] = [$latitude, $longitude];
        }

        return count($geometry) >= 2
            ? [
                'distance_m' => (float) $distance,
                'duration_s' => (int) ceil((float) $duration),
                'geometry' => $geometry,
                'source' => 'road',
            ]
            : null;
    }

    private function distanceMeters(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadiusMeters = 6371000;
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $fromLatitude = deg2rad($fromLatitude);
        $toLatitude = deg2rad($toLatitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitude) * cos($toLatitude) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusMeters * 2 * asin(min(1, sqrt($a)));
    }
}
