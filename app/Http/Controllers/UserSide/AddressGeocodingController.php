<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class AddressGeocodingController extends Controller
{
    private const CACHE_TTL_SECONDS = 86400;
    private const HTTP_TIMEOUT_SECONDS = 5;
    private const LOCK_TTL_SECONDS = 10;
    private const MINIMUM_INTERVAL_MS = 1000;

    public function __invoke(Request $request): JsonResponse
    {
        if (is_string($request->query('q'))) {
            $request->merge(['q' => preg_replace('/\s+/u', ' ', trim($request->query('q'))) ?? '']);
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:200', 'required_without_all:latitude,longitude', 'prohibits:latitude,longitude'],
            'latitude' => ['nullable', 'numeric', 'between:4.5,21.5', 'required_without:q', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:116,127', 'required_without:q', 'required_with:latitude'],
        ]);

        [$path, $parameters, $cacheKey] = isset($validated['q'])
            ? $this->searchRequest($validated['q'])
            : $this->reverseRequest((float) $validated['latitude'], (float) $validated['longitude']);

        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $lock = Cache::lock('nominatim:dispatch-lock', self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            return $this->busy();
        }

        try {
            if (Cache::has($cacheKey)) {
                return response()->json(Cache::get($cacheKey));
            }

            $now = now()->getTimestampMs();
            $lastDispatch = (int) Cache::get('nominatim:last-dispatch-ms', 0);
            if ($lastDispatch && $now - $lastDispatch < self::MINIMUM_INTERVAL_MS) {
                return $this->busy();
            }

            Cache::forever('nominatim:last-dispatch-ms', $now);

            try {
                $response = Http::acceptJson()
                    ->withUserAgent((string) config('services.nominatim.user_agent'))
                    ->timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->get(rtrim((string) config('services.nominatim.url'), '/').'/'.$path, $parameters);
            } catch (Throwable) {
                return $this->unavailable();
            }

            if ($response->failed() || ! is_array($payload = $response->json())) {
                return $this->unavailable();
            }

            Cache::put($cacheKey, $payload, self::CACHE_TTL_SECONDS);

            return response()->json($payload);
        } finally {
            $lock->release();
        }
    }

    private function searchRequest(string $query): array
    {
        $parameters = [
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'countrycodes' => 'ph',
            'limit' => 1,
        ];

        return ['search', $parameters, 'nominatim:response:search:'.hash('sha256', Str::lower($query))];
    }

    private function reverseRequest(float $latitude, float $longitude): array
    {
        $parameters = [
            'lat' => $latitude,
            'lon' => $longitude,
            'format' => 'jsonv2',
            'addressdetails' => 1,
        ];
        $normalized = sprintf('%.6F,%.6F', $latitude, $longitude);

        return ['reverse', $parameters, 'nominatim:response:reverse:'.hash('sha256', $normalized)];
    }

    private function busy(): JsonResponse
    {
        return response()->json([
            'message' => 'Address lookup is busy. Please try again shortly.',
            'retry_after' => 1,
        ], 429)->header('Retry-After', '1');
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'Address lookup is unavailable. Please try again.',
        ], 502);
    }
}
