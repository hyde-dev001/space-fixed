<?php

namespace App\Http\Controllers\ERP;

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
        
        $suppliers = Supplier::where('shop_owner_id', $shopOwnerId)
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
    public function show($id)
    {
        $shopOwnerId = request()->user()->shop_owner_id;
        
        $supplier = Supplier::with(['orders' => function ($query) {
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
     * Remove the specified supplier (soft delete)
     */
    public function destroy($id)
    {
        $shopOwnerId = request()->user()->shop_owner_id;
        
        $supplier = Supplier::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        // Check if supplier has active orders
        $activeOrders = $supplier->orders()
            ->whereIn('status', ['sent', 'confirmed', 'in_transit'])
            ->count();
        
        if ($activeOrders > 0) {
            return response()->json([
                'message' => 'Cannot delete supplier with active orders',
                'active_orders' => $activeOrders
            ], 422);
        }
        
        $supplier->delete();
        
        return response()->json([
            'message' => 'Supplier deleted successfully'
        ]);
    }
}
