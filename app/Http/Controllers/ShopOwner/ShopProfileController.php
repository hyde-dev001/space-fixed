<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ShopProfileController extends Controller
{
    /**
     * Display the shop profile page
     */
    public function index(): Response
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        return Inertia::render('ShopOwner/shopProfile', [
            'shop_owner' => [
                'id' => $shopOwner->id,
                'first_name' => $shopOwner->first_name,
                'last_name' => $shopOwner->last_name,
                'name' => $shopOwner->name ?? $shopOwner->business_name,
                'business_name' => $shopOwner->business_name,
                'email' => $shopOwner->email,
                'phone' => $shopOwner->phone,
                'bio' => $shopOwner->bio,
                'country' => $shopOwner->country,
                'city_state' => $shopOwner->city_state,
                'postal_code' => $shopOwner->postal_code,
                'tax_id' => $shopOwner->tax_id,
                'profile_photo' => $shopOwner->profile_photo,
                'monday_open' => $shopOwner->monday_open,
                'monday_close' => $shopOwner->monday_close,
                'tuesday_open' => $shopOwner->tuesday_open,
                'tuesday_close' => $shopOwner->tuesday_close,
                'wednesday_open' => $shopOwner->wednesday_open,
                'wednesday_close' => $shopOwner->wednesday_close,
                'thursday_open' => $shopOwner->thursday_open,
                'thursday_close' => $shopOwner->thursday_close,
                'friday_open' => $shopOwner->friday_open,
                'friday_close' => $shopOwner->friday_close,
                'saturday_open' => $shopOwner->saturday_open,
                'saturday_close' => $shopOwner->saturday_close,
                'sunday_open' => $shopOwner->sunday_open,
                'sunday_close' => $shopOwner->sunday_close,
            ],
        ]);
    }

    /**
     * Update shop profile information
     */
    public function update(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'business_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:shop_owners,email,' . $shopOwner->id,
            'phone' => 'sometimes|string|max:20',
            'bio' => 'nullable|string|max:1000',
            'country' => 'nullable|string|max:100',
            'city_state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'tax_id' => 'nullable|string|max:50',
            // Operating hours
            'monday_open' => 'nullable|date_format:H:i',
            'monday_close' => 'nullable|date_format:H:i',
            'tuesday_open' => 'nullable|date_format:H:i',
            'tuesday_close' => 'nullable|date_format:H:i',
            'wednesday_open' => 'nullable|date_format:H:i',
            'wednesday_close' => 'nullable|date_format:H:i',
            'thursday_open' => 'nullable|date_format:H:i',
            'thursday_close' => 'nullable|date_format:H:i',
            'friday_open' => 'nullable|date_format:H:i',
            'friday_close' => 'nullable|date_format:H:i',
            'saturday_open' => 'nullable|date_format:H:i',
            'saturday_close' => 'nullable|date_format:H:i',
            'sunday_open' => 'nullable|date_format:H:i',
            'sunday_close' => 'nullable|date_format:H:i',
        ]);

        // Validate that opening time is before closing time for each day
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            $openKey = $day . '_open';
            $closeKey = $day . '_close';
            
            if (isset($validated[$openKey]) && isset($validated[$closeKey])) {
                if ($validated[$openKey] >= $validated[$closeKey]) {
                    return back()->withErrors([
                        $openKey => ucfirst($day) . ' opening time must be before closing time.'
                    ]);
                }
            }
        }

        $shopOwner->update($validated);

        return back()->with('success', 'Profile updated successfully');
    }

    /**
     * Upload profile photo
     */
    public function uploadPhoto(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $request->validate([
            'profile_photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        try {
            // Delete old photo if exists
            if ($shopOwner->profile_photo && Storage::disk('public')->exists($shopOwner->profile_photo)) {
                Storage::disk('public')->delete($shopOwner->profile_photo);
            }

            // Store new photo
            $path = $request->file('profile_photo')->store('profile-photos', 'public');

            // Update shop owner record
            $shopOwner->update(['profile_photo' => $path]);

            return response()->json([
                'success' => true,
                'message' => 'Profile photo uploaded successfully',
                'profile_photo' => Storage::url($path),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload photo: ' . $e->getMessage(),
            ], 500);
        }
    }
}
