<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;

final class DeliveryTypeResolver
{
    /**
     * @return array{delivery_type: string, delivery_label: string}
     */
    public function resolve(?Shipment $shipment, ?ShipmentLeg $leg = null): array
    {
        if (! $shipment) {
            return $this->unknown();
        }

        $leg ??= $shipment->relationLoaded('legs')
            ? $shipment->legs->last()
            : null;

        if ($leg?->leg_type === 'return_to_shop' && $leg->return_for_leg_id !== null) {
            return [
                'delivery_type' => 'return_to_shop',
                'delivery_label' => 'Return to shop',
            ];
        }

        $deliveryType = match (true) {
            $shipment->source_type === 'order'
                && $shipment->purpose === 'retail_delivery'
                && (! $leg || $leg->leg_type === 'outbound') => 'retail_delivery',
            $shipment->source_type === 'order_refund'
                && $shipment->purpose === 'refund_return'
                && (! $leg || $leg->leg_type === 'return_to_shop') => 'retail_return',
            $shipment->source_type === 'repair_request'
                && $shipment->purpose === 'repair_pickup'
                && (! $leg || $leg->leg_type === 'inbound') => 'repair_pickup',
            $shipment->source_type === 'repair_request'
                && $shipment->purpose === 'repair_return'
                && (! $leg || $leg->leg_type === 'outbound') => 'repair_return',
            default => null,
        };

        return $deliveryType
            ? [
                'delivery_type' => $deliveryType,
                'delivery_label' => match ($deliveryType) {
                    'retail_delivery' => 'Retail Delivery',
                    'retail_return' => 'Retail Return',
                    'repair_pickup' => 'Repair Pickup',
                    'repair_return' => 'Repair Return',
                },
            ]
            : $this->unknown();
    }

    /**
     * @return array{delivery_type: string, delivery_label: string}
     */
    private function unknown(): array
    {
        return [
            'delivery_type' => 'unknown',
            'delivery_label' => 'Unknown Delivery Type',
        ];
    }
}
