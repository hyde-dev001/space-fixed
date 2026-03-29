<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Models\InventoryColorVariant;
use App\Models\InventorySize;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierOrderMonitoringController extends Controller
{
    /**
     * List all supplier orders with filters
     */
    public function index(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $query = PurchaseOrder::with([
                'supplier',
                'purchaseRequest.inventoryItem.sizes',
                'purchaseRequest.inventoryItem.colorVariants',
                'inventoryItem.sizes',
                'inventoryItem.colorVariants',
            ])
            ->where('shop_owner_id', $shopOwnerId);
        
        // Filter by status
        if ($request->status) {
            $query->where('status', $request->status);
        }
        
        // Filter by supplier
        if ($request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }
        
        // Search by PO number
        if ($request->search) {
            $query->where('po_number', 'like', "%{$request->search}%");
        }
        
        $orders = $query->orderBy('ordered_date', 'desc')
            ->paginate($request->per_page ?? 20)
            ->withQueryString();

        $orders->setCollection(
            $orders->getCollection()->map(function (PurchaseOrder $order) {
                return $this->buildMonitoringOrderPayload($order);
            })
        );
        
        return response()->json($orders);
    }
    
    /**
     * Show supplier order details
     */
    public function show(Request $request, $id)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $order = PurchaseOrder::with([
                'supplier',
                'purchaseRequest.inventoryItem.sizes',
                'purchaseRequest.inventoryItem.colorVariants',
                'inventoryItem.sizes',
                'inventoryItem.colorVariants',
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        return response()->json($this->buildMonitoringOrderPayload($order));
    }
    
    /**
     * Update order status
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:sent,confirmed,in_transit,cancelled',
            'remarks' => 'nullable|string|max:1000'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $order = PurchaseOrder::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        $order->status = $validated['status'];
        $order->save();
        
        return response()->json([
            'message' => 'Order status updated successfully',
            'order' => $order->fresh(['supplier'])
        ]);
    }

    /**
     * Confirm goods receipt and update inventory for a purchase order.
     */
    public function receiveOrder(Request $request, $id)
    {
        $validatedData = $request->validate([
            'actual_delivery_date' => 'required|date',
            'received_quantity' => 'required|integer|min:0',
            'defective_quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string',
            'size_receipts' => 'nullable|array',
            'size_receipts.*.inventory_size_id' => 'required_with:size_receipts|integer|exists:inventory_sizes,id',
            'size_receipts.*.received_quantity' => 'required_with:size_receipts|integer|min:0',
            'size_receipts.*.defective_quantity' => 'required_with:size_receipts|integer|min:0',
        ]);

        if ($validatedData['defective_quantity'] > $validatedData['received_quantity']) {
            return response()->json(['message' => 'Defective quantity cannot exceed received quantity.'], 422);
        }

        $shopOwnerId = $request->user()->shop_owner_id;
        $order = PurchaseOrder::where('shop_owner_id', $shopOwnerId)->findOrFail($id);

        if ($order->status !== 'in_transit') {
            return response()->json([
                'message' => 'Only in-transit purchase orders can be received.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $order->loadMissing([
                'purchaseRequest.inventoryItem.sizes',
                'purchaseRequest.inventoryItem.colorVariants',
                'inventoryItem.sizes',
                'inventoryItem.colorVariants',
            ]);

            $requestedSizeRaw = trim((string) ($order->requested_size ?? $order->purchaseRequest?->requested_size ?? ''));
            $requestedColorRaw = trim((string) ($order->requested_color ?? $order->purchaseRequest?->requested_color ?? ''));
            $inventoryItem = $order->purchaseRequest?->inventoryItem ?? $order->inventoryItem;
            $applicableSizeRows = $this->resolveApplicableSizeRows($inventoryItem, $requestedColorRaw, $requestedSizeRaw);

            $hasSizeReceipts = !empty($validatedData['size_receipts']);
            $shouldProcessPerSize = $hasSizeReceipts && $this->isAllSizesRequest($requestedSizeRaw) && $applicableSizeRows->isNotEmpty();

            $totalReceived = $validatedData['received_quantity'];
            $totalDefective = $validatedData['defective_quantity'];

            if ($hasSizeReceipts) {
                $totalReceived = 0;
                $totalDefective = 0;

                foreach ($validatedData['size_receipts'] as $receipt) {
                    $receiptReceived = (int) ($receipt['received_quantity'] ?? 0);
                    $receiptDefective = (int) ($receipt['defective_quantity'] ?? 0);

                    if ($receiptDefective > $receiptReceived) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Per-size defective quantity cannot exceed per-size received quantity.'
                        ], 422);
                    }

                    $totalReceived += $receiptReceived;
                    $totalDefective += $receiptDefective;
                }
            }

            $order->received_quantity = $totalReceived;
            $order->defective_quantity = $totalDefective;
            if (isset($validatedData['notes'])) {
                $order->notes = $validatedData['notes'];
            }
            $order->save();

            // Transition to delivered and post inventory movements.
            $order->markAsDelivered((int) $request->user()->id, $validatedData['actual_delivery_date']);

            if ($shouldProcessPerSize) {
                $this->updateInventoryOnPerSizeDelivery(
                    $order,
                    $inventoryItem,
                    $applicableSizeRows,
                    $validatedData['size_receipts'],
                    (int) $request->user()->id
                );
            } else {
                $order->updateInventoryOnDelivery();
            }

            $this->createExpenseFromDeliveredPurchaseOrder($order, (int) $request->user()->id);

            DB::commit();

            $freshOrder = $order->fresh([
                'supplier',
                'purchaseRequest.inventoryItem.sizes',
                'purchaseRequest.inventoryItem.colorVariants',
                'inventoryItem.sizes',
                'inventoryItem.colorVariants',
            ]);

            return response()->json([
                'message' => 'Goods receipt confirmed, inventory updated, and PO marked as delivered.',
                'order' => $this->buildMonitoringOrderPayload($freshOrder)
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to confirm goods receipt.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildMonitoringOrderPayload(PurchaseOrder $order): array
    {
        $payload = $order->toArray();

        $requestedSizeRaw = trim((string) ($order->requested_size ?? $order->purchaseRequest?->requested_size ?? ''));
        $requestedColorRaw = trim((string) ($order->requested_color ?? $order->purchaseRequest?->requested_color ?? ''));
        $inventoryItem = $order->purchaseRequest?->inventoryItem ?? $order->inventoryItem;

        $payload['requested_size'] = $requestedSizeRaw !== '' ? $requestedSizeRaw : null;
        $payload['requested_color'] = $requestedColorRaw !== '' ? $requestedColorRaw : null;
        $payload['is_all_sizes_request'] = $this->isAllSizesRequest($requestedSizeRaw);
        $payload['inventory_category'] = $inventoryItem?->category;

        $sizeRows = $this->resolveApplicableSizeRows($inventoryItem, $requestedColorRaw, $requestedSizeRaw);
        $sizeLabels = $sizeRows
            ->map(function (InventorySize $sizeRow) {
                $system = trim((string) ($sizeRow->size_system ?? 'US'));
                $size = trim((string) ($sizeRow->size ?? ''));
                return trim($system . ' ' . $size);
            })
            ->filter()
            ->values()
            ->all();

        $payload['applicable_size_rows'] = $sizeRows->count();
        $payload['applicable_size_labels'] = $sizeLabels;
        $payload['applicable_sizes'] = $sizeRows
            ->map(function (InventorySize $sizeRow) {
                $system = trim((string) ($sizeRow->size_system ?? 'US'));
                $size = trim((string) ($sizeRow->size ?? ''));

                return [
                    'id' => $sizeRow->id,
                    'size' => $size,
                    'size_system' => $system,
                    'label' => trim($system . ' ' . $size),
                ];
            })
            ->values()
            ->all();

        return $payload;
    }

    private function resolveApplicableSizeRows($inventoryItem, string $requestedColorRaw, string $requestedSizeRaw)
    {
        if (!$inventoryItem) {
            return collect();
        }

        $category = strtolower((string) ($inventoryItem->category ?? ''));
        $isSizeBasedCategory = $category === 'shoes';

        if (!$isSizeBasedCategory) {
            return collect();
        }

        $sizeRows = $inventoryItem->sizes;
        if (!$sizeRows || $sizeRows->isEmpty()) {
            return collect();
        }

        // For specific size requests, we don't need multi-size expansion metadata.
        if (!$this->isAllSizesRequest($requestedSizeRaw)) {
            return collect();
        }

        $filteredSizeRows = $sizeRows;

        if ($requestedColorRaw !== '') {
            $targetColorVariant = $inventoryItem->colorVariants
                ? $inventoryItem->colorVariants->first(function ($variant) use ($requestedColorRaw) {
                    return strtolower((string) $variant->color_name) === strtolower($requestedColorRaw);
                })
                : null;

            if ($targetColorVariant) {
                $filteredSizeRows = $filteredSizeRows->where('inventory_color_variant_id', $targetColorVariant->id);
            }
        }

        return $filteredSizeRows->values();
    }

    private function updateInventoryOnPerSizeDelivery(
        PurchaseOrder $order,
        $inventoryItem,
        $applicableSizeRows,
        array $sizeReceipts,
        int $performedBy
    ): void {
        if (!$inventoryItem) {
            return;
        }

        $rowsById = $applicableSizeRows->keyBy('id');
        $colorNetAdditions = [];
        $totalAddedToInventory = 0;

        foreach ($sizeReceipts as $receipt) {
            $sizeId = (int) $receipt['inventory_size_id'];
            $received = (int) ($receipt['received_quantity'] ?? 0);
            $defective = (int) ($receipt['defective_quantity'] ?? 0);

            if ($defective > $received) {
                throw new \RuntimeException('Invalid per-size quantities: defective exceeds received.');
            }

            /** @var InventorySize|null $sizeRow */
            $sizeRow = $rowsById->get($sizeId);
            if (!$sizeRow) {
                throw new \RuntimeException('One or more size receipt rows are not valid for this purchase order.');
            }

            $netAccepted = max(0, $received - $defective);
            if ($netAccepted <= 0) {
                continue;
            }

            $sizeRow->quantity += $netAccepted;
            $sizeRow->save();

            $totalAddedToInventory += $netAccepted;

            if ($sizeRow->inventory_color_variant_id) {
                $colorNetAdditions[$sizeRow->inventory_color_variant_id] = ($colorNetAdditions[$sizeRow->inventory_color_variant_id] ?? 0) + $netAccepted;
            }
        }

        if (!empty($colorNetAdditions)) {
            $colorVariantIds = array_keys($colorNetAdditions);
            $variants = InventoryColorVariant::query()->whereIn('id', $colorVariantIds)->get();

            foreach ($variants as $variant) {
                $variant->quantity += (int) ($colorNetAdditions[$variant->id] ?? 0);
                $variant->save();
            }
        }

        if ($totalAddedToInventory > 0) {
            $order->loadMissing('supplier');

            $inventoryItem->incrementStock(
                $totalAddedToInventory,
                'stock_in',
                "Delivered from PO: {$order->po_number} (per-size receipt, added: {$totalAddedToInventory})",
                $performedBy
            );
        }
    }

    private function isAllSizesRequest(?string $requestedSize): bool
    {
        $normalized = strtolower(trim((string) $requestedSize));
        if ($normalized === '') {
            return true;
        }

        $normalized = preg_replace('/[\s-]+/', '_', $normalized) ?? $normalized;

        return in_array($normalized, ['all', 'all_sizes', 'all_size', 'any'], true);
    }

    /**
     * Auto-create Finance expense from delivered procurement purchase order.
     */
    private function createExpenseFromDeliveredPurchaseOrder(PurchaseOrder $purchaseOrder, int $userId): void
    {
        $amount = (float) ($purchaseOrder->total_cost ?? 0);
        if ($amount <= 0) {
            return;
        }

        $template = config('finance_expense_templates.procurement', []);

        $category = (string) ($template['category'] ?? 'Procurement');
        $status = (string) ($template['status'] ?? 'submitted');
        $referencePrefix = (string) ($template['reference_prefix'] ?? 'PROC-EXP-');
        $descriptionTemplate = (string) ($template['description_template'] ?? 'Auto-generated from Purchase Order: :reference');
        $metaSource = (string) ($template['meta_source'] ?? 'purchase_order');

        $referenceToken = (string) ($purchaseOrder->po_number ?: ('PO-' . $purchaseOrder->id));
        $reference = $referencePrefix . $referenceToken;

        $description = strtr($descriptionTemplate, [
            ':reference' => $referenceToken,
            ':po_number' => (string) ($purchaseOrder->po_number ?? $referenceToken),
        ]);

        $expenseDate = $purchaseOrder->actual_delivery_date
            ? \Illuminate\Support\Carbon::parse($purchaseOrder->actual_delivery_date)->toDateString()
            : now()->toDateString();

        $purchaseOrder->loadMissing('supplier');

        $expensePayload = [
            'date' => $expenseDate,
            'category' => $category,
            'vendor' => $purchaseOrder->supplier?->name,
            'description' => $description,
            'amount' => $amount,
            'tax_amount' => 0,
            'status' => $status,
            'shop_id' => $purchaseOrder->shop_owner_id,
            'meta' => [
                'source' => $metaSource,
                'purchase_order_id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'created_by' => $userId,
            ],
        ];

        if ($status === 'approved') {
            $expensePayload['approved_by'] = $userId;
            $expensePayload['approved_at'] = now();
            $expensePayload['approval_notes'] = 'Auto-approved from delivered purchase order.';
        }

        Expense::firstOrCreate(
            ['reference' => $reference],
            $expensePayload
        );
    }
    
    /**
     * Get order monitoring metrics
     */
    public function getMetrics(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;

        $baseQuery = PurchaseOrder::where('shop_owner_id', $shopOwnerId)
            ->whereNotIn('status', ['delivered', 'completed', 'cancelled']);

        $today = now()->toDateString();
        $plusThreeDays = now()->addDays(3)->toDateString();

        $activeOrders = (clone $baseQuery)->count();
        $dueToday = (clone $baseQuery)
            ->whereDate('expected_delivery_date', $today)
            ->count();
        $overdue = (clone $baseQuery)
            ->whereDate('expected_delivery_date', '<', $today)
            ->count();
        $arrivingSoon = (clone $baseQuery)
            ->whereDate('expected_delivery_date', '>', $today)
            ->whereDate('expected_delivery_date', '<=', $plusThreeDays)
            ->count();

        return response()->json([
            'active_orders' => $activeOrders,
            'due_today' => $dueToday,
            'overdue' => $overdue,
            'arriving_soon' => $arrivingSoon
        ]);
    }
    
    /**
     * Generate stock movements from supplier order
     */
    protected function generateStockMovements($order, $userId)
    {
        foreach ($order->items as $item) {
            if ($item->inventory_item_id && $item->quantity > 0) {
                $inventoryItem = $item->inventoryItem;
                
                $quantityBefore = $inventoryItem->available_quantity;
                $quantityAfter = $quantityBefore + $item->quantity;
                
                StockMovement::create([
                    'inventory_item_id' => $inventoryItem->id,
                    'movement_type' => 'stock_in',
                    'quantity_change' => $item->quantity,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'reference_type' => 'supplier_order',
                    'reference_id' => $order->id,
                    'notes' => "Received from PO: {$order->po_number}",
                    'performed_by' => $userId,
                    'performed_at' => now()
                ]);
                
                $inventoryItem->available_quantity = $quantityAfter;
                $inventoryItem->save();
            }
        }
    }
}
