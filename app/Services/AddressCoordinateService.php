<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AddressCoordinateService
{
    public function geocode(array $address): ?array
    {
        $query = implode(', ', array_filter([
            $address['address_line'] ?? $address['shipping_address_line'] ?? null,
            $address['barangay'] ?? $address['shipping_barangay'] ?? null,
            $address['city'] ?? $address['shipping_city'] ?? null,
            $address['province'] ?? null,
            $address['region'] ?? $address['shipping_region'] ?? null,
            $address['postal_code'] ?? $address['shipping_postal_code'] ?? null,
            'Philippines',
        ]));

        try {
            $result = Http::timeout(10)->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'SoleSpace/1.0',
            ])->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query, 'format' => 'jsonv2', 'limit' => 1,
                'countrycodes' => 'ph', 'addressdetails' => 0,
            ])->json();

            return isset($result[0]['lat'], $result[0]['lon'])
                ? ['latitude' => (float) $result[0]['lat'], 'longitude' => (float) $result[0]['lon']]
                : null;
        } catch (\Throwable $exception) {
            Log::warning('Address geocoding failed', ['message' => $exception->getMessage()]);
            return null;
        }
    }
}
