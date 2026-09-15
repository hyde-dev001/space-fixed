<?php

namespace App\Services\Logistics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RouteEstimationService
{
    /** @return array{distance_m: float, duration_s: int, geometry: array<int, array{0: float, 1: float}>, source: string}|null */
    public function estimate(?array $from, ?array $to, bool $useCache = true): ?array
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

        $estimate = function () use ($origin, $destination): ?array {
            $roadRoute = $this->roadEstimate($origin, $destination);
            if ($roadRoute) {
                return $roadRoute;
            }

            return (bool) config('logistics_tracking.routing.fallback_to_direct', true)
                ? $this->directEstimate($origin, $destination)
                : null;
        };

        return $useCache
            ? Cache::remember(
                $key,
                now()->addSeconds(max(1, (int) config('logistics_tracking.routing.cache_seconds', 60))),
                $estimate,
            )
            : $estimate();
    }

    /** @param array<int, array{0: int|float|string, 1: int|float|string}> $geometry */
    public function distanceForGeometry(array $geometry): float
    {
        $geometry = $this->normaliseGeometry($geometry);
        $distance = 0.0;

        foreach (array_keys($geometry) as $index) {
            if (! isset($geometry[$index + 1])) {
                break;
            }

            $distance += $this->distanceMeters(
                $geometry[$index][0],
                $geometry[$index][1],
                $geometry[$index + 1][0],
                $geometry[$index + 1][1],
            );
        }

        return $distance;
    }

    /**
     * @param  array{latitude: int|float|string, longitude: int|float|string}  $point
     * @param  array<int, array{0: int|float|string, 1: int|float|string}>  $geometry
     * @return array{point: array{latitude: float, longitude: float}, distance_m: float, progress_m: float, segment_index: int}|null
     */
    public function projectOntoRoute(array $point, array $geometry): ?array
    {
        $point = $this->coordinates($point);
        $geometry = $this->normaliseGeometry($geometry);

        if (! $point || count($geometry) < 2) {
            return null;
        }

        $metersPerLatitude = 111320.0;
        $metersPerLongitude = max(0.0001, $metersPerLatitude * cos(deg2rad($point['latitude'])));
        $best = null;
        $travelled = 0.0;

        foreach (array_keys($geometry) as $index) {
            if (! isset($geometry[$index + 1])) {
                break;
            }

            [$startLatitude, $startLongitude] = $geometry[$index];
            [$endLatitude, $endLongitude] = $geometry[$index + 1];
            $startX = ($startLongitude - $point['longitude']) * $metersPerLongitude;
            $startY = ($startLatitude - $point['latitude']) * $metersPerLatitude;
            $endX = ($endLongitude - $point['longitude']) * $metersPerLongitude;
            $endY = ($endLatitude - $point['latitude']) * $metersPerLatitude;
            $deltaX = $endX - $startX;
            $deltaY = $endY - $startY;
            $segmentLengthSquared = ($deltaX ** 2) + ($deltaY ** 2);
            $segmentLength = sqrt($segmentLengthSquared);
            $ratio = $segmentLengthSquared > 0
                ? (($startX * -$deltaX) + ($startY * -$deltaY)) / $segmentLengthSquared
                : 0.0;
            $ratio = min(1.0, max(0.0, $ratio));
            $projectedX = $startX + ($deltaX * $ratio);
            $projectedY = $startY + ($deltaY * $ratio);
            $distanceSquared = ($projectedX ** 2) + ($projectedY ** 2);

            if ($best === null || $distanceSquared < $best['distance_squared']) {
                $best = [
                    'distance_squared' => $distanceSquared,
                    'point' => [
                        'latitude' => (float) ($point['latitude'] + ($projectedY / $metersPerLatitude)),
                        'longitude' => (float) ($point['longitude'] + ($projectedX / $metersPerLongitude)),
                    ],
                    'progress_m' => $travelled + ($segmentLength * $ratio),
                    'segment_index' => $index,
                ];
            }

            $travelled += $segmentLength;
        }

        return $best === null
            ? null
            : [
                'point' => $best['point'],
                'distance_m' => sqrt($best['distance_squared']),
                'progress_m' => $best['progress_m'],
                'segment_index' => $best['segment_index'],
            ];
    }

    /**
     * @param  array<int, array{0: int|float|string, 1: int|float|string}>  $geometry
     * @param  array{latitude: int|float|string, longitude: int|float|string}  $point
     * @return array{geometry: array<int, array{0: float, 1: float}>, projected_point: array{latitude: float, longitude: float}, distance_to_route_m: float, progress_m: float}|null
     */
    public function trimRoute(array $geometry, array $point, float $toleranceMeters = 25): ?array
    {
        $geometry = $this->normaliseGeometry($geometry);
        $projection = $this->projectOntoRoute($point, $geometry);

        if (! $projection) {
            return null;
        }

        if ($projection['progress_m'] <= max(0, $toleranceMeters)) {
            return [
                'geometry' => $geometry,
                'projected_point' => $projection['point'],
                'distance_to_route_m' => $projection['distance_m'],
                'progress_m' => $projection['progress_m'],
            ];
        }

        $trimmed = [[$projection['point']['latitude'], $projection['point']['longitude']]];
        foreach (array_slice($geometry, $projection['segment_index'] + 1) as $coordinate) {
            $trimmed[] = $coordinate;
        }

        if (count($trimmed) === 1) {
            $trimmed[] = $geometry[array_key_last($geometry)];
        }

        return [
            'geometry' => $trimmed,
            'projected_point' => $projection['point'],
            'distance_to_route_m' => $projection['distance_m'],
            'progress_m' => $projection['progress_m'],
        ];
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

    /** @param array<int, array{0: mixed, 1: mixed}> $geometry */
    private function normaliseGeometry(array $geometry): array
    {
        $normalised = [];
        foreach ($geometry as $coordinate) {
            if (! is_array($coordinate)
                || ! is_numeric($coordinate[0] ?? null)
                || ! is_numeric($coordinate[1] ?? null)) {
                continue;
            }

            $latitude = (float) $coordinate[0];
            $longitude = (float) $coordinate[1];
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                continue;
            }

            $normalised[] = [$latitude, $longitude];
        }

        return $normalised;
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
