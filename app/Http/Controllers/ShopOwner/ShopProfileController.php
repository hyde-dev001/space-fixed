<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Services\CaviteLocationPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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
        $hasEstablishedYearColumn = Schema::hasColumn('shop_owners', 'established_year');
        $fallbackEstablishedYear = data_get($shopOwner->operating_hours, 'established_year');
        
        return Inertia::render('ShopOwner/Settings/shopProfile', [
            'shop_owner' => [
                'id' => $shopOwner->id,
                'first_name' => $shopOwner->first_name,
                'last_name' => $shopOwner->last_name,
                'name' => $shopOwner->name ?? $shopOwner->business_name,
                'business_name' => $shopOwner->business_name,
                'established_year' => $hasEstablishedYearColumn
                    ? $shopOwner->established_year
                    : ($fallbackEstablishedYear !== null ? (int) $fallbackEstablishedYear : null),
                'email' => $shopOwner->email,
                'phone' => $shopOwner->phone,
                'bio' => $shopOwner->bio,
                'country' => $shopOwner->country,
                'city_state' => $shopOwner->city_state,
                'postal_code' => $shopOwner->postal_code,
                'business_address' => $shopOwner->business_address,
                'shop_address' => $shopOwner->shop_address ?: $shopOwner->business_address,
                'tax_id' => $shopOwner->tax_id,
                'profile_photo' => $shopOwner->profile_photo,
                'cover_photo' => $shopOwner->cover_photo,
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
    public function update(Request $request, CaviteLocationPolicyService $caviteLocationPolicy)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        $hasEstablishedYearColumn = Schema::hasColumn('shop_owners', 'established_year');

        $rules = [
            'business_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:shop_owners,email,' . $shopOwner->id,
            'phone' => 'sometimes|string|max:20',
            'bio' => 'nullable|string|max:1000',
            'country' => 'nullable|string|max:100',
            'city_state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'tax_id' => 'nullable|string|max:50',
            'business_address' => 'sometimes|string|max:500',
            'shop_address' => 'sometimes|nullable|string|max:500',
            'shop_latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'shop_longitude' => 'sometimes|nullable|numeric|between:-180,180',
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
        ];

        if ($hasEstablishedYearColumn) {
            $rules['established_year'] = 'nullable|integer|min:1900|max:' . date('Y');
        }

        $validated = $request->validate($rules);

        if (!$hasEstablishedYearColumn && $request->has('established_year')) {
            $existingOperatingHours = is_array($shopOwner->operating_hours) ? $shopOwner->operating_hours : [];
            $inputEstablishedYear = $request->input('established_year');

            if ($inputEstablishedYear === null || $inputEstablishedYear === '') {
                unset($existingOperatingHours['established_year']);
            } else {
                $existingOperatingHours['established_year'] = (int) $inputEstablishedYear;
            }

            $validated['operating_hours'] = $existingOperatingHours;
            unset($validated['established_year']);
        }

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

        $touchesLocation = $request->hasAny([
            'business_address',
            'shop_address',
            'shop_latitude',
            'shop_longitude',
        ]);

        if ($touchesLocation) {
            $nextBusinessAddress = trim((string) ($validated['business_address'] ?? $shopOwner->business_address));
            $nextShopAddress = trim((string) ($validated['shop_address'] ?? $nextBusinessAddress));
            $nextLatitude = $request->has('shop_latitude') ? ($validated['shop_latitude'] ?? null) : $shopOwner->shop_latitude;
            $nextLongitude = $request->has('shop_longitude') ? ($validated['shop_longitude'] ?? null) : $shopOwner->shop_longitude;

            $locationPolicy = $caviteLocationPolicy->validateUpdateLocation(
                $nextLatitude,
                $nextLongitude,
                $nextBusinessAddress !== '' ? $nextBusinessAddress : $nextShopAddress,
                $request,
                $shopOwner,
                $shopOwner->id,
                [
                    'email' => $shopOwner->email,
                    'business_name' => $shopOwner->business_name,
                    'target_type' => 'shop_owner',
                    'target_id' => $shopOwner->id,
                ]
            );

            if (!$locationPolicy['allowed']) {
                return back()->withErrors($locationPolicy['errors'])->withInput();
            }

            $validated['business_address'] = $nextBusinessAddress;
            $validated['shop_address'] = $nextShopAddress;
            $validated['shop_latitude'] = $nextLatitude;
            $validated['shop_longitude'] = $nextLongitude;
        }

        $shopOwner->update($validated);

        return back()->with('success', 'Profile updated successfully');
    }

    /**
     * Upload profile photo
     */
    public function uploadPhoto(Request $request)
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $request->validate([
                'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:10240|required_without:cover_photo',
                'cover_photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:15360|required_without:profile_photo',
            ]);

            if ($request->hasFile('cover_photo')) {
                $this->ensureCoverPhotoColumnExists();

                if ($shopOwner->cover_photo && Storage::disk('public')->exists($shopOwner->cover_photo)) {
                    Storage::disk('public')->delete($shopOwner->cover_photo);
                }

                $path = $request->file('cover_photo')->store('cover-photos', 'public');
                $shopOwner->cover_photo = $path;
                $shopOwner->save();

                \Log::info('Cover photo uploaded', [
                    'shop_owner_id' => $shopOwner->id,
                    'path' => $path,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Cover photo uploaded successfully',
                    'cover_photo' => $path,
                ]);
            }

            if ($shopOwner->profile_photo && Storage::disk('public')->exists($shopOwner->profile_photo)) {
                Storage::disk('public')->delete($shopOwner->profile_photo);
            }

            $path = $request->file('profile_photo')->store('profile-photos', 'public');
            $shopOwner->profile_photo = $path;
            $shopOwner->save();

            \Log::info('Profile photo uploaded', [
                'shop_owner_id' => $shopOwner->id,
                'path' => $path,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile photo uploaded successfully',
                'profile_photo' => $path,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation error uploading photo', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error uploading profile photo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload photo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload cover photo
     */
    public function uploadCoverPhoto(Request $request)
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();

            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $request->validate([
                'cover_photo' => 'required|image|mimes:jpeg,png,jpg,webp|max:15360',
            ]);
            $this->ensureCoverPhotoColumnExists();

            if ($shopOwner->cover_photo && Storage::disk('public')->exists($shopOwner->cover_photo)) {
                Storage::disk('public')->delete($shopOwner->cover_photo);
            }

            $path = $request->file('cover_photo')->store('cover-photos', 'public');
            $shopOwner->cover_photo = $path;
            $shopOwner->save();

            return response()->json([
                'success' => true,
                'message' => 'Cover photo uploaded successfully',
                'cover_photo' => $path,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error uploading cover photo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload cover photo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove profile photo
     */
    public function removeProfilePhoto()
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();

            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            if ($shopOwner->profile_photo && Storage::disk('public')->exists($shopOwner->profile_photo)) {
                Storage::disk('public')->delete($shopOwner->profile_photo);
            }

            $shopOwner->profile_photo = null;
            $shopOwner->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile photo removed successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error removing profile photo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove profile photo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove cover photo
     */
    public function removeCoverPhoto()
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();

            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            if ($shopOwner->cover_photo && Storage::disk('public')->exists($shopOwner->cover_photo)) {
                Storage::disk('public')->delete($shopOwner->cover_photo);
            }

            $shopOwner->cover_photo = null;
            $shopOwner->save();

            return response()->json([
                'success' => true,
                'message' => 'Cover photo removed successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error removing cover photo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove cover photo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ensure legacy databases have the cover_photo column.
     */
    private function ensureCoverPhotoColumnExists(): void
    {
        if (!Schema::hasColumn('shop_owners', 'cover_photo')) {
            Schema::table('shop_owners', function (Blueprint $table) {
                $table->string('cover_photo')->nullable()->after('profile_photo');
            });
        }
    }
}
