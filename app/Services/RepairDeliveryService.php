<?php

namespace App\Services;

use App\Models\Logistics\Shipment;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\UserAddress;
use App\Services\Logistics\DeliveryScheduleService;
use Illuminate\Validation\ValidationException;

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

    public function paymentDetails(RepairRequest $repair, string $leg): array
    {
        $isIntake = $leg === 'intake';
        $method = (string) ($isIntake ? $repair->intake_delivery_method : $repair->return_delivery_method);
        $snapshot = $isIntake ? $repair->intake_address : $repair->return_address;
        $storedFee = round((float) ($isIntake ? $repair->intake_delivery_fee : $repair->return_delivery_fee), 2);
        $shopOwned = $method === ($isIntake ? 'shop_pickup' : 'shop_delivery');

        if (! $shopOwned) {
            return [
                'leg' => $leg,
                'method' => $method,
                'snapshot_version' => is_array($snapshot) ? ($snapshot['version'] ?? null) : null,
                'delivery_amount' => 0.0,
                'quote' => null,
            ];
        }

        $field = $isIntake ? 'intake_address' : 'return_address';
        $addressId = is_array($snapshot) ? (int) ($snapshot['address_id'] ?? 0) : 0;
        $address = UserAddress::query()
            ->whereKey($addressId)
            ->where('user_id', $repair->user_id)
            ->first();

        if (! $address || ! $repair->shopOwner) {
            throw ValidationException::withMessages([
                $field => ['The selected delivery address is no longer available. Please review the delivery plan.'],
            ]);
        }

        $currentSnapshot = $this->snapshot($address, $method);
        if (! hash_equals((string) ($snapshot['version'] ?? ''), (string) $currentSnapshot['version'])) {
            throw ValidationException::withMessages([
                $field => ['The delivery address changed. Please review and confirm the latest pinned address before paying.'],
            ]);
        }

        $quote = $this->quote($repair->shopOwner, $address);
        if (! ($quote['available'] ?? false)) {
            throw ValidationException::withMessages([
                $field => [($quote['reason'] ?? null) === 'outside_coverage'
                    ? 'The address is now outside shop delivery coverage. Choose another delivery method.'
                    : 'Shop-owned delivery is currently unavailable for this address.'],
            ]);
        }

        $currentFee = round((float) ($quote['fee'] ?? 0), 2);
        if ($currentFee !== $storedFee) {
            throw ValidationException::withMessages([
                $field => ['The delivery fee changed. Please refresh the delivery plan before paying.'],
            ]);
        }

        return [
            'leg' => $leg,
            'method' => $method,
            'snapshot_version' => $currentSnapshot['version'],
            'delivery_amount' => $currentFee,
            'quote' => $quote,
        ];
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
