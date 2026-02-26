<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Notifications\ShopOwnerApproved;
use App\Notifications\ShopOwnerRejected;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ShopOwnerRegistrationViewController extends Controller
{
    public function index(): Response
    {
        $shopOwners = ShopOwner::with('documents')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($shopOwner) {
                return [
                    'id' => $shopOwner->id,
                    'firstName' => $shopOwner->first_name,
                    'lastName' => $shopOwner->last_name,
                    'email' => $shopOwner->email,
                    'phone' => $shopOwner->phone,
                    'businessName' => $shopOwner->business_name,
                    'businessAddress' => $shopOwner->business_address,
                    'businessType' => $shopOwner->business_type,
                    'registrationType' => $shopOwner->registration_type,
                    'serviceType' => $shopOwner->business_type,
                    'operatingHours' => is_array($shopOwner->operating_hours) ? $shopOwner->operating_hours : [],
                    'documentUrls' => $shopOwner->documents->map(function ($doc) {
                        return '/storage/' . $doc->file_path;
                    })->toArray(),
                    'status' => $shopOwner->status,
                    'createdAt' => $shopOwner->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();

        return Inertia::render('superAdmin/Shops/ShopOwnerRegistrationView', [
            'registrations' => $shopOwners
        ]);
    }

    public function approve(Request $request, $id)
    {
        $shopOwner = ShopOwner::findOrFail($id);
        
        // Generate a unique token for password setup
        $token = Str::random(64);
        
        // Update shop owner status and store the token
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $shopOwner->email],
            [
                'email' => $shopOwner->email,
                'token' => hash('sha256', $token),
                'created_at' => now()
            ]
        );
        
        $shopOwner->update(['status' => 'approved']);
        
        // Send approval notification with password setup link
        $shopOwner->notify(new ShopOwnerApproved($shopOwner, $token));

        // If this is an XHR/JSON request (e.g., fetch), return JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Shop owner registration approved successfully. Password setup email sent.'
            ]);
        }

        // For Inertia form submissions, redirect back with flash message.
        return redirect()->back()->with('success', 'Shop owner registration approved successfully. Password setup email sent.');
    }

    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:500'
        ]);

        $shopOwner = ShopOwner::findOrFail($id);
        $shopOwner->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'] ?? null
        ]);
        
        // Send rejection notification
        $shopOwner->notify(new ShopOwnerRejected($shopOwner, $validated['rejection_reason'] ?? null));

        // If this is an XHR/JSON request (e.g., fetch), return JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Shop owner registration rejected successfully. Notification email sent.'
            ]);
        }

        // For Inertia form submissions, redirect back with flash message.
        return redirect()->back()->with('success', 'Shop owner registration rejected successfully. Notification email sent.');
    }
}
