<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Services\Manager\ManagerOrderService;
use App\Services\Manager\ManagerRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ShopOwnerOperationsMonitoringController extends Controller
{
    public function __construct(
        private readonly ManagerOrderService $orders,
        private readonly ManagerRepairService $repairs,
    ) {
    }

    public function orders(Request $request): JsonResponse
    {
        $owner = $this->owner();
        $orders = $this->orders->listForShopOwner($owner, $request->only([
            'status',
            'assignment_state',
            'handler_id',
            'date_from',
            'date_to',
            'overdue',
            'sort',
            'direction',
            'per_page',
        ]));

        $sourceOrders = \App\Models\Order::where('shop_owner_id', $owner->id)->whereIn('id', $orders->getCollection()->pluck('id'))
            ->with('retailWarrantyIssuance.warranties')->get();
        $coverage = app(\App\Services\RetailWarrantyService::class)->projectOrders($sourceOrders, 'owner');
        $orders->setCollection($orders->getCollection()->map(fn (array $row) => $row + ['product_warranty' => $coverage[$row['id']] ?? null]));

        return response()->json([
            'data' => $orders,
            'last_updated_at' => now()->toISOString(),
        ]);
    }

    public function showOrder(int $id): JsonResponse
    {
        $owner = $this->owner();
        $data = $this->orders->showForShopOwner($owner, $id);
        $order = \App\Models\Order::where('shop_owner_id', $owner->id)->findOrFail($id);
        $coverage = app(\App\Services\RetailWarrantyService::class)->projectOrders(new \Illuminate\Database\Eloquent\Collection([$order]), 'owner');
        return response()->json([
            'data' => $data + ['product_warranty' => $coverage[$id] ?? null],
        ]);
    }

    public function repairs(Request $request): JsonResponse
    {
        $repairs = $this->repairs->listForShopOwner($this->owner(), $request->only([
            'status',
            'assignment_state',
            'repairer_id',
            'review_pending',
            'search',
            'date_from',
            'date_to',
            'overdue',
            'sort',
            'direction',
            'per_page',
        ]));

        return response()->json([
            'data' => $repairs,
            'last_updated_at' => now()->toISOString(),
        ]);
    }

    public function showRepair(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->repairs->showForShopOwner($this->owner(), $id),
        ]);
    }

    private function owner(): ShopOwner
    {
        $owner = Auth::guard('shop_owner')->user();

        abort_unless($owner instanceof ShopOwner, 401);

        return $owner;
    }
}
