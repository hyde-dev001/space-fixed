<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RepairRequest;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ExpireStalePaymentSessions extends Command
{
    protected $signature = 'payments:expire-stale
                            {--dry-run : Report only, without applying changes}
                            {--classify : Print legacy unpaid classification summary before processing}
                            {--limit=0 : Max number of orders and repairs to process in this run (0 = no limit)}';

    protected $description = 'Expire unpaid payment sessions older than the configured window and release reservations';

    private const EXPIRY_WINDOW_HOURS = 1;

    private function normalizeSizeSystem(?string $rawSystem): string
    {
        $normalized = strtoupper(trim((string) $rawSystem));
        return in_array($normalized, ['US', 'UK', 'EU', 'AU', 'CN'], true) ? $normalized : 'US';
    }

    private function parseSizeComponents(?string $rawSize): array
    {
        $normalizedRaw = trim((string) $rawSize);
        if ($normalizedRaw === '') {
            return ['system' => 'US', 'value' => '', 'explicit_system' => false];
        }

        if (preg_match('/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i', $normalizedRaw, $matches)) {
            return [
                'system' => $this->normalizeSizeSystem($matches[1] ?? null),
                'value' => trim((string) ($matches[2] ?? '')),
                'explicit_system' => true,
            ];
        }

        return ['system' => 'US', 'value' => $normalizedRaw, 'explicit_system' => false];
    }

    private function resolveInventorySizeRowForRestock(int $inventoryItemId, ?int $inventoryColorVariantId, ?string $rawSize): ?InventorySize
    {
        $parsed = $this->parseSizeComponents($rawSize);
        $sizeValue = trim((string) ($parsed['value'] ?? ''));
        $sizeSystem = (string) ($parsed['system'] ?? 'US');
        $hasExplicitSystem = (bool) ($parsed['explicit_system'] ?? false);

        if ($sizeValue === '') {
            return null;
        }

        $query = InventorySize::where('inventory_item_id', $inventoryItemId)
            ->where('size', $sizeValue);

        if ($inventoryColorVariantId) {
            $query->where('inventory_color_variant_id', $inventoryColorVariantId);
        } else {
            $query->whereNull('inventory_color_variant_id');
        }

        if ($hasExplicitSystem) {
            $preferred = (clone $query)
                ->where('size_system', $sizeSystem)
                ->lockForUpdate()
                ->first();

            if ($preferred) {
                return $preferred;
            }
        }

        return $query->orderByRaw("CASE WHEN size_system = 'US' THEN 0 ELSE 1 END")
            ->lockForUpdate()
            ->first();
    }

    private function restoreReservationForExpiredItem(object $item, int $orderId, ?string $orderNumber): void
    {
        if (empty($item->product_id) || empty($item->quantity)) {
            return;
        }

        $qty = (int) $item->quantity;
        if ($qty <= 0) {
            return;
        }

        $product = Product::query()->lockForUpdate()->find((int) $item->product_id);
        if (!$product) {
            return;
        }

        $inventoryItem = InventoryItem::where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (!$inventoryItem) {
            $product->increment('stock_quantity', $qty);

            if (!empty($item->size) && !empty($item->color)) {
                $variant = ProductVariant::query()
                    ->where('product_id', $item->product_id)
                    ->whereRaw('LOWER(color) = ?', [strtolower((string) $item->color)])
                    ->where('size', $item->size)
                    ->lockForUpdate()
                    ->first();

                if ($variant) {
                    $variant->increment('quantity', $qty);
                } else {
                    Log::warning('Variant not found while releasing expired order reservation', [
                        'order_id' => $orderId,
                        'order_number' => $orderNumber,
                        'product_id' => $item->product_id,
                        'size' => $item->size,
                        'color' => $item->color,
                    ]);
                }
            }

            return;
        }

        $quantityBefore = (int) $inventoryItem->available_quantity;
        $didSpecificRestock = false;

        if (!empty($item->size) && !empty($item->color)) {
            $normalizedColor = strtolower(trim((string) $item->color));

            $inventoryColorVariant = InventoryColorVariant::where('inventory_item_id', $inventoryItem->id)
                ->whereRaw('LOWER(color_name) = ?', [$normalizedColor])
                ->lockForUpdate()
                ->first();

            $sizeRow = $this->resolveInventorySizeRowForRestock(
                (int) $inventoryItem->id,
                $inventoryColorVariant?->id,
                (string) $item->size
            );

            if ($sizeRow) {
                $sizeRow->increment('quantity', $qty);
                $didSpecificRestock = true;
            }

            if ($inventoryColorVariant) {
                if ($sizeRow) {
                    $recomputedColorQty = (int) InventorySize::where('inventory_item_id', $inventoryItem->id)
                        ->where('inventory_color_variant_id', $inventoryColorVariant->id)
                        ->sum('quantity');

                    $inventoryColorVariant->quantity = $recomputedColorQty;
                    $inventoryColorVariant->save();
                } else {
                    $inventoryColorVariant->increment('quantity', $qty);
                }
                $didSpecificRestock = true;
            }
        }

        $newTotalQty = (int) InventoryColorVariant::where('inventory_item_id', $inventoryItem->id)
            ->sum('quantity');

        if ($newTotalQty === 0) {
            $newTotalQty = (int) InventorySize::where('inventory_item_id', $inventoryItem->id)
                ->whereNull('inventory_color_variant_id')
                ->sum('quantity');
        }

        if (!$didSpecificRestock) {
            $newTotalQty = $quantityBefore + $qty;
        }

        $inventoryItem->available_quantity = $newTotalQty;
        $inventoryItem->save();

        StockMovement::create([
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'stock_in',
            'quantity_change' => $qty,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $newTotalQty,
            'reference_type' => 'order',
            'reference_id' => $orderId,
            'notes' => 'Expired payment reservation release',
            'performed_at' => now(),
        ]);

        $product->stock_quantity = $newTotalQty;
        $product->save();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $classify = (bool) $this->option('classify');
        $limit = max(0, (int) $this->option('limit'));
        $now = now();
        $threshold = $now->copy()->subHours(self::EXPIRY_WINDOW_HOURS);

        $hasOrderPaymentExpiredAt = Schema::hasColumn('orders', 'payment_expired_at');
        $hasOrderPaymentExpiresAt = Schema::hasColumn('orders', 'payment_expires_at');
        $hasOrderPaymentLinkCreatedAt = Schema::hasColumn('orders', 'payment_link_created_at');
        $hasOrderPaymentFailedAt = Schema::hasColumn('orders', 'payment_failed_at');
        $hasOrderPaymentFailureReason = Schema::hasColumn('orders', 'payment_failure_reason');
        $hasOrderPaymentReleasedAt = Schema::hasColumn('orders', 'payment_released_at');

        $hasRepairPaymentExpiredAt = Schema::hasColumn('repair_requests', 'payment_expired_at');
        $hasRepairPaymentExpiresAt = Schema::hasColumn('repair_requests', 'payment_expires_at');
        $hasRepairPaymentLinkCreatedAt = Schema::hasColumn('repair_requests', 'payment_link_created_at');
        $hasRepairPaymentFailedAt = Schema::hasColumn('repair_requests', 'payment_failed_at');
        $hasRepairPaymentFailureReason = Schema::hasColumn('repair_requests', 'payment_failure_reason');

        $ordersQuery = Order::query()
            ->whereIn('payment_status', ['pending', 'failed', 'expired'])
            ->where('status', 'pending')
            ->with('items');

        if ($hasOrderPaymentExpiredAt) {
            $ordersQuery->whereNull('payment_expired_at');
        }

        $ordersQuery->where(function ($query) use ($now, $threshold, $hasOrderPaymentExpiresAt, $hasOrderPaymentLinkCreatedAt) {
            $hasExpiryCondition = false;

            if ($hasOrderPaymentExpiresAt) {
                $query->where(function ($subQuery) use ($now) {
                    $subQuery->whereNotNull('payment_expires_at')
                        ->where('payment_expires_at', '<=', $now);
                });
                $hasExpiryCondition = true;
            }

            if ($hasOrderPaymentLinkCreatedAt) {
                if ($hasExpiryCondition) {
                    $query->orWhere(function ($subQuery) use ($threshold, $hasOrderPaymentExpiresAt) {
                        if ($hasOrderPaymentExpiresAt) {
                            $subQuery->whereNull('payment_expires_at');
                        }
                        $subQuery->whereNotNull('payment_link_created_at')
                            ->where('payment_link_created_at', '<=', $threshold);
                    });
                } else {
                    $query->whereNotNull('payment_link_created_at')
                        ->where('payment_link_created_at', '<=', $threshold);
                }
                $hasExpiryCondition = true;
            }

            if (!$hasExpiryCondition) {
                $query->where('created_at', '<=', $threshold);
            }
        });

        if ($limit > 0) {
            $ordersQuery->limit($limit);
        }

        $orders = $ordersQuery->get();

        $repairsQuery = RepairRequest::query()
            ->whereIn('payment_status', ['pending', 'failed', 'expired', 'paid'])
            ->whereNotIn('status', ['completed', 'ready-for-pickup', 'picked_up', 'cancelled']);

        if ($hasRepairPaymentExpiredAt) {
            $repairsQuery->whereNull('payment_expired_at');
        }

        $repairsQuery->where(function ($query) use ($now, $threshold, $hasRepairPaymentExpiresAt, $hasRepairPaymentLinkCreatedAt) {
            $hasExpiryCondition = false;

            if ($hasRepairPaymentExpiresAt) {
                $query->where(function ($subQuery) use ($now) {
                    $subQuery->whereNotNull('payment_expires_at')
                        ->where('payment_expires_at', '<=', $now);
                });
                $hasExpiryCondition = true;
            }

            if ($hasRepairPaymentLinkCreatedAt) {
                if ($hasExpiryCondition) {
                    $query->orWhere(function ($subQuery) use ($threshold, $hasRepairPaymentExpiresAt) {
                        if ($hasRepairPaymentExpiresAt) {
                            $subQuery->whereNull('payment_expires_at');
                        }
                        $subQuery->whereNotNull('payment_link_created_at')
                            ->where('payment_link_created_at', '<=', $threshold);
                    });
                } else {
                    $query->whereNotNull('payment_link_created_at')
                        ->where('payment_link_created_at', '<=', $threshold);
                }
                $hasExpiryCondition = true;
            }

            if (!$hasExpiryCondition) {
                $query->where('created_at', '<=', $threshold);
            }
        });

        if ($limit > 0) {
            $repairsQuery->limit($limit);
        }

        $repairs = $repairsQuery->get();

        if ($classify) {
            $this->line('Legacy unpaid classification (older than 1 hour):');

            $orderClassificationBase = Order::query()
                ->whereIn('payment_status', ['pending', 'failed', 'expired'])
                ->where('status', 'pending');

            if ($hasOrderPaymentExpiredAt) {
                $orderClassificationBase->whereNull('payment_expired_at');
            }

            $orderLegacyByExpiresAt = $hasOrderPaymentExpiresAt
                ? (clone $orderClassificationBase)
                    ->whereNotNull('payment_expires_at')
                    ->where('payment_expires_at', '<=', $now)
                    ->count()
                : 0;

            $orderLegacyByLinkTime = $hasOrderPaymentLinkCreatedAt
                ? (clone $orderClassificationBase)
                    ->when($hasOrderPaymentExpiresAt, fn ($q) => $q->whereNull('payment_expires_at'))
                    ->whereNotNull('payment_link_created_at')
                    ->where('payment_link_created_at', '<=', $threshold)
                    ->count()
                : 0;

            $orderLegacyByCreatedAt = (clone $orderClassificationBase)
                ->when($hasOrderPaymentExpiresAt, fn ($q) => $q->whereNull('payment_expires_at'))
                ->when($hasOrderPaymentLinkCreatedAt, fn ($q) => $q->whereNull('payment_link_created_at'))
                ->where('created_at', '<=', $threshold)
                ->count();

            $repairClassificationBase = RepairRequest::query()
                ->whereIn('payment_status', ['pending', 'failed', 'expired', 'paid'])
                ->whereNotIn('status', ['completed', 'ready-for-pickup', 'picked_up', 'cancelled']);

            if ($hasRepairPaymentExpiredAt) {
                $repairClassificationBase->whereNull('payment_expired_at');
            }

            $repairLegacyByExpiresAt = $hasRepairPaymentExpiresAt
                ? (clone $repairClassificationBase)
                    ->whereNotNull('payment_expires_at')
                    ->where('payment_expires_at', '<=', $now)
                    ->count()
                : 0;

            $repairLegacyByLinkTime = $hasRepairPaymentLinkCreatedAt
                ? (clone $repairClassificationBase)
                    ->when($hasRepairPaymentExpiresAt, fn ($q) => $q->whereNull('payment_expires_at'))
                    ->whereNotNull('payment_link_created_at')
                    ->where('payment_link_created_at', '<=', $threshold)
                    ->count()
                : 0;

            $repairLegacyByCreatedAt = (clone $repairClassificationBase)
                ->when($hasRepairPaymentExpiresAt, fn ($q) => $q->whereNull('payment_expires_at'))
                ->when($hasRepairPaymentLinkCreatedAt, fn ($q) => $q->whereNull('payment_link_created_at'))
                ->where('created_at', '<=', $threshold)
                ->count();

            $repairRemainingBalanceDue = (clone $repairClassificationBase)
                ->where('payment_status', 'paid')
                ->whereIn('status', ['ready-for-pickup', 'ready_for_pickup'])
                ->count();

            $this->line(sprintf(' - Orders by payment_expires_at: %d', $orderLegacyByExpiresAt));
            $this->line(sprintf(' - Orders by payment_link_created_at fallback: %d', $orderLegacyByLinkTime));
            $this->line(sprintf(' - Orders by created_at fallback: %d', $orderLegacyByCreatedAt));
            $this->line(sprintf(' - Repairs by payment_expires_at: %d', $repairLegacyByExpiresAt));
            $this->line(sprintf(' - Repairs by payment_link_created_at fallback: %d', $repairLegacyByLinkTime));
            $this->line(sprintf(' - Repairs by created_at fallback: %d', $repairLegacyByCreatedAt));
            $this->line(sprintf(' - Repairs in remaining-balance phase (payment_status=paid): %d', $repairRemainingBalanceDue));

            if ($limit > 0) {
                $this->line(sprintf(' - Processing limit per model in this run: %d', $limit));
            }
        }

        $this->info(sprintf(
            'Found %d stale order sessions and %d stale repair sessions%s%s',
            $orders->count(),
            $repairs->count(),
            $dryRun ? ' (dry-run)' : '',
            $limit > 0 ? " (limit={$limit})" : ''
        ));

        $expiredOrders = 0;
        $expiredRepairs = 0;

        foreach ($orders as $order) {
            if ($dryRun) {
                $this->line("[dry-run] Expire order {$order->order_number}");
                continue;
            }

            DB::transaction(function () use (
                $order,
                &$expiredOrders,
                $hasOrderPaymentExpiredAt,
                $hasOrderPaymentFailedAt,
                $hasOrderPaymentFailureReason,
                $hasOrderPaymentReleasedAt
            ): void {
                $freshOrder = Order::query()
                    ->with('items')
                    ->lockForUpdate()
                    ->find($order->id);

                if (!$freshOrder) {
                    return;
                }

                $orderStatus = $freshOrder->status instanceof \BackedEnum
                    ? $freshOrder->status->value
                    : (string) $freshOrder->status;

                if (
                    !in_array((string) $freshOrder->payment_status, ['pending', 'failed', 'expired'], true)
                    || ($hasOrderPaymentExpiredAt && $freshOrder->payment_expired_at !== null)
                    || $orderStatus !== 'pending'
                ) {
                    return;
                }

                $isPastExpiry = ($freshOrder->payment_expires_at !== null && $freshOrder->payment_expires_at->lessThanOrEqualTo(now()))
                    || ($freshOrder->payment_expires_at === null
                        && $freshOrder->payment_link_created_at !== null
                        && $freshOrder->payment_link_created_at->lessThanOrEqualTo(now()->subHours(self::EXPIRY_WINDOW_HOURS)));

                if (!$isPastExpiry) {
                    return;
                }

                foreach ($freshOrder->items as $item) {
                    $this->restoreReservationForExpiredItem(
                        $item,
                        (int) $freshOrder->id,
                        (string) $freshOrder->order_number
                    );
                }

                $existingNotes = trim((string) $freshOrder->notes);
                $expiryNote = 'Auto-cancelled due to payment timeout.';
                $mergedNotes = $existingNotes === '' ? $expiryNote : ($existingNotes . "\n" . $expiryNote);

                $orderUpdate = [
                    'status' => 'cancelled',
                    'notes' => $mergedNotes,
                ];

                if ($hasOrderPaymentExpiredAt) {
                    $orderUpdate['payment_expired_at'] = now();
                }
                if ($hasOrderPaymentFailedAt) {
                    $orderUpdate['payment_failed_at'] = now();
                }
                if ($hasOrderPaymentFailureReason) {
                    $orderUpdate['payment_failure_reason'] = 'payment_timeout';
                }
                if ($hasOrderPaymentReleasedAt) {
                    $orderUpdate['payment_released_at'] = now();
                }

                $freshOrder->update($orderUpdate);

                $expiredOrders++;
            });
        }

        foreach ($repairs as $repair) {
            if ($dryRun) {
                $this->line("[dry-run] Expire repair {$repair->request_id}");
                continue;
            }

            DB::transaction(function () use (
                $repair,
                &$expiredRepairs,
                $hasRepairPaymentExpiredAt,
                $hasRepairPaymentFailedAt,
                $hasRepairPaymentFailureReason
            ): void {
                $freshRepair = RepairRequest::query()
                    ->lockForUpdate()
                    ->find($repair->id);

                if (!$freshRepair) {
                    return;
                }

                if (
                    ($hasRepairPaymentExpiredAt && $freshRepair->payment_expired_at !== null)
                    || in_array((string) $freshRepair->status, ['completed', 'ready-for-pickup', 'ready_for_pickup', 'picked_up', 'cancelled'], true)
                ) {
                    return;
                }

                if (!in_array((string) ($freshRepair->payment_status ?? 'pending'), ['pending', 'failed', 'expired', 'paid'], true)) {
                    return;
                }

                $isPastExpiry = ($freshRepair->payment_expires_at !== null && $freshRepair->payment_expires_at->lessThanOrEqualTo(now()))
                    || ($freshRepair->payment_expires_at === null
                        && $freshRepair->payment_link_created_at !== null
                        && $freshRepair->payment_link_created_at->lessThanOrEqualTo(now()->subHours(self::EXPIRY_WINDOW_HOURS)));

                if (!$isPastExpiry) {
                    return;
                }

                $repairUpdate = [
                    'payment_status' => 'expired',
                    'status' => 'cancelled',
                    'assigned_repairer_id' => null,
                    'assigned_manager_id' => null,
                    'assigned_at' => null,
                ];

                if ($hasRepairPaymentExpiredAt) {
                    $repairUpdate['payment_expired_at'] = now();
                }
                if ($hasRepairPaymentFailedAt) {
                    $repairUpdate['payment_failed_at'] = now();
                }
                if ($hasRepairPaymentFailureReason) {
                    $repairUpdate['payment_failure_reason'] = 'payment_timeout';
                }

                $freshRepair->update($repairUpdate);

                $expiredRepairs++;
            });
        }

        $this->info("Expired order sessions: {$expiredOrders}");
        $this->info("Expired repair sessions: {$expiredRepairs}");

        return self::SUCCESS;
    }
}
