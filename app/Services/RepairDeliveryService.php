<?php

namespace App\Services;

use App\Models\Logistics\Shipment;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\UserAddress;
use App\Services\Logistics\DeliveryScheduleService;

final class RepairDeliveryService
{
    public function __construct(
        private DeliveryScheduleService $schedules,
        private ShippingEstimateService $shipping,
    ) {
    }

    public function snapshot(UserAddress $address, string $method): array
    {
        $snapshot = [
            'address_id' => (int) $address->id,
            'name' => (string) $address->name,
            'phone' => (string) $address->phone,
            'address_line' => (string) $address->address_line,
            'barangay' => (string) $address->barangay,
            'city' => (string) $address->city,
            'province' => (string) $address->province,
            'region' => (string) $address->region,
            'postal_code' => $address->postal_code,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'delivery_instructions' => $address->delivery_instructions,
            'method' => $method,
        ];
        $snapshot['version'] = $this->version($snapshot, $method);

        return $snapshot;
    }

    public function version(array $snapshot, string $method): string
    {
        unset($snapshot['version']);
        $snapshot['method'] = $method;
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function quote(ShopOwner $shop, UserAddress $address): array
    {
        $coverage = $this->schedules->coverage($shop, $address->latitude, $address->longitude);
        if (! $coverage['available']) {
            return [...$coverage, 'fee' => null, 'estimate' => null];
        }

        $estimate = $this->shipping->calculate((float) $coverage['distance_km']);

        return [...$coverage, 'fee' => $estimate['max_fee'], 'estimate' => $estimate];
    }

    public function hasApprovedProof(RepairRequest $repair, string $purpose): bool
    {
        return Shipment::query()
            ->where('source_type', 'repair_request')
            ->where('source_id', $repair->id)
            ->where('purpose', $purpose)
            ->whereHas('legs.proofs', fn ($proofs) => $proofs->where('review_status', 'approved'))
            ->exists();
    }
}
