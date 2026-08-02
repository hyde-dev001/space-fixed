<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseOrderReceiptRequest;
use App\Http\Requests\VoidPurchaseOrderReceiptRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Services\PurchaseOrderReceiptService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class PurchaseOrderReceiptController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private PurchaseOrderReceiptService $receiptService) {}

    public function index(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::where('shop_owner_id', $request->user()->shop_owner_id)->findOrFail($id);
        $this->authorize('view', $purchaseOrder);

        return response()->json(['data' => $purchaseOrder->receipts()->with('items')->latest('received_at')->get()]);
    }

    public function store(StorePurchaseOrderReceiptRequest $request, $id)
    {
        $purchaseOrder = PurchaseOrder::where('shop_owner_id', $request->user()->shop_owner_id)->findOrFail($id);
        $this->authorize('receive', $purchaseOrder);

        $receipt = $this->receiptService->post($purchaseOrder, $request->user(), $request->validated());

        return response()->json([
            'message' => $receipt->wasRecentlyCreated ? 'Receipt posted.' : 'Receipt already posted.',
            'data' => $receipt->load(['items.purchaseOrderItem', 'expense']),
        ], $receipt->wasRecentlyCreated ? 201 : 200);
    }

    public function void(VoidPurchaseOrderReceiptRequest $request, $id, $receiptId)
    {
        $purchaseOrder = PurchaseOrder::where('shop_owner_id', $request->user()->shop_owner_id)->findOrFail($id);
        $this->authorize('voidReceipt', $purchaseOrder);
        $receipt = PurchaseOrderReceipt::where('purchase_order_id', $purchaseOrder->id)->findOrFail($receiptId);

        $receipt = $this->receiptService->void($purchaseOrder, $receipt, $request->user(), $request->validated('reason'));

        return response()->json([
            'message' => 'Receipt voided.',
            'data' => $receipt->load(['items.purchaseOrderItem', 'expense']),
        ]);
    }
}
