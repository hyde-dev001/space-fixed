<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\OrderTransitionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrderTransitionPolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('transitionMatrix')]
    public function it_allows_only_canonical_order_transitions(
        OrderStatus $from,
        OrderStatus $target,
        bool $hasAuthoritativeDirectFulfillment,
        bool $expected,
    ): void {
        $order = Order::make(['status' => $from->value]);
        $policy = new OrderTransitionPolicy();

        $actual = match ($target) {
            OrderStatus::PROCESSING => $policy->canMarkProcessing($order),
            OrderStatus::SHIPPED => $policy->canMarkShipped($order),
            OrderStatus::DELIVERED => $policy->canConfirmDelivered($order),
            OrderStatus::COMPLETED => $policy->canCompleteDirectly($order, $hasAuthoritativeDirectFulfillment),
            default => false,
        };

        $this->assertSame($expected, $actual, "Unexpected transition {$from->value} -> {$target->value}");
    }

    public static function transitionMatrix(): iterable
    {
        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $target) {
                yield "{$from->value} -> {$target->value} without direct fulfillment" => [
                    $from,
                    $target,
                    false,
                    self::expectedTransition($from, $target, false),
                ];

                if ($target === OrderStatus::COMPLETED && in_array($from, [OrderStatus::PENDING, OrderStatus::PROCESSING], true)) {
                    yield "{$from->value} -> completed with direct fulfillment" => [
                        $from,
                        $target,
                        true,
                        true,
                    ];
                }
            }
        }
    }

    private static function expectedTransition(OrderStatus $from, OrderStatus $target, bool $hasAuthoritativeDirectFulfillment): bool
    {
        return match (true) {
            $from === OrderStatus::PENDING && $target === OrderStatus::PROCESSING => true,
            $from === OrderStatus::PROCESSING && $target === OrderStatus::SHIPPED => true,
            $from === OrderStatus::SHIPPED && $target === OrderStatus::DELIVERED => true,
            $hasAuthoritativeDirectFulfillment
                && in_array($from, [OrderStatus::PENDING, OrderStatus::PROCESSING], true)
                && $target === OrderStatus::COMPLETED => true,
            default => false,
        };
    }

    #[Test]
    public function shipped_has_complete_enum_presentation_metadata(): void
    {
        $this->assertStringContainsString('Shipped', OrderStatus::SHIPPED->label());
        $this->assertNotSame('', OrderStatus::SHIPPED->badgeClass());
    }
}
