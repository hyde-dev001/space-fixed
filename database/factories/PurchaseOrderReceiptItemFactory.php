<?php

namespace Database\Factories;

use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderReceiptItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_order_receipt_id' => PurchaseOrderReceipt::factory(),
            'purchase_order_item_id' => PurchaseOrderItem::factory(),
            'received_quantity' => 1,
            'defective_quantity' => 0,
            'accepted_quantity' => 1,
            'inventory_effects' => [],
        ];
    }
}
