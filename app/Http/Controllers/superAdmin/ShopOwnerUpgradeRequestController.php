<?php

namespace App\Http\Controllers\superAdmin;

use App\Actions\superAdmin\ReviewShopOwnerUpgradeRequest;
use App\Exceptions\ShopOwnerUpgradeReviewConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\superAdmin\ReviewShopOwnerUpgradeRequest as ReviewRequest;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\ShopOwnerUpgradeRequestDocument;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

final class ShopOwnerUpgradeRequestController extends Controller
{
    public function index(ReviewRequest $request): JsonResponse|InertiaResponse
    {
        $validated = $request->validated();
        $query = ShopOwnerUpgradeRequest::query()
            ->select([
                'id',
                'shop_owner_id',
                'current_registration_type',
                'current_business_type',
                'requested_registration_type',
                'requested_business_type',
                'status',
                'decision_reason',
                'reviewed_by_super_admin_id',
                'reviewed_at',
                'created_at',
            ])
            ->with([
                'shopOwner:id,first_name,last_name,business_name,email',
                'reviewedBySuperAdmin:id,first_name,last_name',
                'documents:id,shop_owner_upgrade_request_id,document_type,mime_type,size,source_status',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('id', is_numeric($search) ? (int) $search : -1)
                    ->orWhereHas('shopOwner', function ($ownerQuery) use ($search): void {
                        $ownerQuery->where(function ($ownerSearch) use ($search): void {
                            $ownerSearch
                                ->where('business_name', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    });
            });
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 20));
        $items = collect($paginator->items())->map(fn (ShopOwnerUpgradeRequest $upgradeRequest): array => $this->serializeRequest($upgradeRequest))->values();

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $items,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]);
        }

        return Inertia::render('superAdmin/Shops/BusinessUpgradeRequests', [
            'requests' => $items,
            'filters' => [
                'status' => $validated['status'] ?? null,
                'search' => $validated['search'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function update(
        ReviewRequest $request,
        ShopOwnerUpgradeRequest $upgradeRequest,
        ReviewShopOwnerUpgradeRequest $review,
        PrivilegedFailureResponse $failures,
    ): JsonResponse|Response
    {
        $validated = $request->validated();
        $reviewer = Auth::guard('super_admin')->user();

        try {
            $result = $review->handle(
                upgradeRequest: $upgradeRequest,
                reviewer: $reviewer,
                decision: (string) $validated['decision'],
                decisionReason: $validated['decision_reason'] ?? null,
                request: $request,
            );
        } catch (ShopOwnerUpgradeReviewConflict $exception) {
            return $failures->conflict(
                request: $request,
                operation: 'shop_owner_upgrade',
                message: 'The upgrade request conflicts with current state.',
                code: 'shop_owner_upgrade_conflict',
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $failures->unexpected(
                request: $request,
                operation: 'shop_owner_upgrade',
                exception: $exception,
                message: 'The upgrade request could not be reviewed. Please try again.',
                code: 'shop_owner_upgrade_error',
            );
        }

        $status = $result['conflict'] ? 409 : 200;
        if ($request->expectsJson()) {
            return response()->json([
                'success' => ! $result['conflict'],
                'message' => $result['conflict']
                    ? 'The request was superseded because the account changed before review.'
                    : 'Business upgrade request reviewed successfully.',
                'request' => $this->serializeRequest($result['request']),
                'newly_enabled_module_keys' => $result['newly_enabled_module_keys'],
                'dormant_employee_permission_warning' => $result['dormant_employee_permission_warning'],
            ], $status);
        }

        return back()->with('success', 'Business upgrade request reviewed successfully.');
    }

    public function download(ShopOwnerUpgradeRequest $upgradeRequest, ShopOwnerUpgradeRequestDocument $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $document = $upgradeRequest->documents()->whereKey($document->id)->firstOrFail();
        $disk = (string) $document->disk;
        $path = (string) $document->getRawOriginal('path');

        if ($disk !== 'local' || $path === '' || ! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $filename = (string) $document->document_type.'.'.$extension;

        return Storage::disk($disk)->download($path, $filename, [
            'Content-Type' => (string) $document->mime_type,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRequest(ShopOwnerUpgradeRequest $request): array
    {
        return [
            'id' => (int) $request->id,
            'status' => (string) $request->status,
            'current_registration_type' => $request->current_registration_type,
            'current_business_type' => $request->current_business_type,
            'requested_registration_type' => $request->requested_registration_type,
            'requested_business_type' => $request->requested_business_type,
            'decision_reason' => $request->decision_reason,
            'reviewed_at' => $request->reviewed_at?->toISOString(),
            'created_at' => $request->created_at?->toISOString(),
            'shop_owner' => [
                'id' => (int) $request->shopOwner?->id,
                'business_name' => (string) ($request->shopOwner?->business_name ?? ''),
                'name' => trim((string) (($request->shopOwner?->first_name ?? '').' '.($request->shopOwner?->last_name ?? ''))),
                'email' => (string) ($request->shopOwner?->email ?? ''),
            ],
            'reviewed_by' => $request->reviewedBySuperAdmin ? [
                'id' => (int) $request->reviewedBySuperAdmin->id,
                'name' => trim($request->reviewedBySuperAdmin->first_name.' '.$request->reviewedBySuperAdmin->last_name),
            ] : null,
            'documents' => $request->documents->map(fn (ShopOwnerUpgradeRequestDocument $document): array => [
                'id' => (int) $document->id,
                'document_type' => $document->document_type,
                'mime_type' => $document->mime_type,
                'size' => (int) $document->size,
                'source_status' => $document->source_status,
                'download_url' => route('admin.business-upgrade-requests.documents.download', [$request, $document]),
            ])->values()->all(),
        ];
    }
}
