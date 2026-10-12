<?php

namespace Tests\Unit\Services\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Services\Logistics\DeliveryTypeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryTypeResolverTest extends TestCase
{
    #[DataProvider('canonicalDeliveryTypes')]
    public function test_it_resolves_canonical_delivery_types(
        string $sourceType,
        string $purpose,
        string $legType,
        string $deliveryType,
        string $deliveryLabel,
    ): void {
        $shipment = new Shipment([
            'source_type' => $sourceType,
            'purpose' => $purpose,
        ]);
        $leg = new ShipmentLeg([
            'leg_type' => $legType,
        ]);

        $resolved = app(DeliveryTypeResolver::class)->resolve($shipment, $leg);

        $this->assertSame([
            'delivery_type' => $deliveryType,
            'delivery_label' => $deliveryLabel,
        ], $resolved);
    }

    public static function canonicalDeliveryTypes(): array
    {
        return [
            'retail delivery' => [
                'order',
                'retail_delivery',
                'outbound',
                'retail_delivery',
                'Retail Delivery',
            ],
            'retail return' => [
                'order_refund',
                'refund_return',
                'return_to_shop',
                'retail_return',
                'Retail Return',
            ],
            'repair pickup' => [
                'repair_request',
                'repair_pickup',
                'inbound',
                'repair_pickup',
                'Repair Pickup',
            ],
            'repair return' => [
                'repair_request',
                'repair_return',
                'outbound',
                'repair_return',
                'Repair Return',
            ],
        ];
    }

    public function test_it_resolves_failed_delivery_recovery_as_return_to_shop(): void
    {
        $shipment = new Shipment([
            'source_type' => 'order',
            'purpose' => 'retail_delivery',
        ]);
        $leg = new ShipmentLeg([
            'leg_type' => 'return_to_shop',
            'return_for_leg_id' => 7,
        ]);

        $resolved = app(DeliveryTypeResolver::class)->resolve($shipment, $leg);

        $this->assertSame([
            'delivery_type' => 'return_to_shop',
            'delivery_label' => 'Return to shop',
        ], $resolved);
    }

    public function test_unknown_classifications_do_not_default_to_retail_delivery(): void
    {
        $shipment = new Shipment([
            'source_type' => 'unknown_source',
            'purpose' => 'unknown_purpose',
        ]);
        $leg = new ShipmentLeg([
            'leg_type' => 'outbound',
        ]);

        $resolved = app(DeliveryTypeResolver::class)->resolve($shipment, $leg);

        $this->assertSame([
            'delivery_type' => 'unknown',
            'delivery_label' => 'Unknown Delivery Type',
        ], $resolved);
    }
}
