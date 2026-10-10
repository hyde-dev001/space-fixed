<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShowroomPlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ShowroomWallArtController extends Controller
{
    public function store(Request $request, ShowroomPlacementService $showroom): JsonResponse
    {
        $validated = $request->validate([
            'wall' => ['required', Rule::in(['left', 'right'])],
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);
        $shopOwnerId = $showroom->actorShopOwnerId($request);
        abort_unless($shopOwnerId && $showroom->canManageWallArt($request, $shopOwnerId), 403, 'Only the shop owner can manage wall art.');

        $path = $showroom->replaceWallArt($shopOwnerId, $validated['wall'], $validated['image']);

        return response()->json([
            'success' => true,
            'wall' => $validated['wall'],
            'url' => asset('storage/' . ltrim($path, '/')),
        ]);
    }

    public function destroy(Request $request, string $wall, ShowroomPlacementService $showroom): JsonResponse
    {
        abort_unless(in_array($wall, ['left', 'right'], true), 404);
        $shopOwnerId = $showroom->actorShopOwnerId($request);
        abort_unless($shopOwnerId && $showroom->canManageWallArt($request, $shopOwnerId), 403, 'Only the shop owner can manage wall art.');

        $showroom->removeWallArt($shopOwnerId, $wall);

        return response()->json(['success' => true, 'wall' => $wall]);
    }
}
