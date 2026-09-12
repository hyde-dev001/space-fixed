<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\CarrierType;
use App\Models\ShopOwner;
use App\Models\Logistics\ShippingMethod;
use App\Models\Logistics\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ShipmentRequestService
{
    public function __construct(private DeliveryEventService $events)
    {
    }

    public function requestShipment(array $payload): Shipment
    {
        $validator = Validator::make($payload, [
            'shop_owner_id' => ['required', 'integer', 'exists:shop_owners,id'],
            'source_type' => ['required', 'string', 'max:80'],
            'source_id' => ['required', 'integer'],
            'purpose' => ['required', 'string', 'max:80'],
            'legs' => ['required', 'array', 'min:1'],
            'legs.*.leg_type' => ['required', 'string', 'max:80'],
            'legs.*.origin_snapshot' => ['nullable', 'array'],
            'legs.*.destination_snapshot' => ['nullable', 'array'],
            'legs.*.shipping_method_id' => ['nullable', 'integer', 'exists:shipping_methods,id'],
            'legs.*.scheduled_delivery_date' => ['nullable', 'date'],
            'legs.*.delivery_window' => ['nullable', 'in:morning,afternoon'],
            'legs.*.schedule_status' => ['nullable', 'string', 'max:40'],
            'legs.*.schedule_override_reason' => ['nullable', 'string'],
            'legs.*.distance_km' => ['nullable', 'numeric', 'min:0'],
            'legs.*.estimated_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        return DB::transaction(function () use ($data) {
            ShopOwner::query()
                ->whereKey($data['shop_owner_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $shipmentNumber = (int) Shipment::query()
                ->where('shop_owner_id', $data['shop_owner_id'])
                ->max('shipment_number') + 1;

            $shipment = Shipment::create([
                'shipment_number' => $shipmentNumber,
                'shop_owner_id' => $data['shop_owner_id'],
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'],
                'purpose' => $data['purpose'],
                'status' => 'requested',
                'requested_by_type' => $data['requested_by_type'] ?? null,
                'requested_by_id' => $data['requested_by_id'] ?? null,
            ]);

            foreach (array_values($data['legs']) as $index => $legData) {
                $method = null;
                if (!empty($legData['shipping_method_id'])) {
                    $method = ShippingMethod::query()
                        ->whereKey($legData['shipping_method_id'])
                        ->where('active', true)
                        ->where('carrier_type', CarrierType::INTERNAL->value)
                        ->where('requires_assignment', true)
                        ->where(function ($query) use ($data) {
                            $query->whereNull('shop_owner_id')
                                ->orWhere('shop_owner_id', $data['shop_owner_id']);
                        })
                        ->first();

                    if (!$method) {
                        throw ValidationException::withMessages([
                            "legs.{$index}.shipping_method_id" => 'Selected shipping method is not supported by shop-owned logistics.',
                        ]);
                    }
                }

                $shipment->legs()->create([
                    'sequence' => $index + 1,
                    'leg_type' => $legData['leg_type'],
                    'status' => 'pending',
                    'shipping_method_id' => $legData['shipping_method_id'] ?? null,
                    'origin_snapshot' => $legData['origin_snapshot'] ?? null,
                    'destination_snapshot' => $legData['destination_snapshot'] ?? null,
                    'requires_pickup_proof' => (bool) ($legData['requires_pickup_proof'] ?? $method?->requires_pickup_proof ?? false),
                    'requires_delivery_proof' => (bool) ($legData['requires_delivery_proof'] ?? $method?->requires_delivery_proof ?? true),
                    'scheduled_delivery_date' => $legData['scheduled_delivery_date'] ?? null,
                    'delivery_window' => $legData['delivery_window'] ?? null,
                    'schedule_status' => $legData['schedule_status'] ?? null,
                    'schedule_override_reason' => $legData['schedule_override_reason'] ?? null,
                    'distance_km' => $legData['distance_km'] ?? null,
                    'estimated_at' => $legData['estimated_at'] ?? null,
                ]);
            }

            $this->events->record($shipment, null, [
                'event_type' => 'shipment_requested',
                'visibility' => 'internal',
                'message' => 'Shipment requested.',
            ]);

            return $shipment->fresh(['legs', 'events']);
        });
    }
}
