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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SupplierAdjustmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SupplierAdjustmentService $adjustmentService) {}

    public function index(Request $request)
    {
        $shopId = (int) $request->user()->shop_owner_id;
        $filters = $request->validate([
            'purchase_order_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in([
                SupplierAdjustment::STATUS_REPORTED,
                SupplierAdjustment::STATUS_UNDER_REVIEW,
                SupplierAdjustment::STATUS_AWAITING_SUPPLIER,
                SupplierAdjustment::STATUS_RESOLUTION_IN_PROGRESS,
                SupplierAdjustment::STATUS_AWAITING_VERIFICATION,
                SupplierAdjustment::STATUS_PARTIALLY_REFUNDED,
                SupplierAdjustment::STATUS_RESOLVED,
            ])],
            'current_owner' => ['nullable', Rule::in(['procurement', 'inventory', 'finance', 'none'])],
        ]);
        $query = SupplierAdjustment::query()
            ->where('shop_owner_id', $shopId)
            ->with(['receiptItem.receipt.purchaseOrder.supplier', 'receiptItem.purchaseOrderItem']);

        $query->when($filters['purchase_order_id'] ?? null, fn ($query, $purchaseOrderId) => $query
            ->whereHas('receiptItem.receipt', fn ($receiptQuery) => $receiptQuery->where('purchase_order_id', $purchaseOrderId)));
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $this->filterCurrentOwner($query, $filters['current_owner'] ?? null);

        $adjustments = $query
            ->latest('id')
            ->get()
            ->map(fn (SupplierAdjustment $adjustment): array => $this->adjustmentService->present($adjustment))
            ->values()
            ->all();

        return response()->json(['data' => $adjustments]);
    }

    private function filterCurrentOwner(Builder $query, ?string $owner): void
    {
        if ($owner === null) {
            return;
        }
        if ($owner === 'none') {
            $query->where('status', SupplierAdjustment::STATUS_RESOLVED);
            return;
        }

        $query->where('status', '<>', SupplierAdjustment::STATUS_RESOLVED);
        if ($owner === 'inventory') {
            $query->where('issue_stage', SupplierAdjustment::ISSUE_STAGE_RECEIVING_DEFECT)
                ->where(fn ($query) => $query
                    ->where('return_status', SupplierAdjustment::RETURN_REQUIRED)
                    ->orWhere(fn ($query) => $query
                        ->where('replacement_status', SupplierAdjustment::REPLACEMENT_IN_TRANSIT)
                        ->where(fn ($query) => $query->whereNull('return_status')->orWhere('return_status', '<>', SupplierAdjustment::RETURN_REQUIRED))));
            return;
        }
        if ($owner === 'finance') {
            $query->where('issue_stage', SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT)
                ->whereIn('status', [SupplierAdjustment::STATUS_AWAITING_VERIFICATION, SupplierAdjustment::STATUS_PARTIALLY_REFUNDED]);
            return;
        }

        $query->where(fn ($query) => $query
            ->where(fn ($query) => $query
                ->where('issue_stage', SupplierAdjustment::ISSUE_STAGE_RECEIVING_DEFECT)
                ->where(fn ($query) => $query
                    ->where('return_status', SupplierAdjustment::RETURN_RELEASED)
                    ->orWhere(fn ($query) => $query
                        ->where(fn ($query) => $query->whereNull('return_status')->orWhere('return_status', '<>', SupplierAdjustment::RETURN_REQUIRED))
                        ->where(fn ($query) => $query->whereNull('replacement_status')->orWhere('replacement_status', '<>', SupplierAdjustment::REPLACEMENT_IN_TRANSIT)))))
            ->orWhere(fn ($query) => $query
                ->where('issue_stage', SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT)
                ->whereNotIn('status', [SupplierAdjustment::STATUS_AWAITING_VERIFICATION, SupplierAdjustment::STATUS_PARTIALLY_REFUNDED])));
    }

    public function show(Request $request, int $adjustmentId)
    {
        $adjustment = SupplierAdjustment::query()
            ->where('shop_owner_id', (int) $request->user()->shop_owner_id)
            ->findOrFail($adjustmentId);

        return response()->json(['data' => $this->adjustmentService->present($adjustment)]);
    }

    public function resolution(Request $request, int $adjustmentId)
    {
        abort_unless($request->user()->can('procurement.manage_suppliers'), 403);
        $adjustment = $this->shopAdjustment($request, $adjustmentId);
        $data = $request->validate([
            'resolution' => ['required', 'in:replacement,refund,short_fulfillment'],
            'procurement_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->adjustmentService->chooseResolution($adjustment, $request->user(), $data),
        ]);
    }

    public function replacementAction(Request $request, int $adjustmentId, string $action)
    {
        abort_unless($request->user()->can('procurement.manage_suppliers'), 403);
        $adjustment = $this->shopAdjustment($request, $adjustmentId);
        $data = $request->validate([
            'decline_reason' => ['nullable', 'string', 'max:2000'],
            'supplier_reference' => ['nullable', 'string', 'max:160'],
            'procurement_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->adjustmentService->replacementAction($adjustment, $request->user(), $action, $data),
        ]);
    }

    public function refundDeclined(Request $request, int $adjustmentId)
    {
        abort_unless($request->user()->can('procurement.manage_suppliers'), 403);
        $adjustment = $this->shopAdjustment($request, $adjustmentId);
        $data = $request->validate([
            'decline_reason' => ['required', 'string', 'max:2000'],
            'supplier_reference' => ['nullable', 'string', 'max:160'],
            'procurement_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->adjustmentService->refundDeclined($adjustment, $request->user(), $data),
        ]);
    }

    public function closeShortFulfillment(Request $request, int $adjustmentId)
    {
        abort_unless($request->user()->can('procurement.manage_suppliers'), 403);
        $adjustment = $this->shopAdjustment($request, $adjustmentId);
        $data = $request->validate([
            'procurement_notes' => ['nullable', 'string', 'max:2000'],
            'supplier_reference' => ['nullable', 'string', 'max:160'],
        ]);

        return response()->json([
            'data' => $this->adjustmentService->closeShortFulfillment($adjustment, $request->user(), $data),
        ]);
    }

    public function returnAction(Request $request, int $adjustmentId)
    {
        $adjustment = $this->shopAdjustment($request, $adjustmentId);
        $status = (string) $request->input('status');
        if ($status === SupplierAdjustment::RETURN_RELEASED) {
            $this->assertInventoryActor($request);
        } else {
            abort_unless($request->user()->can('procurement.manage_suppliers'), 403);
        }
        $data = $request->validate([
            'status' => ['required', 'in:required,released,received_by_supplier,waived'],
            'return_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->adjustmentService->setReturnStatus($adjustment, $request->user(), $data['status'], $data),
        ]);
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

        $response = response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline',
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

    private function shopAdjustment(Request $request, int $adjustmentId): SupplierAdjustment
    {
        return SupplierAdjustment::query()
            ->where('shop_owner_id', (int) $request->user()->shop_owner_id)
            ->findOrFail($adjustmentId);
    }

    private function assertInventoryActor(Request $request): void
    {
        abort_unless(
            $request->user()->can('procurement.receive_purchase_orders')
                && $request->user()->can('view-inventory'),
            403,
        );
    }
}
