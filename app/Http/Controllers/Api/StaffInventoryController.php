<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StaffInventoryController extends Controller
{
    public function index(Request $request)
    {
        try {
            // Support both User and ShopOwner guards
            $user = Auth::guard('user')->user();
            $shopOwner = Auth::guard('shop_owner')->user();

            if (!$user && !$shopOwner) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Determine shop owner ID based on authenticated guard
            $shopOwnerId = null;
            
            if ($shopOwner) {
                // Direct shop owner authentication
                $shopOwnerId = (int) $shopOwner->id;
            } elseif ($user) {
                // User (staff) authentication - get associated shop owner
                $shopOwnerId = (int) ($user->shop_owner_id ?? 0);
            }

            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }

            $search = trim((string) $request->input('search', ''));
            $category = trim((string) $request->input('category', ''));
            $status = trim((string) $request->input('status', ''));
            $perPage = max(5, min((int) $request->input('per_page', 10), 100));

            $itemsQuery = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true);

            if ($search !== '') {
                $itemsQuery->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            if ($category !== '' && strtoupper($category) !== 'ALL') {
                $itemsQuery->where('category', $category);
            }

            if ($status !== '' && strtoupper($status) !== 'ALL') {
                if ($status === 'In Stock') {
                    $itemsQuery->where('available_quantity', '>', DB::raw('reorder_level'));
                } elseif ($status === 'Low Stock') {
                    $itemsQuery->whereRaw('available_quantity > 0 AND available_quantity <= reorder_level');
                } elseif ($status === 'Out of Stock') {
                    $itemsQuery->where('available_quantity', '<=', 0);
                }
            }

            $items = $itemsQuery
                ->orderBy('name')
                ->paginate($perPage)
                ->through(function (InventoryItem $item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'sku' => $item->sku,
                        'category' => $item->category,
                        'quantity' => $item->available_quantity,
                        'price' => (float) ($item->price ?? 0),
                        'status' => $item->status,
                        'image' => $this->resolveImageUrl($item->main_image),
                        'last_updated' => optional($item->updated_at)->toDateTimeString(),
                    ];
                });

            $baseQuery = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true);

            $metrics = [
                'total_quantity' => (int) (clone $baseQuery)->sum('available_quantity'),
                'low_stock_count' => (int) (clone $baseQuery)->whereRaw('available_quantity > 0 AND available_quantity <= reorder_level')->count(),
                'out_of_stock_count' => (int) (clone $baseQuery)->where('available_quantity', '<=', 0)->count(),
            ];

            $categories = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true)
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->values();

            return response()->json([
                'items' => $items,
                'metrics' => $metrics,
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get staff inventory overview: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to load inventory overview',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveImageUrl(?string $imagePath): ?string
    {
        if (!$imagePath) {
            return null;
        }

        if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://') || str_starts_with($imagePath, '/')) {
            return $imagePath;
        }

        return asset('storage/' . ltrim($imagePath, '/'));
    }
}
