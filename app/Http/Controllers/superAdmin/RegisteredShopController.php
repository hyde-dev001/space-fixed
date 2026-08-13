<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Concerns\RespondsToAccountLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\AccountArchiveRequest;
use App\Http\Requests\SuperAdmin\AccountReactivationRequest;
use App\Http\Requests\SuperAdmin\AccountRestoreRequest;
use App\Http\Requests\SuperAdmin\AccountSuspensionRequest;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\AccountLifecycleService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RegisteredShopController extends Controller
{
    use RespondsToAccountLifecycle;

    public function __construct(
        private readonly AccountLifecycleService $accountLifecycle,
        private readonly PrivilegedFailureResponse $failures,
    ) {
    }

    public function index(Request $request): Response
    {
        $lifecycle = $request->query('lifecycle', 'all');
        if (! in_array($lifecycle, ['active', 'archived', 'all'], true)) {
            $lifecycle = 'all';
        }

        $shopsQuery = ShopOwner::withTrashed()
            ->whereIn('status', ['approved', 'suspended']);

        if ($lifecycle === 'active') {
            $shopsQuery->whereNull('deleted_at');
        } elseif ($lifecycle === 'archived') {
            $shopsQuery->whereNotNull('deleted_at');
        }

        $shops = $shopsQuery
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (ShopOwner $shopOwner): array {
                $accountStatus = $shopOwner->status;
                $archived = $shopOwner->trashed();

                return [
                    'id' => $shopOwner->id,
                    'first_name' => $shopOwner->first_name,
                    'last_name' => $shopOwner->last_name,
                    'fullName' => $shopOwner->full_name,
                    'email' => $shopOwner->email,
                    'contact_number' => $shopOwner->phone,
                    'phone' => $shopOwner->phone,
                    'business_name' => $shopOwner->business_name,
                    'business_address' => $shopOwner->business_address,
                    'business_type' => $shopOwner->business_type,
                    'registration_type' => $shopOwner->registration_type,
                    'status' => $archived ? 'archived' : $accountStatus,
                    'accountStatus' => $accountStatus,
                    'archived' => $archived,
                    'suspension_reason' => $shopOwner->suspension_reason,
                    'created_at' => $shopOwner->created_at->format('Y-m-d H:i:s'),
                    'approved_at' => $shopOwner->updated_at->format('Y-m-d H:i:s'),
                ];
            });

        $stats = [
            'total' => $shops->count(),
            'active' => $shops->where('accountStatus', 'approved')->where('archived', false)->count(),
            'suspended' => $shops->where('accountStatus', 'suspended')->where('archived', false)->count(),
            'archived' => $shops->where('archived', true)->count(),
            'thisMonth' => (clone $shopsQuery)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];

        return Inertia::render('superAdmin/Shops/RegisteredShops', [
            'shops' => $shops,
            'stats' => $stats,
        ]);
    }

    public function show(int $shopOwner): JsonResponse
    {
        $model = ShopOwner::query()
            ->withTrashed()
            ->with('documents')
            ->findOrFail($shopOwner);

        $accountStatus = $model->status;
        $archived = $model->trashed();

        return response()->json([
            'shop' => [
                'id' => $model->id,
                'first_name' => $model->first_name,
                'last_name' => $model->last_name,
                'fullName' => $model->full_name,
                'email' => $model->email,
                'contact_number' => $model->phone,
                'phone' => $model->phone,
                'business_name' => $model->business_name,
                'business_address' => $model->business_address,
                'business_type' => $model->business_type,
                'registration_type' => $model->registration_type,
                'operating_hours' => is_array($model->operating_hours) ? $model->operating_hours : [],
                'status' => $archived ? 'archived' : $accountStatus,
                'accountStatus' => $accountStatus,
                'archived' => $archived,
                'suspension_reason' => $model->suspension_reason,
                'created_at' => $model->created_at->format('Y-m-d H:i:s'),
                'approved_at' => $model->updated_at->format('Y-m-d H:i:s'),
                'documentUrls' => $model->documents->map(
                    fn ($document): string => route('admin.shop-documents.show', [
                        'shopOwner' => $model->id,
                        'document' => $document->id,
                    ]),
                )->toArray(),
            ],
        ]);
    }

    public function suspend(AccountSuspensionRequest $request, int $shopOwner)
    {
        return $this->respondToAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->suspend(
                'shop',
                $shopOwner,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('suspension_reason'),
            ),
            successMessage: 'Shop suspended successfully.',
            failures: $this->failures,
        );
    }

    public function reactivate(AccountReactivationRequest $request, int $shopOwner)
    {
        return $this->respondToAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->reactivate(
                'shop',
                $shopOwner,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('reactivation_reason'),
            ),
            successMessage: 'Shop reactivated successfully.',
            failures: $this->failures,
        );
    }

    public function archive(AccountArchiveRequest $request, int $shopOwner)
    {
        return $this->respondToAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->archive(
                'shop',
                $shopOwner,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('archive_reason'),
            ),
            successMessage: 'Shop archived successfully.',
            failures: $this->failures,
        );
    }

    public function restore(AccountRestoreRequest $request, int $shopOwner)
    {
        return $this->respondToAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->restore(
                'shop',
                $shopOwner,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('restore_reason'),
            ),
            successMessage: 'Shop restored successfully.',
            failures: $this->failures,
        );
    }

    private function currentPrivilegedActor(): SuperAdmin
    {
        $actor = auth('super_admin')->user();

        abort_unless($actor instanceof SuperAdmin, 403);

        return $actor;
    }
}
