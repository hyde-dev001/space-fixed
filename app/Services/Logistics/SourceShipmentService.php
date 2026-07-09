<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;

class SourceShipmentService
{
    public function __construct(private ShipmentRequestService $shipments)
    {
    }

    public function ensureRetailOrderShipment(Order $order): Shipment
    {
        $existing = $this->findExisting('order', (int) $order->id, 'retail_delivery');
        if ($existing) {
            return $existing;
        }

        $order->loadMissing('shopOwner');

        return $this->shipments->requestShipment([
            'shop_owner_id' => (int) $order->shop_owner_id,
            'source_type' => 'order',
            'source_id' => (int) $order->id,
            'purpose' => 'retail_delivery',
            'legs' => [[
                'leg_type' => 'outbound',
                'origin_snapshot' => [
                    'type' => 'shop',
                    'name' => (string) ($order->shopOwner?->business_name ?? 'Shop'),
                    'address' => (string) ($order->shopOwner?->business_address ?? ''),
                ],
                'destination_snapshot' => [
                    'type' => 'customer',
                    'name' => (string) ($order->customer_name ?? 'Customer'),
                    'phone' => (string) ($order->customer_phone ?? ''),
                    'address' => (string) ($order->full_shipping_address ?? $order->customer_address ?? ''),
                ],
            ]],
        ]);
    }

    public function ensureRefundReturnShipment(OrderRefund $refund): Shipment
    {
        $existing = $this->findExisting('order_refund', (int) $refund->id, 'refund_return');
        if ($existing) {
            return $existing;
        }

        $refund->loadMissing('order.shopOwner', 'customer');
        $order = $refund->order;

        return $this->shipments->requestShipment([
            'shop_owner_id' => (int) $refund->shop_owner_id,
            'source_type' => 'order_refund',
            'source_id' => (int) $refund->id,
            'purpose' => 'refund_return',
            'legs' => [[
                'leg_type' => 'inbound',
                'origin_snapshot' => [
                    'type' => 'customer',
                    'name' => (string) ($refund->customer?->name ?? $order?->customer_name ?? 'Customer'),
                    'phone' => (string) ($order?->customer_phone ?? ''),
                    'address' => (string) ($order?->full_shipping_address ?? $order?->customer_address ?? ''),
                ],
                'destination_snapshot' => [
                    'type' => 'shop',
                    'name' => (string) ($order?->shopOwner?->business_name ?? 'Shop'),
                    'address' => (string) ($order?->shopOwner?->business_address ?? ''),
                ],
            ]],
        ]);
    }

    public function ensureRepairInboundShipment(RepairRequest $repair): Shipment
    {
        $existing = $this->findExisting('repair_request', (int) $repair->id, 'repair_pickup');
        if ($existing) {
            return $existing;
        }

        $repair->loadMissing('shopOwner');

        return $this->shipments->requestShipment([
            'shop_owner_id' => (int) $repair->shop_owner_id,
            'source_type' => 'repair_request',
            'source_id' => (int) $repair->id,
            'purpose' => 'repair_pickup',
            'legs' => [[
                'leg_type' => 'inbound',
                'origin_snapshot' => [
                    'type' => 'customer',
                    'name' => (string) ($repair->customer_name ?? 'Customer'),
                    'phone' => (string) ($repair->phone ?? ''),
                    'address' => $this->formatAddress($repair->intake_address ?? $repair->pickup_address),
                ],
                'destination_snapshot' => [
                    'type' => 'shop',
                    'name' => (string) ($repair->shopOwner?->business_name ?? 'Shop'),
                    'address' => (string) ($repair->shopOwner?->business_address ?? ''),
                ],
            ]],
        ]);
    }

    public function ensureRepairReturnShipment(RepairRequest $repair): Shipment
    {
        $existing = $this->findExisting('repair_request', (int) $repair->id, 'repair_return');
        if ($existing) {
            return $existing;
        }

        $repair->loadMissing('shopOwner');

        return $this->shipments->requestShipment([
            'shop_owner_id' => (int) $repair->shop_owner_id,
            'source_type' => 'repair_request',
            'source_id' => (int) $repair->id,
            'purpose' => 'repair_return',
            'legs' => [[
                'leg_type' => 'outbound',
                'origin_snapshot' => [
                    'type' => 'shop',
                    'name' => (string) ($repair->shopOwner?->business_name ?? 'Shop'),
                    'address' => (string) ($repair->shopOwner?->business_address ?? ''),
                ],
                'destination_snapshot' => [
                    'type' => 'customer',
                    'name' => (string) ($repair->customer_name ?? 'Customer'),
                    'phone' => (string) ($repair->phone ?? ''),
                    'address' => $this->formatAddress($repair->return_address),
                ],
            ]],
        ]);
    }

    private function findExisting(string $sourceType, int $sourceId, string $purpose): ?Shipment
    {
        return Shipment::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('purpose', $purpose)
            ->first();
    }

    private function formatAddress(mixed $address): string
    {
        if (is_array($address)) {
            return collect([
                $address['address_line'] ?? null,
                $address['barangay'] ?? null,
                $address['city'] ?? null,
                $address['region'] ?? null,
                $address['postal_code'] ?? null,
            ])->filter()->implode(', ');
        }

        return (string) ($address ?? '');
    }
}
