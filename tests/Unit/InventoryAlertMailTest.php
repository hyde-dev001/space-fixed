<?php

namespace Tests\Unit;

use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\SupplierOrderOverdueNotification;
use Tests\TestCase;

class InventoryAlertMailTest extends TestCase
{
    public function test_low_stock_mail_preserves_item_and_variant_details(): void
    {
        $item = (new InventoryItem)->forceFill([
            'id' => 17, 'name' => 'Repair Glue', 'sku' => 'GLUE-17', 'reorder_quantity' => 50,
        ]);
        foreach ([null, ['color_name' => 'Black', 'size' => '8', 'size_system' => 'US', 'reorder_quantity' => 20]] as $target) {
            $notification = new LowStockNotification($item, 1, 10, $target);
            $mail = $notification->toMail(new User);
            $name = $target ? 'Repair Glue - Black - US 8' : 'Repair Glue';
            $this->assertSame('Low Stock Alert: '.$name, $mail->subject);
            $this->assertContains('Current Quantity: **1**', $mail->introLines);
            $this->assertContains('Recommended Reorder Quantity: **'.($target ? 20 : 50).'**', $mail->introLines);
            $this->assertSame(url('/erp/inventory/inventory-dashboard?inventory_item=17'), $mail->actionUrl);
            $this->assertSame(['mail', 'database'], $notification->via(new User));
        }
    }

    public function test_overdue_supplier_mail_preserves_order_details(): void
    {
        $order = (new SupplierOrder)->forceFill([
            'id' => 23, 'po_number' => 'PO-23', 'status' => 'pending', 'expected_delivery_date' => '2026-10-01',
        ])->setRelation('supplier', new Supplier(['name' => 'Test Supplier']));
        $notification = new SupplierOrderOverdueNotification($order, 3);
        $mail = $notification->toMail(new User);
        $this->assertSame('Overdue Supplier Order: PO-23', $mail->subject);
        $this->assertContains('Supplier: **Test Supplier**', $mail->introLines);
        $this->assertContains('Supplier order **PO-23** is overdue by **3 days**.', $mail->introLines);
        $this->assertSame(url('/erp/inventory/supplier-order-monitoring?supplier=PO-23'), $mail->actionUrl);
        $this->assertSame(['mail', 'database'], $notification->via(new User));
    }
}
