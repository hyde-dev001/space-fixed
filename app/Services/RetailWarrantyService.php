<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\CodCollection;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\OrderRefundItem;
use App\Models\PosRefund;
use App\Models\PosRefundItem;
use App\Models\RetailWarranty;
use App\Models\RetailWarrantyIssuance;
use App\Models\ShopOwner;
use App\Models\ShopRetailWarrantySetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RetailWarrantyService
{
    public function captureFulfillment(Order $order): ?RetailWarrantyIssuance
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true)) {
                return null;
            }
            if (! $order->retail_warranty_fulfilled_at) {
                // Settings writes only lock the shop; fulfillment consistently locks order then shop.
                $shop = ShopOwner::whereKey($order->shop_owner_id)->lockForUpdate()->firstOrFail();
                $setting = $shop->retailWarrantySetting;
                $fulfilledAt = now()->utc();
                $eligible = in_array(strtolower(trim($shop->business_type)), ['retail', 'both'], true)
                    && $setting?->enabled && $setting->eligible_orders_from
                    && $order->created_at->gte($setting->eligible_orders_from)
                    && $fulfilledAt->gte($setting->eligible_orders_from);
                $snapshot = ['eligible' => false];
                if ($eligible) {
                    $buyer = $order->customer;
                    $buyerName = $buyer ? (trim((string) $buyer->name) ?: trim($buyer->first_name.' '.$buyer->last_name)) : $order->customer_name;
                    $snapshot = [
                        'eligible' => (bool) $eligible, 'policy' => $setting?->only(['title', 'duration_value', 'duration_unit', 'description', 'terms', 'exclusions', 'instructions']),
                        'timezone' => config('app.shop_timezone', 'Asia/Manila'),
                        'shop' => ['name' => $shop->business_name, 'address' => $shop->business_address, 'phone' => $shop->phone, 'email' => $shop->email],
                        'customer' => ['name' => $buyerName ?: 'Walk-in Customer', 'email' => $buyer ? $buyer->email : $order->customer_email],
                        'order' => ['number' => $order->order_number, 'created_at' => $order->created_at->toIso8601String()],
                        'items' => $order->items()->orderBy('id')->get()->map(fn ($item) => [
                            'id' => $item->id, 'product_id' => $item->product_id, 'variant_id' => $item->product_variant_id,
                            'name' => $item->product_name, 'size' => $item->size, 'color' => $item->color, 'quantity' => (int) $item->quantity,
                        ])->all(),
                    ];
                }
                $order->forceFill(['retail_warranty_fulfilled_at' => $fulfilledAt, 'retail_warranty_policy_snapshot' => $snapshot])->save();
                $this->audit($shop->id, 'retail_warranty.fulfillment_captured', 'order', $order->id, ['eligible' => (bool) $eligible]);
            }

            return $this->issueCaptured($order);
        }, 3);
    }

    public function issueCaptured(Order $order): ?RetailWarrantyIssuance
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($existing = $order->retailWarrantyIssuance) {
                return $existing->load('warranties');
            }
            $capture = $order->retail_warranty_policy_snapshot;
            if (! $order->retail_warranty_fulfilled_at || ! ($capture['eligible'] ?? false)
                || ! in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true)
                || ! $this->paymentEligible($order) || empty($capture['items'])) {
                return null;
            }
            $issuance = RetailWarrantyIssuance::create([
                'shop_owner_id' => $order->shop_owner_id, 'order_id' => $order->id, 'customer_id' => $order->customer_id,
                'warranty_number' => 'WRNTY-'.now()->year.'-'.Str::upper(Str::random(20)),
                'shop_snapshot' => $capture['shop'], 'customer_snapshot' => $capture['customer'], 'order_snapshot' => $capture['order'],
                'fulfilled_at' => $order->retail_warranty_fulfilled_at, 'issued_at' => now()->utc(), 'business_timezone' => $capture['timezone'],
            ]);
            $policy = $capture['policy'];
            $start = $order->retail_warranty_fulfilled_at;
            $expiry = $this->expiration($start, $policy['duration_value'], $policy['duration_unit'], $capture['timezone']);
            foreach ($capture['items'] as $item) {
                if ($item['quantity'] < 1) {
                    continue;
                }
                $warranty = RetailWarranty::create([
                    'retail_warranty_issuance_id' => $issuance->id, 'shop_owner_id' => $order->shop_owner_id,
                    'order_id' => $order->id, 'order_item_id' => $item['id'], 'customer_id' => $order->customer_id,
                    'policy_snapshot' => $policy, 'item_snapshot' => $item, 'original_covered_quantity' => $item['quantity'],
                    'warranty_start_date' => $start, 'warranty_expiration_date' => $expiry,
                ]);
                $this->audit($order->shop_owner_id, 'retail_warranty.item_issued', 'retail_warranty', $warranty->id);
            }
            $this->audit($order->shop_owner_id, 'retail_warranty.issuance_created', 'retail_warranty_issuance', $issuance->id);
            DB::afterCommit(function () use ($issuance) {
                try {
                    \App\Jobs\DeliverRetailWarranty::dispatch($issuance->id);
                } catch (\Throwable $failure) {
                    // The durable pending issuance is recovered by the reconciliation command.
                    \Illuminate\Support\Facades\Log::warning('Product Warranty delivery enqueue failed.', ['issuance_id' => $issuance->id]);
                }
            });

            return $issuance->load('warranties');
        }, 3);
    }

    public function expiration(CarbonImmutable $start, int $duration, string $unit, string $timezone): CarbonImmutable
    {
        $local = $start->setTimezone($timezone);

        return match ($unit) {
            'days' => $local->addDays($duration)->utc(),
            'weeks' => $local->addWeeks($duration)->utc(),
            'months' => $local->addMonthsNoOverflow($duration)->utc(),
            'years' => $local->addYearsNoOverflow($duration)->utc(),
            default => throw new \LogicException('Invalid snapshotted warranty unit.'),
        };
    }

    private function paymentEligible(Order $order): bool
    {
        if (in_array(strtolower(trim((string) $order->payment_method)), ['cod', 'cash_on_delivery', 'cash on delivery'], true)) {
            return $order->codCollection()->whereIn('status', [CodCollection::STATUS_CASH_COLLECTED, CodCollection::STATUS_SETTLED])
                ->where('collected_amount', '>', 0)->exists();
        }

        return in_array(strtolower(trim((string) $order->payment_status)), ['paid', 'completed'], true);
    }

    /** Two aggregate queries for any number of covered items, including ordinary product refunds. */
    public function quantities(Collection $warranties): array
    {
        $ids = $warranties->pluck('order_item_id')->unique()->all();
        if ($ids === []) {
            return [];
        }
        $totals = [];
        foreach ([[new OrderRefundItem, new OrderRefund, 'order_refund_id'], [new PosRefundItem, new PosRefund, 'pos_refund_id']] as [$item, $refund, $foreignKey]) {
            $rows = DB::table($item->getTable().' as lines')->join($refund->getTable().' as refunds', 'refunds.id', '=', 'lines.'.$foreignKey)
                ->whereIn('lines.order_item_id', $ids)
                ->select('lines.order_item_id')
                ->selectRaw("SUM(CASE WHEN refunds.status IN ('succeeded', 'successful', 'completed', 'refunded') THEN COALESCE(lines.approved_qty, lines.requested_qty, 0) ELSE 0 END) as consumed")
                ->selectRaw("SUM(CASE WHEN refunds.status IN ('requested', 'pending_approval', 'approved', 'processing') THEN COALESCE(lines.approved_qty, lines.requested_qty, 0) ELSE 0 END) as reserved")
                ->groupBy('lines.order_item_id')->get();
            foreach ($rows as $row) {
                $totals[$row->order_item_id]['consumed'] = ($totals[$row->order_item_id]['consumed'] ?? 0) + (int) $row->consumed;
                $totals[$row->order_item_id]['reserved'] = ($totals[$row->order_item_id]['reserved'] ?? 0) + (int) $row->reserved;
            }
        }

        return $warranties->mapWithKeys(function (RetailWarranty $warranty) use ($totals) {
            $consumed = min($warranty->original_covered_quantity, max($warranty->refunded_quantity, $totals[$warranty->order_item_id]['consumed'] ?? 0));
            $remaining = $warranty->status === 'voided' ? 0 : max(0, $warranty->original_covered_quantity - $consumed);
            $reserved = min($remaining, $totals[$warranty->order_item_id]['reserved'] ?? 0);

            return [$warranty->id => ['refunded' => $consumed, 'remaining' => $remaining, 'reserved' => $reserved, 'available' => $remaining - $reserved]];
        })->all();
    }

    public function projectIssuances(Collection $issuances, string $audience = 'customer'): array
    {
        $issuances = new \Illuminate\Database\Eloquent\Collection($issuances->all());
        $issuances->loadMissing('warranties');
        $quantities = $this->quantities($issuances->flatMap(fn ($issuance) => $issuance->warranties));

        return $issuances->mapWithKeys(function (RetailWarrantyIssuance $issuance) use ($quantities, $audience) {
            $items = $issuance->warranties->map(function (RetailWarranty $warranty) use ($quantities) {
                $qty = $quantities[$warranty->id];
                $status = $warranty->status === 'voided' || $qty['remaining'] === 0 ? 'voided'
                    : ($warranty->status === 'expired' || now()->gte($warranty->warranty_expiration_date) ? 'expired' : 'active');

                return ['id' => $warranty->id, 'order_item_id' => $warranty->order_item_id,
                    'product_name' => $warranty->item_snapshot['name'], 'size' => $warranty->item_snapshot['size'] ?? null,
                    'color' => $warranty->item_snapshot['color'] ?? null, 'covered_quantity' => $warranty->original_covered_quantity,
                    'refunded_quantity' => $qty['refunded'], 'remaining_quantity' => $qty['remaining'], 'reserved_quantity' => $qty['reserved'],
                    'available_quantity' => $qty['available'], 'status' => $status,
                    'can_assess' => $status === 'active' && $qty['available'] > 0 && now()->gte($warranty->warranty_start_date),
                    'start_date' => $warranty->warranty_start_date->toIso8601String(), 'expiration_date' => $warranty->warranty_expiration_date->toIso8601String(),
                    'policy' => $warranty->policy_snapshot, 'void_reason' => $warranty->void_reason];
            })->values();
            $status = 'active';
            if ($items->every(fn ($item) => $item['status'] === 'voided')) {
                $status = $items->sum('refunded_quantity') > 0 ? 'no_remaining_coverage' : 'voided';
            } elseif (! $items->contains(fn ($item) => $item['status'] === 'active')) {
                $status = 'expired';
            } elseif ($items->sum('refunded_quantity') > 0 || $items->contains(fn ($item) => $item['status'] === 'voided')) {
                $status = 'partially_used';
            }
            $routeName = match ($audience) {
                'owner' => 'shop_owner.retail-warranties.certificate', 'staff' => 'api.staff.retail-warranties.certificate',
                default => 'customer.retail-warranties.certificate',
            };

            return [$issuance->order_id => ['id' => $issuance->id, 'reference' => $issuance->warranty_number,
                'order_number' => $issuance->order_snapshot['number'] ?? (string) $issuance->order_id,
                'customer_name' => $issuance->customer_snapshot['name'], 'shop_name' => $issuance->shop_snapshot['name'],
                'issued_at' => $issuance->issued_at->toIso8601String(), 'fulfilled_at' => $issuance->fulfilled_at->toIso8601String(),
                'timezone' => $issuance->business_timezone, 'status' => $status,
                'download_url' => route($routeName, ['reference' => $issuance->warranty_number], false), 'items' => $items->all()]];
        })->all();
    }

    public function projectOrders(Collection $orders, string $audience = 'customer'): array
    {
        $orders = new \Illuminate\Database\Eloquent\Collection($orders->all());
        $orders->loadMissing('retailWarrantyIssuance.warranties');

        return $this->projectIssuances($orders->pluck('retailWarrantyIssuance')->filter(), $audience);
    }

    public function filterIssuances(Builder $query, string $status): void
    {
        $table = (new RetailWarranty)->getTable();
        $consumed = $this->successfulQuantitySql();
        $remaining = fn (Builder $q) => $q->where('status', '!=', 'voided')->whereColumn('original_covered_quantity', '>', 'refunded_quantity')
            ->whereRaw("{$table}.original_covered_quantity > ({$consumed})");
        $active = function (Builder $q) use ($remaining) {
            $remaining($q);
            $q->where('status', 'active')->where('warranty_expiration_date', '>', now()->utc());
        };
        $refunded = fn (Builder $q) => $q->where(fn ($q) => $q->where('refunded_quantity', '>', 0)->orWhereRaw("({$consumed}) > 0"));
        $used = fn (Builder $q) => $q->where(fn ($q) => $q->where('status', 'voided')->orWhere('refunded_quantity', '>', 0)->orWhereRaw("({$consumed}) > 0"));
        match ($status) {
            'active' => $query->whereHas('warranties', $active)->whereDoesntHave('warranties', $used),
            'partially_used' => $query->whereHas('warranties', $active)->whereHas('warranties', $used),
            'no_remaining_coverage' => $query->whereDoesntHave('warranties', $remaining)->whereHas('warranties', $refunded),
            'voided' => $query->whereDoesntHave('warranties', $remaining)->whereDoesntHave('warranties', $refunded),
            'expired' => $query->whereDoesntHave('warranties', $active)->whereHas('warranties', $remaining),
            default => null,
        };
    }

    private function successfulQuantitySql(): string
    {
        $table = (new RetailWarranty)->getTable();
        $sums = [];
        foreach ([[new OrderRefundItem, new OrderRefund, 'order_refund_id'], [new PosRefundItem, new PosRefund, 'pos_refund_id']] as [$item, $refund, $fk]) {
            $sums[] = '(SELECT COALESCE(SUM(COALESCE(l.approved_qty,l.requested_qty,0)),0) FROM '.$item->getTable().' l JOIN '.$refund->getTable().' r ON r.id=l.'.$fk
                ." WHERE l.order_item_id={$table}.order_item_id AND r.status IN ('succeeded','successful','completed','refunded'))";
        }

        return implode(' + ', $sums);
    }

    public function reconciliationCandidates(int $limit): Collection
    {
        $consumed = $this->successfulQuantitySql();

        return RetailWarranty::where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'active')->where('warranty_expiration_date', '<=', now()->utc()))
            ->orWhere(fn ($q) => $q->whereColumn('refunded_quantity', '<', 'original_covered_quantity')->whereRaw("({$consumed}) > refunded_quantity")))
            ->orderBy('id')->limit($limit)->get();
    }

    public function reconcileOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $warranties = RetailWarranty::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $quantities = $this->quantities($warranties);
            foreach ($warranties as $warranty) {
                $before = $warranty->refunded_quantity;
                $oldStatus = $warranty->status;
                $warranty->refunded_quantity = $quantities[$warranty->id]['refunded'];
                if ($warranty->status !== 'voided' && $warranty->refunded_quantity >= $warranty->original_covered_quantity) {
                    $warranty->status = 'voided';
                    $warranty->voided_at = now()->utc();
                    $warranty->void_reason = 'All covered product quantities were successfully refunded.';
                    $warranty->void_actor_type = 'system';
                } elseif ($warranty->status === 'active' && now()->gte($warranty->warranty_expiration_date)) {
                    $warranty->status = 'expired';
                }
                if (! $warranty->isDirty()) {
                    continue;
                }
                $warranty->save();
                if ($before !== $warranty->refunded_quantity) {
                    $this->audit($warranty->shop_owner_id, 'retail_warranty.quantity_consumed', 'retail_warranty', $warranty->id,
                        ['before' => $before, 'after' => $warranty->refunded_quantity]);
                }
                if ($oldStatus !== $warranty->status) {
                    $this->audit($warranty->shop_owner_id, 'retail_warranty.'.$warranty->status, 'retail_warranty', $warranty->id,
                        ['before' => $oldStatus, 'after' => $warranty->status, 'reason' => $warranty->void_reason]);
                }
            }
        }, 3);
    }

    public function voidWarranty(RetailWarranty $warranty, ShopOwner $actor, string $reason): void
    {
        DB::transaction(function () use ($warranty, $actor, $reason) {
            Order::whereKey($warranty->order_id)->lockForUpdate()->firstOrFail();
            $warranty = RetailWarranty::whereKey($warranty->id)->where('shop_owner_id', $actor->id)->lockForUpdate()->firstOrFail();
            if ($warranty->status === 'voided') {
                return;
            }
            $warranty->forceFill(['status' => 'voided', 'void_reason' => trim($reason), 'voided_at' => now()->utc(),
                'void_actor_type' => 'shop_owner', 'void_actor_id' => $actor->id])->save();
            $this->audit($actor->id, 'retail_warranty.voided', 'retail_warranty', $warranty->id,
                ['actor_type' => 'shop_owner', 'actor_id' => $actor->id, 'reason' => trim($reason)]);
        });
    }

    /** Recheck inside the refund's order lock before reserving any quantities/funds. */
    public function validateAssessment(Order $order, array $lines, string $channel = 'online'): array
    {
        return DB::transaction(function () use ($order, $lines, $channel) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $invalid = fn (string $message) => throw ValidationException::withMessages(['refund_lines' => [$message]]);
            if (! in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true) || $lines === []) {
                $invalid('Select covered items from a fulfilled purchase for warranty assessment.');
            }
            if ($channel === 'online' && ($order->origin_channel === 'pos' || \App\Models\PosTransaction::where('module_type', 'retail')->where('module_reference_id', $order->id)->exists())) {
                $invalid('POS warranty assessment must be handled by the shop with the existing inspection process.');
            }
            if (\App\Models\DeliveryDispute::where('order_id', $order->id)->whereIn('status', ['open', 'investigating'])->exists()) {
                $invalid('Resolve the active delivery investigation before warranty assessment.');
            }
            if (OrderRefund::where('order_id', $order->id)->whereIn('status', ['succeeded', 'successful'])->whereDoesntHave('items')->exists()) {
                $invalid('Existing refund history needs item-level review before further assessment.');
            }
            $ids = array_map(fn ($line) => (int) ($line['order_item_id'] ?? 0), $lines);
            if (count(array_unique($ids)) !== count($ids)) {
                $invalid('Select each covered order item only once.');
            }
            $warranties = RetailWarranty::where('order_id', $order->id)->where('shop_owner_id', $order->shop_owner_id)
                ->where('customer_id', $order->customer_id)->whereIn('order_item_id', $ids)->orderBy('id')->lockForUpdate()->get();
            $byItem = $warranties->keyBy('order_item_id');
            $quantities = $this->quantities($warranties);
            $links = [];
            foreach ($lines as $line) {
                $warranty = $byItem->get((int) ($line['order_item_id'] ?? 0));
                $qty = (int) ($line['requested_qty'] ?? 0);
                if (! $warranty || $warranty->id !== (int) ($line['retail_warranty_id'] ?? 0)
                    || $warranty->status !== 'active' || now()->gte($warranty->warranty_expiration_date)
                    || now()->lt($warranty->warranty_start_date) || $qty < 1 || $qty > $quantities[$warranty->id]['available']) {
                    $invalid('Warranty coverage, item, status or remaining quantity is not eligible for this assessment.');
                }
                $links[$warranty->order_item_id] = $warranty->id;
            }

            return $links;
        });
    }

    public function saveSettings(ShopOwner $owner, array $validated): ShopRetailWarrantySetting
    {
        return DB::transaction(function () use ($owner, $validated) {
            ShopOwner::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $setting = ShopRetailWarrantySetting::firstOrNew(['shop_owner_id' => $owner->id]);
            $before = $setting->only($setting->getFillable());
            if (array_key_exists('title', $validated) && $validated['title'] === null) {
                $validated['title'] = 'Product Warranty';
            }
            $setting->fill($validated);
            if ($setting->enabled && ! $setting->eligible_orders_from) {
                $setting->eligible_orders_from = now()->utc();
            }
            if (! $setting->exists || $setting->isDirty()) {
                $setting->save();
                $this->audit($owner->id, 'retail_warranty.settings_changed', 'shop_retail_warranty_setting', $setting->id, [
                    'actor_type' => 'shop_owner', 'actor_id' => $owner->id,
                    'before' => $before, 'after' => $setting->only($setting->getFillable()),
                ]);
            }

            return $setting;
        });
    }

    public function settingsFor(ShopOwner $owner): array
    {
        $setting = $owner->retailWarrantySetting ?? new ShopRetailWarrantySetting;

        return array_merge($setting->only(['enabled', 'title', 'duration_value', 'duration_unit', 'description', 'terms', 'exclusions', 'instructions']), [
            'eligible_orders_from' => $setting->eligible_orders_from?->toIso8601String(),
        ]);
    }

    public function audit(int $shopId, string $action, string $targetType, int $targetId, array $metadata = []): void
    {
        AuditLog::create(['shop_owner_id' => $shopId, 'action' => $action, 'target_type' => $targetType,
            'target_id' => $targetId, 'metadata' => $metadata + ['actor_type' => 'system']]);
    }
}
