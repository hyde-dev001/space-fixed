<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Notifications\ShopOwnerApproved;
use App\Notifications\ShopOwnerRejected;
use App\Services\ShopModuleProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ShopOwnerRegistrationViewController extends Controller
{
    public function __construct(
        private readonly ShopModuleProvisioningService $shopModuleProvisioning,
    ) {}

    public function index(): Response
    {
        $shopOwners = ShopOwner::with('documents')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($shopOwner) {
                $documents = $shopOwner->documents->map(function ($doc) use ($shopOwner) {
                    return [
                        'url' => route('admin.shop-documents.show', [
                            'shopOwner' => $shopOwner->id,
                            'document' => $doc->id,
                        ]),
                        'type' => $doc->document_type,
                    ];
                })->values();

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
                    'documents' => $documents->toArray(),
                    'documentUrls' => $documents->pluck('url')->toArray(),
                    'status' => $shopOwner->status,
                    'createdAt' => $shopOwner->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();

        return Inertia::render('superAdmin/Shops/ShopOwnerRegistrationView', [
            'registrations' => $shopOwners,
        ]);
    }

    public function approve(Request $request, $id)
    {
        // Generate a unique token for password setup
        $token = Str::random(64);

        $shopOwner = DB::transaction(function () use ($id, $token): ShopOwner {
            $shopOwner = ShopOwner::query()->lockForUpdate()->findOrFail($id);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $shopOwner->email],
                [
                    'email' => $shopOwner->email,
                    'token' => hash('sha256', $token),
                    'created_at' => now(),
                ]
            );

            $shopOwner->update(['status' => 'approved']);
            $this->shopModuleProvisioning->initializeMissing(
                $shopOwner,
                $this->shopModuleProvisioning->eligibleKeysFor($shopOwner),
            );

            return $shopOwner->fresh();
        });

        // Send approval notification with password setup link
        $shopOwner->notify(new ShopOwnerApproved($shopOwner, $token));

        // If this is an XHR/JSON request (e.g., fetch), return JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Shop owner registration approved successfully. Password setup email sent.',
            ]);
        }

        // For Inertia form submissions, redirect back with flash message.
        return redirect()->back()->with('success', 'Shop owner registration approved successfully. Password setup email sent.');
    }

    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        $shopOwner = ShopOwner::findOrFail($id);
        $shopOwner->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ]);

        // Send rejection notification
        $shopOwner->notify(new ShopOwnerRejected($shopOwner, $validated['rejection_reason'] ?? null));

        // If this is an XHR/JSON request (e.g., fetch), return JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Shop owner registration rejected successfully. Notification email sent.',
            ]);
        }

        // For Inertia form submissions, redirect back with flash message.
        return redirect()->back()->with('success', 'Shop owner registration rejected successfully. Notification email sent.');
    }
}
