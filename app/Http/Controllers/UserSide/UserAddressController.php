<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserAddressController extends Controller
{
    /**
     * Get all addresses for authenticated user
     */
    public function index()
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $addresses = $user->addresses()
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'addresses' => $addresses,
        ]);
    }

    /**
     * Store a new address
     */
    public function store(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'region' => 'required|string|max:255',
            'province' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'barangay' => 'required|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'address_line' => 'required|string|max:500',
            'is_default' => 'boolean',
        ]);

        $validated['user_id'] = $user->id;

        // If this is set as default, unset other defaults
        if ($validated['is_default'] ?? false) {
            $user->addresses()->update(['is_default' => false]);
        }

        // If this is user's first address, make it default
        if ($user->addresses()->count() === 0) {
            $validated['is_default'] = true;
        }

        $address = UserAddress::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Address added successfully',
            'address' => $address,
        ], 201);
    }

    /**
     * Update an existing address
     */
    public function update(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'region' => 'required|string|max:255',
            'province' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'barangay' => 'required|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'address_line' => 'required|string|max:500',
            'is_default' => 'boolean',
        ]);

        // If this is set as default, unset other defaults
        if ($validated['is_default'] ?? false) {
            $user->addresses()->where('id', '!=', $id)->update(['is_default' => false]);
        }

        $address->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Address updated successfully',
            'address' => $address,
        ]);
    }

    /**
     * Delete an address
     */
    public function destroy($id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);
        
        // If deleting default address, set another as default
        if ($address->is_default) {
            $nextAddress = $user->addresses()->where('id', '!=', $id)->first();
            if ($nextAddress) {
                $nextAddress->update(['is_default' => true]);
            }
        }

        $address->delete();

        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully',
        ]);
    }

    /**
     * Set an address as default
     */
    public function setDefault($id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);
        $address->setAsDefault();

        return response()->json([
            'success' => true,
            'message' => 'Default address updated',
            'address' => $address->fresh(),
        ]);
    }
}
