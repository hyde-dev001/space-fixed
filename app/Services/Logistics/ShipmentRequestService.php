<?php

namespace App\Services\Logistics;

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
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        return DB::transaction(function () use ($data) {
            $shipment = Shipment::create([
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
                    $method = \App\Models\Logistics\ShippingMethod::query()
                        ->whereKey($legData['shipping_method_id'])
                        ->where(function ($query) use ($data) {
                            $query->whereNull('shop_owner_id')
                                ->orWhere('shop_owner_id', $data['shop_owner_id']);
                        })
                        ->first();

                    if (!$method) {
                        throw ValidationException::withMessages(['legs' => 'Selected shipping method is not available for this shop.']);
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
