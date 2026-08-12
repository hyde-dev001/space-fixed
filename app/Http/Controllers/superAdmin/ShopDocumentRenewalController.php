<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Enums\NotificationType;
use App\Enums\PrivilegedDeliveryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ReviewShopDocumentRenewalRequest;
use App\Models\Notification;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedMailDispatcher;
use App\Services\ShopDocumentLifecycleService;
use App\Services\ShopDocumentValidityService;
use App\Services\ShopOwnerDocumentRequirementService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class ShopDocumentRenewalController extends Controller
{
    public function __construct(
        private readonly ShopDocumentLifecycleService $lifecycle,
        private readonly ShopDocumentValidityService $validity,
        private readonly ShopOwnerDocumentRequirementService $requirements,
        private readonly PrivilegedAudit $audit,
        private readonly PrivilegedMailDispatcher $mailDispatcher,
    ) {}

    public function index(Request $request): JsonResponse|InertiaResponse
    {
        $perPage = $this->perPage($request->query('per_page'));
        $query = ShopDocument::query()
            ->with([
                'shopOwner:id,first_name,last_name,email,business_name,status',
                'predecessor',
            ])
            ->pendingRenewals()
            ->whereHas('shopOwner', fn ($ownerQuery) => $ownerQuery->where('status', 'approved'))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (is_numeric($request->query('document_id'))) {
            $query->whereKey((int) $request->query('document_id'));
        }

        $paginator = $query->paginate($perPage);
        $rows = collect($paginator->items())
            ->map(fn (ShopDocument $document): array => $this->serializeRenewal($document))
            ->values()
            ->all();
        $pagination = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $rows,
                'meta' => $pagination,
            ]);
        }

        return Inertia::render('superAdmin/Shops/DocumentRenewalQueue', [
            'renewals' => $rows,
            'pagination' => $pagination,
            'filters' => [
                'document_id' => $request->query('document_id'),
            ],
        ]);
    }

    public function approve(
        ReviewShopDocumentRenewalRequest $request,
        ShopDocument $document,
        PrivilegedFailureResponse $failures,
    ): JsonResponse|Response {
        $actor = Auth::guard('super_admin')->user();
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $outcome = DB::transaction(fn (): array => $this->approveWithinTransaction(
                $request,
                $actor,
                $document,
                $request->validated(),
            ));
        } catch (ConflictHttpException) {
            return $failures->conflict(
                request: $request,
                operation: 'shop_document_renewal',
                message: 'The document renewal conflicts with current state.',
                code: 'shop_document_renewal_conflict',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $failures->unexpected(
                request: $request,
                operation: 'shop_document_renewal',
                exception: $exception,
                message: 'The document renewal could not be approved.',
                code: 'shop_document_renewal_approval_error',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        }

        return $this->decisionResponse($request, $outcome, 'approved');
    }

    public function reject(
        ReviewShopDocumentRenewalRequest $request,
        ShopDocument $document,
        PrivilegedFailureResponse $failures,
    ): JsonResponse|Response {
        $actor = Auth::guard('super_admin')->user();
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $outcome = DB::transaction(fn (): array => $this->rejectWithinTransaction(
                $request,
                $actor,
                $document,
                (string) $request->validated('rejection_reason'),
            ));
        } catch (ConflictHttpException) {
            return $failures->conflict(
                request: $request,
                operation: 'shop_document_renewal',
                message: 'The document renewal conflicts with current state.',
                code: 'shop_document_renewal_conflict',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $failures->unexpected(
                request: $request,
                operation: 'shop_document_renewal',
                exception: $exception,
                message: 'The document renewal could not be rejected.',
                code: 'shop_document_renewal_rejection_error',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        }

        return $this->decisionResponse($request, $outcome, 'rejected');
    }

    /** @param array<string, mixed> $validated */
    private function approveWithinTransaction(
        Request $request,
        SuperAdmin $actor,
        ShopDocument $routeDocument,
        array $validated,
    ): array {
        [$owner, $candidate, $predecessor, $rows] = $this->lockRenewalContext($routeDocument);

        if ((string) $candidate->status === 'approved' && (bool) $candidate->is_current) {
            if ($this->approvalPayloadMatches($candidate, $validated)) {
                return ['applied' => false, 'document' => $candidate->fresh(['predecessor', 'shopOwner'])];
            }

            throw new ConflictHttpException('The document renewal was already approved with different metadata.');
        }

        if ((string) $candidate->status !== 'pending' || (bool) $candidate->is_current) {
            throw new ConflictHttpException('Only a pending non-current renewal can be approved.');
        }

        $this->assertCurrentPredecessor($predecessor, $rows, $candidate);
        if (! $this->requirements->hasPrivateStoredFile($candidate)) {
            throw new ConflictHttpException('The pending document file is unavailable.');
        }

        $metadata = [
            ...$this->approvalMetadata($candidate, $validated),
            'submitted_document_type' => (string) $candidate->document_type,
            'submitted_issued_on' => $candidate->issued_on?->toDateString(),
            'submitted_expiration_mode' => $candidate->expiration_mode,
            'submitted_expires_on' => $candidate->expires_on?->toDateString(),
        ];
        $approved = $this->lifecycle->approvePendingVersion($candidate, (int) $actor->getKey(), $metadata);
        $predecessor = $predecessor?->fresh();

        $this->audit->shopDocumentRenewalApproved(
            request: $request,
            actor: $actor,
            document: $approved,
            shopOwner: $owner,
            predecessor: $predecessor,
            metadata: $metadata,
        );
        $this->notifyOwner($request, $owner, $approved, 'approved', null);

        return ['applied' => true, 'document' => $approved->fresh(['predecessor', 'shopOwner'])];
    }

    private function rejectWithinTransaction(
        Request $request,
        SuperAdmin $actor,
        ShopDocument $routeDocument,
        string $reason,
    ): array {
        [$owner, $candidate, $predecessor, $rows] = $this->lockRenewalContext($routeDocument);

        if ((string) $candidate->status === 'rejected') {
            if ((string) $candidate->rejection_reason === $reason) {
                return ['applied' => false, 'document' => $candidate->fresh(['predecessor', 'shopOwner'])];
            }

            throw new ConflictHttpException('The document renewal was already rejected with a different reason.');
        }

        $this->assertCurrentPredecessor($predecessor, $rows, $candidate);

        if ((string) $candidate->status !== 'pending' || (bool) $candidate->is_current) {
            throw new ConflictHttpException('Only a pending non-current renewal can be rejected.');
        }

        $rejected = $this->lifecycle->rejectPendingVersion($candidate, (int) $actor->getKey(), $reason);
        $this->audit->shopDocumentRenewalRejected(
            request: $request,
            actor: $actor,
            document: $rejected,
            shopOwner: $owner,
            predecessor: $predecessor,
            reason: $reason,
        );
        $this->notifyOwner($request, $owner, $rejected, 'rejected', $reason);

        return ['applied' => true, 'document' => $rejected->fresh(['predecessor', 'shopOwner'])];
    }

    /** @return array{0: ShopOwner, 1: ShopDocument, 2: ?ShopDocument, 3: \Illuminate\Database\Eloquent\Collection<int, ShopDocument>} */
    private function lockRenewalContext(ShopDocument $routeDocument): array
    {
        $owner = ShopOwner::query()->lockForUpdate()->find($routeDocument->shop_owner_id);
        if (! $owner instanceof ShopOwner || $this->statusValue($owner->status) !== 'approved') {
            throw new ConflictHttpException('The shop owner is not currently approved.');
        }

        $rows = ShopDocument::query()
            ->whereBelongsTo($owner)
            ->where(function ($query) use ($routeDocument): void {
                $query->where('logical_slot', $routeDocument->logical_slot)
                    ->orWhere('id', $routeDocument->getKey())
                    ->orWhere('id', $routeDocument->predecessor_document_id);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $candidate = $rows->firstWhere('id', $routeDocument->getKey());

        if (! $candidate instanceof ShopDocument
            || trim((string) $candidate->logical_slot) === ''
            || $candidate->predecessor_document_id === null) {
            throw new ConflictHttpException('The requested document is not a renewal candidate.');
        }

        $predecessor = $rows->firstWhere('id', $candidate->predecessor_document_id);
        if (! $predecessor instanceof ShopDocument) {
            throw new ConflictHttpException('The renewal predecessor is unavailable.');
        }

        return [$owner, $candidate, $predecessor, $rows];
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, ShopDocument> $rows */
    private function assertCurrentPredecessor(?ShopDocument $predecessor, $rows, ShopDocument $candidate): void
    {
        if (! $predecessor instanceof ShopDocument
            || (int) $predecessor->shop_owner_id !== (int) $candidate->shop_owner_id
            || (string) $predecessor->status !== 'approved'
            || ! (bool) $predecessor->is_current) {
            throw new ConflictHttpException('The renewal predecessor is no longer current and approved.');
        }

        $competingCurrent = $rows->first(
            fn (ShopDocument $row): bool => (int) $row->id !== (int) $candidate->id
                && (int) $row->id !== (int) $predecessor->id
                && (string) $row->status === 'approved'
                && (bool) $row->is_current,
        );
        if ($competingCurrent instanceof ShopDocument) {
            throw new ConflictHttpException('Another current approved document already occupies this slot.');
        }
    }

    /** @param array<string, mixed> $validated */
    private function approvalMetadata(ShopDocument $candidate, array $validated): array
    {
        $declaredType = $this->requirements->normalizeType((string) $validated['document_type']);
        $candidateType = $this->requirements->normalizeType((string) $candidate->document_type);
        $slot = (string) $candidate->logical_slot;

        if ((string) $validated['logical_slot'] !== $slot
            || (int) $validated['version_number'] !== (int) $candidate->version_number
            || ! $this->isAllowedReviewType($slot, $candidateType, $declaredType)) {
            throw new ConflictHttpException('The reviewer metadata does not match the renewal candidate.');
        }

        $metadata = [
            'document_type' => $declaredType,
            'logical_slot' => $slot,
            'version_number' => (int) $candidate->version_number,
            'issued_on' => $validated['issued_on'] ?? null,
            'expiration_mode' => (string) $validated['expiration_mode'],
            'expires_on' => $validated['expires_on'] ?? null,
        ];
        $errors = $this->requirements->validateDocumentMetadata($metadata);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $metadata;
    }

    /** @param array<string, mixed> $validated */
    private function approvalPayloadMatches(ShopDocument $candidate, array $validated): bool
    {
        try {
            $metadata = $this->approvalMetadata($candidate, $validated);
        } catch (ValidationException|ConflictHttpException) {
            return false;
        }

        return (string) $candidate->document_type === $metadata['document_type']
            && (string) ($candidate->issued_on?->toDateString() ?? '') === (string) ($metadata['issued_on'] ?? '')
            && (string) ($candidate->expiration_mode ?? '') === (string) $metadata['expiration_mode']
            && (string) ($candidate->expires_on?->toDateString() ?? '') === (string) ($metadata['expires_on'] ?? '');
    }

    private function isAllowedReviewType(string $slot, string $candidateType, string $declaredType): bool
    {
        if ($slot === 'business_registration') {
            return in_array($candidateType, ['dti_registration', 'sec_registration'], true)
                && in_array($declaredType, ['dti_registration', 'sec_registration'], true);
        }

        return $candidateType === $declaredType && $this->requirements->slotForType($declaredType) === $slot;
    }

    private function notifyOwner(
        Request $request,
        ShopOwner $owner,
        ShopDocument $document,
        string $decision,
        ?string $reason,
    ): void {
        $groupKey = 'shop-document-renewal-reviewed:'.$document->getKey().':'.$decision;
        Notification::firstOrCreate(
            [
                'shop_owner_id' => $owner->getKey(),
                'type' => NotificationType::SHOP_DOCUMENT_RENEWAL_REVIEWED->value,
                'group_key' => $groupKey,
            ],
            [
                'title' => 'Document renewal '.$decision,
                'message' => $owner->business_name.' document renewal for '.$document->logical_slot.' was '.$decision.'.',
                'action_url' => route('shop-owner.settings'),
                'data' => [
                    'document_id' => (int) $document->getKey(),
                    'shop_owner_id' => (int) $owner->getKey(),
                    'logical_slot' => (string) $document->logical_slot,
                    'decision' => $decision,
                    'decision_reason' => $reason,
                ],
                'is_read' => false,
                'requires_action' => false,
                'is_archived' => false,
                'priority' => 'medium',
            ],
        );

        $this->mailDispatcher->dispatch(
            type: PrivilegedDeliveryType::SHOP_DOCUMENT_RENEWAL_REVIEWED,
            businessEventId: 'shop-document-renewal-reviewed:'.$document->getKey().':'.$decision,
            recipientType: 'shop_owner',
            recipientId: (int) $owner->getKey(),
            payload: [
                'document_id' => (int) $document->getKey(),
                'shop_owner_id' => (int) $owner->getKey(),
                'business_name' => (string) $owner->business_name,
                'logical_slot' => (string) $document->logical_slot,
                'decision' => $decision,
                'decision_reason' => $reason,
            ],
            correlationId: $this->audit->correlationId($request),
        );
    }

    /** @param array{applied: bool, document: ShopDocument} $outcome */
    private function decisionResponse(Request $request, array $outcome, string $decision): JsonResponse|Response
    {
        $message = $outcome['applied']
            ? 'Document renewal '.$decision.'.'
            : 'Document renewal was already '.$decision.'.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'applied' => $outcome['applied'],
                'message' => $message,
                'document' => $this->serializeDocument($outcome['document']),
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    /** @return array<string, mixed> */
    private function serializeRenewal(ShopDocument $document): array
    {
        $owner = $document->shopOwner;

        return [
            ...$this->serializeDocument($document),
            'created_at' => $document->created_at?->toISOString(),
            'owner' => [
                'id' => (int) $owner?->getKey(),
                'business_name' => (string) ($owner?->business_name ?? ''),
                'name' => trim((string) (($owner?->first_name ?? '').' '.($owner?->last_name ?? ''))),
                'email' => (string) ($owner?->email ?? ''),
                'status' => $this->statusValue($owner?->status),
            ],
            'predecessor' => $document->predecessor ? $this->serializeDocument($document->predecessor) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDocument(ShopDocument $document): array
    {
        $ownerId = (int) $document->shop_owner_id;

        return [
            'id' => (int) $document->getKey(),
            'document_type' => (string) $document->document_type,
            'logical_slot' => (string) $document->logical_slot,
            'version_number' => $document->version_number !== null ? (int) $document->version_number : null,
            'status' => (string) $document->status,
            'issued_on' => $document->issued_on?->toDateString(),
            'expiration_mode' => $document->expiration_mode,
            'expires_on' => $document->expires_on?->toDateString(),
            'validity' => $this->validity->classify($document),
            'url' => route('admin.shop-documents.show', [
                'shopOwner' => $ownerId,
                'document' => $document->getKey(),
            ]),
        ];
    }

    private function perPage(mixed $raw): int
    {
        $value = is_numeric($raw) ? (int) $raw : 20;

        return max(1, min(100, $value));
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
