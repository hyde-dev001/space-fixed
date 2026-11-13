<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NominatimService
{
    private const CACHE_TTL_SECONDS = 86400;
    private const HTTP_TIMEOUT_SECONDS = 5;
    private const LOCK_WAIT_TIMEOUT_SECONDS = self::HTTP_TIMEOUT_SECONDS + 1;
    private const LOCK_TTL_SECONDS = 10;
    private const MINIMUM_INTERVAL_MS = 1000;
    private const RESPONSE_KEYS = 'nominatim:response-keys';

    public function search(
        string $query,
        bool $addressDetails = true,
        int $limit = 1,
        bool $waitForDispatch = false,
    ): array
    {
        $query = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
        $limit = max(1, min(5, $limit));

        return $this->request(
            'search',
            [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => $addressDetails ? 1 : 0,
                'countrycodes' => 'ph',
                'limit' => $limit,
            ],
            'nominatim:response:search:'.hash('sha256', Str::lower($query).'|'.(int) $addressDetails.'|'.$limit),
            fn (array $payload) => $this->validSearch($payload, $addressDetails),
            $addressDetails,
            $waitForDispatch,
        );
    }

    public function reverse(float $latitude, float $longitude): array
    {
        return $this->request(
            'reverse',
            [
                'lat' => $latitude,
                'lon' => $longitude,
                'format' => 'jsonv2',
                'addressdetails' => 1,
            ],
            $this->reverseCacheKey($latitude, $longitude),
            fn (array $payload) => $this->validReverse($payload),
        );
    }

    private function request(
        string $path,
        array $parameters,
        string $cacheKey,
        callable $valid,
        bool $primeReverse = false,
        bool $waitForDispatch = false,
    ): array
    {
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $lock = Cache::lock('nominatim:dispatch-lock', self::LOCK_TTL_SECONDS);
        try {
            $acquired = $waitForDispatch
                ? $lock->block(self::LOCK_WAIT_TIMEOUT_SECONDS)
                : $lock->get();
        } catch (LockTimeoutException) {
            $acquired = false;
        }
        if (! $acquired) {
            throw new RuntimeException('Address lookup is busy.', 429);
        }

        try {
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            $now = now()->getTimestampMs();
            $lastDispatch = (int) Cache::get('nominatim:last-dispatch-ms', 0);
            if ($lastDispatch && $now - $lastDispatch < self::MINIMUM_INTERVAL_MS) {
                if (! $waitForDispatch) {
                    throw new RuntimeException('Address lookup is busy.', 429);
                }

                do {
                    $remainingMs = self::MINIMUM_INTERVAL_MS - ($now - $lastDispatch);
                    usleep(max(1, $remainingMs) * 1000);
                    $now = now()->getTimestampMs();
                } while ($now - $lastDispatch < self::MINIMUM_INTERVAL_MS);
            }

            Cache::forever('nominatim:last-dispatch-ms', $now);

            try {
                $response = Http::acceptJson()
                    ->withUserAgent((string) config('services.nominatim.user_agent'))
                    ->timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->get(rtrim((string) config('services.nominatim.url'), '/').'/'.$path, $parameters);
            } catch (Throwable) {
                throw new RuntimeException('Address lookup is unavailable.', 502);
            }

            $payload = $response->json();
            if ($response->failed() || ! is_array($payload) || ! $valid($payload)) {
                throw new RuntimeException('Address lookup is unavailable.', 502);
            }

            $this->store($cacheKey, $payload);
            if ($primeReverse) {
                foreach ($payload as $result) {
                    $this->store(
                        $this->reverseCacheKey((float) $result['lat'], (float) $result['lon']),
                        $result,
                    );
                }
            }

            return $payload;
        } finally {
            if ($lock->isOwnedByCurrentProcess()) {
                $lock->release();
            }
        }
    }

    private function validSearch(array $payload, bool $addressDetails): bool
    {
        if (! array_is_list($payload)) {
            return false;
        }

        foreach ($payload as $result) {
            if (! is_array($result)
                || ! $this->coordinate($result['lat'] ?? null)
                || ! $this->coordinate($result['lon'] ?? null)
                || ($addressDetails && ! is_array($result['address'] ?? null))) {
                return false;
            }
        }

        return true;
    }

    private function validReverse(array $payload): bool
    {
        return ! array_is_list($payload)
            && is_array($payload['address'] ?? null)
            && $this->coordinate($payload['lat'] ?? null)
            && $this->coordinate($payload['lon'] ?? null);
    }

    private function coordinate(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }

    private function store(string $key, array $payload): void
    {
        $keys = Cache::get(self::RESPONSE_KEYS, []);
        $keys = is_array($keys) ? array_values(array_filter($keys, fn ($stored) => $stored !== $key)) : [];
        $keys[] = $key;

        $maximum = max(1, (int) config('services.nominatim.cache_max_entries', 500));
        while (count($keys) > $maximum) {
            Cache::forget((string) array_shift($keys));
        }

        Cache::forever(self::RESPONSE_KEYS, $keys);
        Cache::put($key, $payload, self::CACHE_TTL_SECONDS);
    }

    private function reverseCacheKey(float $latitude, float $longitude): string
    {
        return 'nominatim:response:reverse:'.hash('sha256', sprintf('%.6F,%.6F', $latitude, $longitude));
    }
}
