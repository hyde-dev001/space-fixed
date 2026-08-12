<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\RejectShopOwnerRegistrationRequest;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\ShopOwnerRegistrationDecisionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShopOwnerRegistrationViewController extends Controller
{
    public function __construct(
        private readonly ShopOwnerRegistrationDecisionService $registrationDecisions,
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
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $outcome = $this->registrationDecisions->approve($request, $actor, (int) $id);
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception->getMessage());
        }

        // If this is an XHR/JSON request (e.g., fetch), return JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'applied' => $outcome['applied'],
                'notification_failed' => $outcome['notification_failed'],
                'message' => $outcome['applied']
                    ? ($outcome['notification_failed']
                        ? 'Shop owner registration approved, but the password setup notification could not be queued.'
                        : 'Shop owner registration approved successfully. Password setup notification queued.')
                    : 'Shop owner registration was already approved.',
            ]);
        }

        // For Inertia form submissions, redirect back with flash message.
        return redirect()->back()->with('success', $outcome['applied']
            ? ($outcome['notification_failed']
                ? 'Shop owner registration approved, but the password setup notification could not be queued.'
                : 'Shop owner registration approved successfully. Password setup notification queued.')
            : 'Shop owner registration was already approved.');
    }

    public function reject(RejectShopOwnerRegistrationRequest $request, $id)
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $outcome = $this->registrationDecisions->reject(
                $request,
                $actor,
                (int) $id,
                (string) $request->validated('rejection_reason'),
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception->getMessage());
        }

        // If this is an XHR/JSON request (e.g., fetch), return JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'applied' => $outcome['applied'],
                'notification_failed' => $outcome['notification_failed'],
                'message' => $outcome['applied']
                    ? ($outcome['notification_failed']
                        ? 'Shop owner registration rejected, but the rejection notification could not be queued.'
                        : 'Shop owner registration rejected successfully. Rejection notification queued.')
                    : 'Shop owner registration was already rejected with the same reason.',
            ]);
        }

        // For Inertia form submissions, redirect back with flash message.
        return redirect()->back()->with('success', $outcome['applied']
            ? ($outcome['notification_failed']
                ? 'Shop owner registration rejected, but the rejection notification could not be queued.'
                : 'Shop owner registration rejected successfully. Rejection notification queued.')
            : 'Shop owner registration was already rejected with the same reason.');
    }

    private function conflictResponse(Request $request, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 409);
        }

        return redirect()->back()->withErrors(['registration' => $message]);
    }
}
