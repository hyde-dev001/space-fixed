<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostPaymentIssueRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\SupplierAdjustment;
use App\Services\SupplierAdjustmentService;
use App\Support\Finance\FinanceDomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

final class SupplierAdjustmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SupplierAdjustmentService $adjustmentService) {}

    public function index(Request $request)
    {
        $shopId = (int) $request->user()->shop_owner_id;
        $adjustments = SupplierAdjustment::query()
            ->where('shop_owner_id', $shopId)
            ->with(['receiptItem.receipt.purchaseOrder', 'receiptItem.purchaseOrderItem'])
            ->latest('id')
            ->get()
            ->map(fn (SupplierAdjustment $adjustment): array => $this->adjustmentService->present($adjustment))
            ->values()
            ->all();

        return response()->json(['data' => $adjustments]);
    }

    public function show(Request $request, int $adjustmentId)
    {
        $adjustment = SupplierAdjustment::query()
            ->where('shop_owner_id', (int) $request->user()->shop_owner_id)
            ->findOrFail($adjustmentId);

        return response()->json(['data' => $this->adjustmentService->present($adjustment)]);
    }

    public function postPaymentIssue(
        StorePostPaymentIssueRequest $request,
        int $id,
        int $receiptId,
        int $receiptItemId,
    ) {
        $purchaseOrder = PurchaseOrder::query()
            ->where('shop_owner_id', (int) $request->user()->shop_owner_id)
            ->findOrFail($id);
        $this->authorize('reportSupplierIssue', $purchaseOrder);

        $receipt = PurchaseOrderReceipt::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->whereKey($receiptId)
            ->firstOrFail();
        $receiptItem = PurchaseOrderReceiptItem::query()
            ->where('purchase_order_receipt_id', $receipt->id)
            ->whereKey($receiptItemId)
            ->firstOrFail();

        $result = $this->adjustmentService->reportPostPaymentIssue(
            $receiptItem,
            $request->user(),
            $request->validated() + ['defect_evidence' => $request->file('defect_evidence', [])],
        );

        return response()->json([
            'message' => $result['replayed'] ? 'Supplier issue already reported.' : 'Supplier issue reported.',
            'data' => $this->adjustmentService->present($result['adjustment']),
        ], $result['replayed'] ? 200 : 201);
    }

    public function evidence(Request $request, int $adjustmentId, int $mediaId)
    {
        $adjustment = SupplierAdjustment::query()
            ->where('shop_owner_id', (int) $request->user()->shop_owner_id)
            ->findOrFail($adjustmentId);
        $media = $adjustment->getMedia('defect_evidence')->firstWhere('id', $mediaId);
        abort_unless($media, 404);

        $response = response()->download($media->getPath(), $media->file_name, [
            'Content-Type' => $media->mime_type,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function supplierRefundProof(Request $request, int $adjustmentId)
    {
        abort_unless($request->user()->can('procurement.manage_suppliers'), 403);

        $data = $request->validate([
            'expected_refund_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'supplier_reported_refund_amount' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'supplier_reported_refund_reference' => ['nullable', 'string', 'max:160'],
            'supplier_reported_refund_date' => ['nullable', 'date'],
            'procurement_notes' => ['nullable', 'string', 'max:2000'],
            'supplier_refund_proof' => ['nullable', 'file', 'max:10240'],
        ]);
        $adjustment = SupplierAdjustment::query()
            ->where('shop_owner_id', (int) $request->user()->shop_owner_id)
            ->findOrFail($adjustmentId);

        try {
            $updated = $this->adjustmentService->recordSupplierRefundProof(
                $adjustment,
                $request->user(),
                $data,
                $request->file('supplier_refund_proof'),
            );

            return response()->json(['data' => $this->adjustmentService->present($updated)]);
        } catch (FinanceDomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->httpStatus);
        }
    }

    public function refundProof(Request $request, int $adjustmentId, int $mediaId)
    {
        $actor = $request->user('user');
        abort_unless($actor, 401);

        $adjustment = SupplierAdjustment::query()
            ->where('shop_owner_id', (int) $actor->shop_owner_id)
            ->findOrFail($adjustmentId);
        $media = $adjustment->getMedia('supplier_refund_proof')->firstWhere('id', $mediaId)
            ?? $adjustment->getMedia('finance_confirmation_proof')->firstWhere('id', $mediaId);
        abort_unless($media, 404);

        $response = response()->download($media->getPath(), $media->file_name, [
            'Content-Type' => $media->mime_type,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
