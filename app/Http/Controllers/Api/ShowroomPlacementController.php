<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShowroomPlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowroomPlacementController extends Controller
{
    public function update(Request $request, ShowroomPlacementService $placements): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'from_slot_key' => ['required', 'string', 'regex:/^slot-[0-9]+$/'],
            'to_slot_key' => ['required', 'string', 'regex:/^slot-[0-9]+$/'],
        ]);
        $shopOwnerId = $placements->actorShopOwnerId($request);
        abort_unless($shopOwnerId, 403, 'A shop-linked account is required.');
        abort_unless($placements->canEdit($request, $shopOwnerId), 403, 'You cannot edit this showroom.');

        $canonical = $placements->move(
            $shopOwnerId,
            (int) $validated['product_id'],
            $validated['from_slot_key'],
            $validated['to_slot_key'],
        );

        return response()->json([
            'success' => true,
            'placements' => $canonical->map(fn ($placement) => [
                'product_id' => (int) $placement->product_id,
                'slot_key' => $placement->slot_key,
            ])->values(),
        ]);
    }
}
