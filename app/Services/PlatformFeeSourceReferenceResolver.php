<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PlatformFeeCharge;
use App\Models\RepairRequest;
use Illuminate\Support\Collection;

final class PlatformFeeSourceReferenceResolver
{
    /** @param Collection<int, PlatformFeeCharge> $charges @return array<int, string> */
    public function forCharges(Collection $charges, int $shopId): array
    {
        $charges = $charges->filter(fn (PlatformFeeCharge $charge): bool => (int) $charge->shop_id === $shopId);
        $legacy = $charges->filter(fn (PlatformFeeCharge $charge): bool => $this->snapshot($charge) === null);
        $orderIds = $legacy->where('source_type', 'order')->pluck('source_id');
        $repairIds = $legacy->where('source_type', 'repair')->pluck('source_id');
        $orders = $orderIds->isEmpty() ? collect() : Order::query()
            ->where('shop_owner_id', $shopId)->whereIn('id', $orderIds)->pluck('order_number', 'id');
        $repairs = $repairIds->isEmpty() ? collect() : RepairRequest::query()
            ->where('shop_owner_id', $shopId)->whereIn('id', $repairIds)->pluck('request_id', 'id');

        return $charges->mapWithKeys(function (PlatformFeeCharge $charge) use ($orders, $repairs): array {
            $reference = $this->snapshot($charge) ?? match ($charge->source_type) {
                'order' => $orders->get($charge->source_id),
                'repair' => $repairs->get($charge->source_id),
                default => null,
            };
            return [(int) $charge->id => is_string($reference) && trim($reference) !== ''
                ? trim($reference) : $this->fallback((string) $charge->source_type, (int) $charge->source_id)];
        })->all();
    }

    public function fallback(string $type, int $id): string
    {
        return ucfirst(str_replace('_', ' ', $type)).' #'.$id;
    }

    private function snapshot(PlatformFeeCharge $charge): ?string
    {
        $key = match ($charge->source_type) { 'order' => 'order_number', 'repair' => 'request_id', default => null };
        $value = $key ? data_get($charge->metadata, $key) : null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
