<?php

require __DIR__.'/failed_delivery_fixture.php';
use App\Models\CodCollection;
use App\Models\Employee;
use App\Models\Finance\Invoice;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\OrderRefundItem;
use App\Models\PosPaymentLine;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ProcurementSettings;
use App\Models\Product;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Spatie\Permission\Models\Permission;

function financialActor(ShopOwner $shop, string $name, array $permissions): array
{
    $user = User::factory()->create(['shop_owner_id' => $shop->id, 'name' => 'Synthetic '.$name, 'email' => $name.'-'.$shop->id.'@recovery.example.test', 'password' => 'Local-Recovery-Test-72!', 'role' => 'STAFF', 'status' => 'active', 'force_password_change' => false]);
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'user'));
    }
    $user->forceFill(['employee_totp_secret' => 'JBSWY3DPEHPK3PXP', 'employee_totp_enabled_at' => now()])->save();
    Employee::factory()->active()->create(['shop_owner_id' => $shop->id, 'email' => $user->email]);

    return ['id' => $user->id, 'email' => $user->email];
}
if (($argv[1] ?? '') === '--verify') {
    $data = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $lines = collect($data['inspection'])->map(function ($line) {
        $refund = OrderRefund::findOrFail($line['refund_id']);
        $item = OrderRefundItem::where('order_refund_id', $refund->id)->firstOrFail();
        $stock = Product::findOrFail($line['product_id'])->stock_quantity;
        if ($refund->return_status !== 'received' || $item->inspection_disposition !== $line['disposition'] || (int) $stock !== ($line['disposition'] === 'resellable' ? 6 : 5)) {
            throw new RuntimeException('Inspection or stock mismatch.');
        }

        return ['disposition' => $item->inspection_disposition, 'stock' => $stock, 'inventory_action' => $item->inventory_action];
    });
    $refund = PosRefund::findOrFail($data['payout_id']);
    if ($refund->status !== 'succeeded' || $refund->execution_mode !== 'manual' || ! $refund->execution_proof_urls || $refund->paymongo_refund_id) {
        throw new RuntimeException('Manual synthetic payout mismatch.');
    }
    if ((int) $refund->executed_by !== $data['actors']['finance']['id'] || (string) $refund->approved_amount !== '500.00' || $refund->execution_channel !== 'manual_cash') {
        throw new RuntimeException('Payout actor/amount/channel mismatch.');
    }
    $collection = CodCollection::findOrFail($data['cod_id']);
    if ($collection->rider_user_id !== $data['actors']['a']['id'] || $collection->status !== CodCollection::STATUS_SETTLED) {
        throw new RuntimeException('COD responsibility or settlement mismatch.');
    }
    $payment = App\Models\Finance\InvoicePayment::where('invoice_id', $collection->order->invoice_id)->sole();
    if ($payment->source !== 'cod_remittance' || (string) $payment->amount !== '100.00' || (int) $payment->recorded_by_user_id !== $data['actors']['finance']['id']) {
        throw new RuntimeException('COD payment history mismatch.');
    }
    echo json_encode(['inspections' => $lines, 'manual_payout' => 'succeeded_with_synthetic_evidence', 'cod' => 'settled_by_finance_original_rider_responsible'], JSON_THROW_ON_ERROR);
    exit;
}
$data = batchRecoveryFixture('refund_return', 'mid');
$shop = ShopOwner::findOrFail($data['shop_id']);
foreach (['retail_operations', 'finance'] as $module) {
    ShopOwnerModule::updateOrCreate(['shop_owner_id' => $shop->id, 'module_key' => $module], ['enabled' => true]);
}
$data['actors']['staff'] = financialActor($shop, 'staff', ['access-staff-job-orders', 'access-notification-center', 'access-profile']);
$data['actors']['finance'] = financialActor($shop, 'finance', ['access-refund-approval', 'access-cod-remittances', 'access-finance-dashboard', 'access-notification-center', 'access-profile']);
$data['inspection'] = [];
foreach ($data['affected_ids'] as $index => $legId) {
    $refund = OrderRefund::findOrFail(ShipmentLeg::findOrFail($legId)->shipment->source_id);
    $refund->update(['status' => 'pending_approval', 'flow_type' => 'request_approval', 'reason_code' => 'quality_issue']);
    $product = Product::create(['shop_owner_id' => $shop->id, 'name' => 'Synthetic inspection shoe '.$index, 'slug' => 'inspection-'.$shop->id.'-'.$index, 'price' => 1000, 'stock_quantity' => 5, 'is_active' => true]);
    $item = OrderItem::create(['order_id' => $refund->order_id, 'product_id' => $product->id, 'product_name' => $product->name, 'product_slug' => $product->slug, 'price' => 1000, 'quantity' => 1, 'subtotal' => 1000]);
    OrderRefundItem::create(['order_refund_id' => $refund->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'requested_qty' => 1, 'approved_qty' => 1, 'unit_price_snapshot' => 1000, 'line_amount' => 1000]);
    $data['inspection'][] = ['refund_id' => $refund->id, 'order_id' => $refund->order_id, 'product_id' => $product->id, 'disposition' => $index === 0 ? 'resellable' : 'damaged'];
}
$customer = User::factory()->create(['email' => 'customer-'.\Illuminate\Support\Str::uuid().'@recovery.example.test']);
$repair = RepairRequest::factory()->create(['shop_owner_id' => $shop->id, 'user_id' => $customer->id, 'status' => 'picked_up', 'total' => 500, 'total_paid_amount' => 500, 'payment_status' => 'completed']);
$source = PosTransaction::create(['transaction_no' => 'POS-LOCAL-'.$shop->id, 'shop_owner_id' => $shop->id, 'module_type' => 'repair', 'module_reference_id' => $repair->id, 'customer_type' => 'registered', 'customer_id' => $customer->id, 'due_type' => 'full', 'subtotal' => 500, 'total_amount' => 500, 'paid_amount' => 500, 'status' => 'paid', 'paid_at' => now()]);
PosPaymentLine::create(['pos_transaction_id' => $source->id, 'tender_type' => 'cash', 'amount' => 500, 'status' => 'paid', 'paid_at' => now()]);
ProcurementSettings::updateOrCreate(['shop_owner_id' => $shop->id], ['settings_json' => ['approval_pages' => ['refund_approval' => ['enabled' => false, 'limit' => null]]]]);
$refund = app(App\Services\RepairPosRefundService::class)->requestRefund($source, ['workflow_source' => 'online_myrepair', 'request_type' => 'full', 'requested_amount' => 500, 'reason_code' => 'service_defect'], $customer->id);
$refund->update(['repairer_status' => 'approved', 'repairer_assessment_note' => 'Synthetic assessed defect; fixture precondition.', 'repairer_reviewed_at' => now(), 'preferred_return_channel' => 'manual_cash', 'customer_payout_consent' => true]);
if ($refund->requires_owner_approval) {
    throw new RuntimeException('Fixture owner approval policy did not resolve as configured.');
}
$refund->legs()->create(['leg_type' => 'pos_manual', 'requested_amount' => 500, 'status' => 'requested', 'source_transaction_id' => $source->id]);
$data['payout_id'] = $refund->id;
$order = Order::factory()->create(['shop_owner_id' => $shop->id, 'total_amount' => 100, 'shipping_fee' => 0, 'vat_amount' => 0, 'payment_method' => 'cod', 'payment_status' => 'pending', 'paid_at' => null]);
$invoice = Invoice::create(['shop_id' => $shop->id, 'reference' => 'INV-LOCAL-'.$order->id, 'customer_name' => 'Synthetic COD customer', 'date' => now()->toDateString(), 'total' => 100, 'tax_amount' => 0, 'status' => 'sent', 'payment_method' => 'cod', 'job_order_id' => $order->id]);
$order->update(['invoice_id' => $invoice->id, 'invoice_generated' => true]);
// Cash already collected is a fixture precondition; the journey tests real remittance and confirmation.
$cod = CodCollection::create(['shop_owner_id' => $shop->id, 'order_id' => $order->id, 'rider_profile_id' => $data['actors']['a']['rider_id'], 'rider_user_id' => $data['actors']['a']['id'], 'expected_amount' => 100, 'collected_amount' => 100, 'status' => CodCollection::STATUS_CASH_COLLECTED, 'collection_reference' => 'COD-LOCAL-'.$order->id, 'collection_idempotency_key' => 'cod-local-'.$order->id, 'collected_at' => now(), 'collected_by_user_id' => $data['actors']['a']['id']]);
$data['cod_id'] = $cod->id;
echo json_encode($data,JSON_THROW_ON_ERROR);
