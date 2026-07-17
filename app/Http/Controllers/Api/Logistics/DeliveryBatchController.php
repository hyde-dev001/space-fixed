<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\BatchDispatchService;
use App\Services\Logistics\BatchSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeliveryBatchController extends Controller
{
    public function index(): JsonResponse
    {
        $shop = $this->dispatcherShop();
        return response()->json(['batches' => DeliveryBatch::with(['riderProfile', 'legs.assignments'])
            ->where('shop_owner_id', $shop->id)->latest()->get()]);
    }

    public function suggestions(Request $request, BatchSuggestionService $service): JsonResponse
    {
        $shop = $this->dispatcherShop();
        $data = $request->validate(['delivery_date' => ['required', 'date'], 'delivery_window' => ['required', 'in:morning,afternoon']]);
        return response()->json(['suggestions' => $service->suggest($shop, now()->parse($data['delivery_date']), $data['delivery_window'])]);
    }

    public function store(Request $request, BatchDispatchService $service): JsonResponse
    {
        $shop = $this->dispatcherShop();
        $data = $request->validate([
            'delivery_date' => ['required', 'date'], 'delivery_window' => ['required', 'in:morning,afternoon'],
            'leg_ids' => ['required', 'array', 'min:1'], 'leg_ids.*' => ['integer', 'distinct'],
            'dispatcher_override_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['batch' => $service->createDraft($shop, $data['delivery_date'], $data['delivery_window'], $data['leg_ids'], $data['dispatcher_override_reason'] ?? null)], 201);
    }

    public function schedule(Request $request, BatchDispatchService $service): JsonResponse
    {
        $shop = $this->dispatcherShop();
        $data = $request->validate([
            'delivery_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'delivery_window' => ['required', 'in:morning,afternoon'],
            'leg_ids' => ['required', 'array', 'min:1'], 'leg_ids.*' => ['integer', 'distinct'],
        ]);
        $service->schedule($shop, $data['delivery_date'], $data['delivery_window'], $data['leg_ids']);
        return response()->json(['message' => 'Deliveries scheduled.']);
    }

    public function offer(Request $request, DeliveryBatch $batch, BatchDispatchService $service): JsonResponse
    {
        $shop = $this->dispatcherShop($batch);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer'],
            'capacity_override_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $rider = RiderProfile::where('shop_owner_id', $shop->id)->findOrFail($data['rider_profile_id']);
        return response()->json(['batch' => $service->offer($batch, $rider, $shop, $data['capacity_override_reason'] ?? null)]);
    }

    public function update(Request $request, DeliveryBatch $batch, BatchDispatchService $service): JsonResponse
    {
        $this->dispatcherShop($batch);
        $ids = $request->validate(['leg_ids' => ['required', 'array', 'min:1'], 'leg_ids.*' => ['integer', 'distinct']])['leg_ids'];
        return response()->json(['batch' => $service->replaceStops($batch, $ids)]);
    }

    public function remove(DeliveryBatch $batch, ShipmentLeg $leg, BatchDispatchService $service): JsonResponse
    {
        $this->dispatcherShop($batch);
        return response()->json(['batch' => $service->removeStop($batch, $leg)]);
    }

    public function urgent(Request $request, ShipmentLeg $leg, BatchDispatchService $service): JsonResponse
    {
        $shop = $this->dispatcherShop();
        $leg->loadMissing('shipment');
        abort_unless($leg->shipment->shop_owner_id === $shop->id, 403);
        return response()->json(['leg' => $service->markUrgent($leg, $request->boolean('urgent', true))]);
    }

    public function accept(DeliveryBatch $batch, BatchDispatchService $service): JsonResponse
    {
        return response()->json(['batch' => $service->accept($batch, $this->assignedRider($batch))]);
    }

    public function reject(Request $request, DeliveryBatch $batch, BatchDispatchService $service): JsonResponse
    {
        $reason = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']])['rejection_reason'];
        return response()->json(['batch' => $service->reject($batch, $this->assignedRider($batch), $reason)]);
    }

    public function start(DeliveryBatch $batch, BatchDispatchService $service): JsonResponse
    {
        return response()->json(['batch' => $service->start($batch, $this->assignedRider($batch))]);
    }

    public function cancel(Request $request, DeliveryBatch $batch, BatchDispatchService $service): JsonResponse
    {
        $this->dispatcherShop($batch);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];
        return response()->json(['batch' => $service->cancel($batch, $reason)]);
    }

    public function restore(DeliveryBatch $batch, BatchDispatchService $service): JsonResponse
    {
        $this->dispatcherShop($batch);
        return response()->json(['batch' => $service->restore($batch)]);
    }

    private function dispatcherShop(?DeliveryBatch $batch = null): ShopOwner
    {
        $shop = Auth::guard('shop_owner')->user();
        if (!$shop) {
            $user = Auth::guard('user')->user();
            abort_unless($user?->shop_owner_id && ($user->can('manage-logistics-batches') || $user->can('assign-logistics-deliveries')), 403);
            $shop = ShopOwner::findOrFail($user->shop_owner_id);
        }
        abort_if($batch && $batch->shop_owner_id !== $shop->id, 403);
        return $shop;
    }

    private function assignedRider(DeliveryBatch $batch): RiderProfile
    {
        $user = Auth::guard('user')->user();
        abort_unless($user, 403);
        $rider = RiderProfile::query()->whereKey($batch->rider_profile_id)
            ->where('linked_type', User::class)->where('linked_id', $user->id)->first();
        abort_unless($rider, 403);
        return $rider;
    }
}
