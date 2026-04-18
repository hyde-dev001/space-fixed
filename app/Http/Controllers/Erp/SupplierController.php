<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /**
     * Display a listing of suppliers
     */
    public function index(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        $showArchived = $request->boolean('archived');
        
        $suppliers = Supplier::where('shop_owner_id', $shopOwnerId)
            ->when($showArchived, function ($query) {
                $query->onlyTrashed();
            })
            ->withCount([
                'purchaseOrders as purchase_order_count' => function ($query) use ($shopOwnerId) {
                    $query->where('shop_owner_id', $shopOwnerId);
                },
            ])
            ->withMax([
                'purchaseOrders as last_order_date' => function ($query) use ($shopOwnerId) {
                    $query->where('shop_owner_id', $shopOwnerId);
                },
            ], 'ordered_date')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
        
        return response()->json($suppliers);
    }

    /**
     * Store a newly created supplier
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'lead_time_days' => 'nullable|integer|min:0',
            'products_supplied' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $supplier = Supplier::create(array_merge($validated, [
            'shop_owner_id' => $shopOwnerId,
            'is_active' => true
        ]));
        
        return response()->json([
            'message' => 'Supplier created successfully',
            'supplier' => $supplier
        ], 201);
    }

    /**
     * Display the specified supplier
     */
    public function show(Request $request, $id)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $supplier = Supplier::with(['purchaseOrders' => function ($query) {
                $query->latest()->limit(10);
            }])
            ->where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        return response()->json($supplier);
    }

    /**
     * Update the specified supplier
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'lead_time_days' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'products_supplied' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $supplier = Supplier::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        $supplier->update($validated);
        
        return response()->json([
            'message' => 'Supplier updated successfully',
            'supplier' => $supplier
        ]);
    }

    /**
     * Archive the specified supplier (soft delete)
     */
    public function destroy(Request $request, $id)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $supplier = Supplier::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        // Check if supplier has active orders
        $activeOrders = $supplier->purchaseOrders()
            ->whereIn('status', ['sent', 'confirmed', 'in_transit'])
            ->count();
        
        if ($activeOrders > 0) {
            return response()->json([
                'message' => 'Cannot archive supplier with active orders',
                'active_orders' => $activeOrders
            ], 422);
        }
        
        $supplier->delete();
        
        return response()->json([
            'message' => 'Supplier archived successfully'
        ]);
    }

    /**
     * Restore an archived supplier
     */
    public function restore(Request $request, $id)
    {
        $shopOwnerId = $request->user()->shop_owner_id;

        $supplier = Supplier::onlyTrashed()
            ->where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        $supplier->restore();

        return response()->json([
            'message' => 'Supplier restored successfully'
        ]);
    }
}
