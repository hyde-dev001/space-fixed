<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\CashCollectedRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\CodCollectionService;
use Illuminate\Support\Facades\Auth;

class CodCollectionController extends Controller
{
    public function __construct(
        private readonly CodCollectionService $codCollectionService,
    ) {}

    public function cashCollected(CashCollectedRequest $request, Order $order)
    {
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User, 403);

        $collection = $this->codCollectionService->cashCollected($order, $actor, $request->validated());

        return response()->json([
            'success' => true,
            'collection' => [
                'id' => $collection->id,
                'order_id' => $collection->order_id,
                'shipment_id' => $collection->shipment_id,
                'shipment_leg_id' => $collection->shipment_leg_id,
                'rider_user_id' => $collection->rider_user_id,
                'expected_amount' => (string) $collection->expected_amount,
                'collected_amount' => (string) $collection->collected_amount,
                'status' => $collection->status,
                'collected_at' => optional($collection->collected_at)->toISOString(),
            ],
        ]);
    }

    public function index()
    {
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User, 403);

        return response()->json([
            'success' => true,
            ...$this->codCollectionService->riderCollections($actor),
        ]);
    }
}
