<?php

namespace App\Services;

use App\Models\CodCollection;
use App\Models\Order;

final class CodCollectionService
{
    public function ensureForOrder(Order $order): CodCollection
    {
        return CodCollection::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'shop_owner_id' => $order->shop_owner_id,
                'expected_amount' => $this->expectedAmount($order),
                'status' => CodCollection::STATUS_PENDING,
            ],
        );
    }

    public function expectedAmount(Order $order): string
    {
        return number_format(
            round(
                (float) $order->total_amount
                + (float) ($order->shipping_fee ?? 0)
                + (float) ($order->vat_amount ?? 0),
                2,
            ),
            2,
            '.',
            '',
        );
    }
}
