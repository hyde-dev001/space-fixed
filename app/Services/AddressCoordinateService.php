<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AddressCoordinateService
{
    public function __construct(private readonly NominatimService $nominatim) {}

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
        $localityQuery = implode(', ', array_filter([
            $address['city'] ?? $address['shipping_city'] ?? null,
            $address['province'] ?? $address['region'] ?? $address['shipping_region'] ?? null,
            $address['postal_code'] ?? $address['shipping_postal_code'] ?? null,
            'Philippines',
        ]));

        try {
            $result = $this->nominatim->search($query, false, 1, true);
            if (isset($result[0])) {
                return ['latitude' => (float) $result[0]['lat'], 'longitude' => (float) $result[0]['lon']];
            }

            if ($localityQuery !== $query) {
                $result = $this->nominatim->search($localityQuery, false, 1, true);
                if (isset($result[0])) {
                    return ['latitude' => (float) $result[0]['lat'], 'longitude' => (float) $result[0]['lon']];
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Address geocoding failed', ['message' => $exception->getMessage()]);
        }

        return null;
    }
}
