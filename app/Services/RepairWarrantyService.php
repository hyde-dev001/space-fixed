<?php

namespace App\Services;

use App\Models\RepairRequest;
use App\Models\RepairWarrantyClaim;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RepairWarrantyService
{
    private const DEFAULT_WARRANTY_DAYS = 30;
    private const MIN_WARRANTY_DAYS = 1;
    private const MAX_WARRANTY_DAYS = 90;
    private const MAX_EVIDENCE_IMAGES = 10;

    public function __construct(
        private ?NotificationService $notificationService = null
    ) {
        $this->notificationService ??= app(NotificationService::class);
    }

    /**
     * @return array{warranty_started_at: Carbon, warranty_expires_at: Carbon, warranty_days: int}
     */
    public function validateEligibility(RepairRequest $originalRepair, ?int $customerUserId = null, ?int $ignoreClaimId = null): array
    {
        if ((bool) ($originalRepair->is_warranty_job ?? false)) {
            throw ValidationException::withMessages([
                'repair' => ['Warranty claims must be filed against the original repair request.'],
            ]);
        }

        $shopOwner = $originalRepair->shopOwner ?: ShopOwner::query()->find($originalRepair->shop_owner_id);
        if (!$shopOwner) {
            throw ValidationException::withMessages([
                'repair' => ['Shop context could not be resolved for this repair request.'],
            ]);
        }

        if ((bool) ($shopOwner->warranty_enabled ?? true) === false) {
            throw ValidationException::withMessages([
                'repair' => ['Warranty claims are currently disabled for this shop.'],
            ]);
        }

        if ($customerUserId !== null && (int) ($originalRepair->user_id ?? 0) !== $customerUserId) {
            throw ValidationException::withMessages([
                'repair' => ['You are not allowed to file a warranty claim for this repair request.'],
            ]);
        }

        $eligibleStatuses = ['picked_up', 'received'];
        if (!in_array((string) $originalRepair->status, $eligibleStatuses, true)) {
            throw ValidationException::withMessages([
                'repair' => ['Warranty claims can only be filed after pickup/receipt confirmation.'],
            ]);
        }

        $windowStart = $this->resolveWarrantyWindowStart($originalRepair);
        if (!$windowStart) {
            throw ValidationException::withMessages([
                'repair' => ['Warranty start date is not available for this repair request.'],
            ]);
        }

        $days = (int) ($shopOwner->repair_warranty_days ?? self::DEFAULT_WARRANTY_DAYS);
        $days = max(self::MIN_WARRANTY_DAYS, min(self::MAX_WARRANTY_DAYS, $days));

        $windowExpiry = $windowStart->copy()->addDays($days)->endOfDay();
        if (now()->greaterThan($windowExpiry)) {
            throw ValidationException::withMessages([
                'repair' => ['Warranty period has already expired for this repair request.'],
            ]);
        }

        $existingClaims = RepairWarrantyClaim::query()
            ->where('original_repair_request_id', $originalRepair->id)
            ->when($ignoreClaimId && $ignoreClaimId > 0, function ($query) use ($ignoreClaimId) {
                $query->where('id', '!=', $ignoreClaimId);
            })
            ->orderByDesc('id')
            ->get(['id', 'status', 'approved_once_guard']);

        $hasApprovedClaim = $existingClaims->contains(function (RepairWarrantyClaim $claim): bool {
            return (string) $claim->status === RepairWarrantyClaim::STATUS_APPROVED
                || (int) ($claim->approved_once_guard ?? 0) === 1;
        });

        if ($hasApprovedClaim) {
            throw ValidationException::withMessages([
                'repair' => ['Warranty claim has already been used for this repair request.'],
            ]);
        }

        $hasPendingClaim = $existingClaims->contains(function (RepairWarrantyClaim $claim): bool {
            return (string) $claim->status === RepairWarrantyClaim::STATUS_PENDING_REPAIRER;
        });

        if ($hasPendingClaim) {
            throw ValidationException::withMessages([
                'repair' => ['A warranty claim is already pending review for this repair request.'],
            ]);
        }

        $latestClaim = $existingClaims->first();
        if ($latestClaim) {
            $latestStatus = (string) $latestClaim->status;

            // Re-submission policy: allow only after a rejected claim while warranty is still valid.
            if ($latestStatus !== RepairWarrantyClaim::STATUS_REJECTED) {
                throw ValidationException::withMessages([
                    'repair' => ['Warranty claim cannot be re-filed unless the previous claim was rejected.'],
                ]);
            }
        }

        return [
            'warranty_started_at' => $windowStart,
            'warranty_expires_at' => $windowExpiry,
            'warranty_days' => $days,
        ];
    }

    /**
     * @param UploadedFile[] $images
     */
    public function createCustomerClaim(RepairRequest $repair, User $customer, array $validated, array $images): RepairWarrantyClaim
    {
        if ((int) ($repair->user_id ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'repair' => ['This repair request is not linked to a registered customer account.'],
            ]);
        }

        $window = $this->validateEligibility($repair, (int) $customer->id);

        return $this->createClaimRecord(
            repair: $repair,
            validated: $validated,
            images: $images,
            window: $window,
            sourceChannel: 'customer_portal',
            actorId: (int) $customer->id,
            customerUserId: (int) $customer->id,
        );
    }

    /**
     * @param UploadedFile[] $images
     */
    public function createPosWalkInClaim(RepairRequest $repair, array $validated, array $images, int $actorId): RepairWarrantyClaim
    {
        if (!$this->isManualPosRepair($repair)) {
            throw ValidationException::withMessages([
                'repair' => ['POS walk-in warranty claims are only allowed for manual POS walk-in repairs.'],
            ]);
        }

        $window = $this->validateEligibility($repair, null);

        return $this->createClaimRecord(
            repair: $repair,
            validated: $validated,
            images: $images,
            window: $window,
            sourceChannel: 'manual_pos_walk_in',
            actorId: $actorId,
            customerUserId: null,
        );
    }

    public function approveClaim(RepairWarrantyClaim $claim, int $actorId): RepairWarrantyClaim
    {
        return DB::transaction(function () use ($claim, $actorId) {
            /** @var RepairWarrantyClaim $lockedClaim */
            $lockedClaim = RepairWarrantyClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if ((string) $lockedClaim->status !== RepairWarrantyClaim::STATUS_PENDING_REPAIRER) {
                throw ValidationException::withMessages([
                    'claim' => ['This warranty claim is no longer pending for review.'],
                ]);
            }

            if ($lockedClaim->warranty_expires_at_snapshot && now()->greaterThan($lockedClaim->warranty_expires_at_snapshot)) {
                $lockedClaim->forceFill([
                    'status' => RepairWarrantyClaim::STATUS_EXPIRED,
                ])->save();

                throw ValidationException::withMessages([
                    'claim' => ['Warranty claim expired before approval.'],
                ]);
            }

            /** @var RepairRequest $original */
            $original = RepairRequest::query()
                ->with(['services', 'shopOwner'])
                ->whereKey($lockedClaim->original_repair_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-run key eligibility checks at approval time.
            $this->validateEligibility($original, null, (int) $lockedClaim->id);

            $alreadyApprovedOther = RepairWarrantyClaim::query()
                ->where('original_repair_request_id', $original->id)
                ->where('id', '!=', $lockedClaim->id)
                ->where(function ($query) {
                    $query->where('status', RepairWarrantyClaim::STATUS_APPROVED)
                        ->orWhere('approved_once_guard', 1);
                })
                ->lockForUpdate()
                ->exists();

            if ($alreadyApprovedOther) {
                throw ValidationException::withMessages([
                    'claim' => ['An approved warranty claim already exists for this repair request.'],
                ]);
            }

            $sequence = (int) RepairRequest::query()
                ->where('parent_repair_request_id', $original->id)
                ->where('is_warranty_job', true)
                ->lockForUpdate()
                ->count() + 1;

            $requestId = $this->generateRepairRequestId();
            $displayAlias = sprintf('%s-W%d', (string) ($original->request_id ?: $original->id), $sequence);

            $handlerUserId = $lockedClaim->repair_handler_user_id;
            $handlerSource = (string) ($lockedClaim->handler_source ?? '');

            if ($handlerUserId === null || $handlerSource === '') {
                [$resolvedHandlerUserId, $resolvedHandlerSource] = $this->resolveHandlerForRepair($original);
                $handlerUserId = $handlerUserId ?? $resolvedHandlerUserId;
                $handlerSource = $handlerSource !== '' ? $handlerSource : $resolvedHandlerSource;
            }

            $preferredReturn = (string) ($lockedClaim->preferred_return_method ?? 'walk_in');
            $intakeMethod = $preferredReturn === 'customer_delivery' ? 'customer_delivery' : 'walk_in';
            $deliveryMethod = $intakeMethod === 'customer_delivery' ? 'pickup' : 'walk_in';

            $status = ($handlerSource === 'business_employee' && $handlerUserId)
                ? 'assigned_to_repairer'
                : 'pending';

            $pricingBreakdown = is_array($original->pricing_breakdown) ? $original->pricing_breakdown : [];
            $pricingBreakdown['mode'] = 'warranty_rework';
            $pricingBreakdown['base_total'] = 0;
            $pricingBreakdown['materials_total'] = 0;
            $pricingBreakdown['final_total'] = 0;
            $pricingBreakdown['warranty_claim_no'] = $lockedClaim->claim_no;
            $pricingBreakdown['parent_request_id'] = $original->request_id;

            $linked = RepairRequest::query()->create([
                'request_id' => $requestId,
                'customer_name' => $original->customer_name,
                'email' => $original->email,
                'phone' => $original->phone,
                'shoe_type' => $original->shoe_type,
                'brand' => $original->brand,
                'description' => $original->description,
                'shop_owner_id' => $original->shop_owner_id,
                'repair_package_id' => $original->repair_package_id,
                'user_id' => $original->user_id,
                'assigned_repairer_id' => ($handlerSource === 'business_employee' && $handlerUserId) ? $handlerUserId : null,
                'assigned_at' => ($handlerSource === 'business_employee' && $handlerUserId) ? now() : null,
                'assignment_method' => ($handlerSource === 'business_employee' && $handlerUserId) ? 'manual' : ($original->assignment_method ?? 'auto'),
                'assigned_by' => $actorId > 0 ? $actorId : null,
                'assignment_notes' => sprintf('Auto-created from approved warranty claim %s', (string) $lockedClaim->claim_no),
                'images' => $original->images,
                'total' => 0,
                'package_price' => 0,
                'add_ons_total' => 0,
                'final_total' => 0,
                'included_services_snapshot' => $original->included_services_snapshot,
                'add_on_services_snapshot' => $original->add_on_services_snapshot,
                'pricing_breakdown' => $pricingBreakdown,
                'payment_status' => 'completed',
                'payment_enabled' => false,
                'payment_policy' => $original->payment_policy,
                'payment_policy_snapshot' => $original->payment_policy_snapshot ?: $original->payment_policy,
                'payment_status_derived' => 'completed',
                'total_paid_amount' => 0,
                'total_refunded_amount' => 0,
                'manual_pos_queue_enabled' => false,
                'is_warranty_job' => true,
                'parent_repair_request_id' => $original->id,
                'warranty_sequence' => $sequence,
                'warranty_claim_id' => $lockedClaim->id,
                'billing_mode' => 'warranty_no_charge',
                'warranty_display_alias' => $displayAlias,
                'repair_handler_user_id' => $handlerUserId,
                'handler_source' => $handlerSource,
                'status' => $status,
                'delivery_method' => $deliveryMethod,
                'intake_delivery_method' => $intakeMethod,
                'intake_address' => $intakeMethod === 'customer_delivery' ? ($original->intake_address ?? $original->pickup_address) : null,
                'pickup_address' => $intakeMethod === 'customer_delivery' ? ($original->pickup_address ?? $original->intake_address) : null,
                'return_delivery_method' => 'walk_in',
                'is_high_value' => false,
                'requires_owner_approval' => false,
            ]);

            $serviceIds = $original->services()->pluck('repair_services.id')->all();
            if (!empty($serviceIds)) {
                $linked->services()->sync($serviceIds);
            }

            $lockedClaim->forceFill([
                'status' => RepairWarrantyClaim::STATUS_APPROVED,
                'approved_repair_request_id' => $linked->id,
                'reviewed_by_repairer_id' => $actorId > 0 ? $actorId : null,
                'reviewed_at' => now(),
                'rejection_reason' => null,
                'approved_once_guard' => 1,
                'repair_handler_user_id' => $handlerUserId,
                'handler_source' => $handlerSource,
            ])->save();

            $notificationPayload = [
                'claim_id' => (int) $lockedClaim->id,
                'claim_no' => (string) $lockedClaim->claim_no,
                'request_id' => (string) ($original->request_id ?: $original->id),
                'linked_request_id' => (string) ($linked->request_id ?: $linked->id),
                'customer_user_id' => (int) ($lockedClaim->customer_user_id ?? 0),
                'shop_owner_id' => (int) $lockedClaim->shop_owner_id,
                'source_channel' => (string) ($lockedClaim->source_channel ?? 'customer_portal'),
                'repair_handler_user_id' => (int) ($lockedClaim->repair_handler_user_id ?? 0),
                'handler_source' => (string) ($lockedClaim->handler_source ?? ''),
                'reviewed_by_repairer_id' => $actorId,
                'status' => (string) $lockedClaim->status,
                'reviewed_at' => optional($lockedClaim->reviewed_at)->toDateTimeString(),
                'approved_repair_request_id' => (int) $linked->id,
            ];

            DB::afterCommit(function () use ($lockedClaim, $actorId, $notificationPayload): void {
                $this->recordClaimActivity(
                    claim: $lockedClaim,
                    actorId: $actorId,
                    event: 'repair_warranty_claim_approved',
                    description: 'Warranty claim approved and linked warranty repair created.',
                    properties: $notificationPayload
                );

                $this->dispatchNotificationSafely(function () use ($notificationPayload): void {
                    $this->notificationService?->notifyRepairWarrantyClaimApproved(
                        (int) $notificationPayload['shop_owner_id'],
                        $notificationPayload
                    );
                });
            });

            return $lockedClaim->fresh(['originalRepair', 'approvedRepair', 'repairHandler']);
        });
    }

    public function rejectClaim(RepairWarrantyClaim $claim, int $actorId, string $reason): RepairWarrantyClaim
    {
        return DB::transaction(function () use ($claim, $actorId, $reason) {
            /** @var RepairWarrantyClaim $lockedClaim */
            $lockedClaim = RepairWarrantyClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if ((string) $lockedClaim->status !== RepairWarrantyClaim::STATUS_PENDING_REPAIRER) {
                throw ValidationException::withMessages([
                    'claim' => ['This warranty claim is no longer pending for review.'],
                ]);
            }

            if ($lockedClaim->warranty_expires_at_snapshot && now()->greaterThan($lockedClaim->warranty_expires_at_snapshot)) {
                $lockedClaim->forceFill([
                    'status' => RepairWarrantyClaim::STATUS_EXPIRED,
                ])->save();

                throw ValidationException::withMessages([
                    'claim' => ['Warranty claim expired before rejection.'],
                ]);
            }

            $lockedClaim->forceFill([
                'status' => RepairWarrantyClaim::STATUS_REJECTED,
                'reviewed_by_repairer_id' => $actorId > 0 ? $actorId : null,
                'reviewed_at' => now(),
                'rejection_reason' => trim($reason),
            ])->save();

            $lockedClaim = $lockedClaim->fresh(['originalRepair', 'approvedRepair', 'repairHandler']);

            $notificationPayload = [
                'claim_id' => (int) $lockedClaim->id,
                'claim_no' => (string) $lockedClaim->claim_no,
                'request_id' => (string) ($lockedClaim->originalRepair?->request_id ?: $lockedClaim->original_repair_request_id),
                'customer_user_id' => (int) ($lockedClaim->customer_user_id ?? 0),
                'shop_owner_id' => (int) $lockedClaim->shop_owner_id,
                'source_channel' => (string) ($lockedClaim->source_channel ?? 'customer_portal'),
                'repair_handler_user_id' => (int) ($lockedClaim->repair_handler_user_id ?? 0),
                'handler_source' => (string) ($lockedClaim->handler_source ?? ''),
                'reviewed_by_repairer_id' => $actorId,
                'status' => (string) $lockedClaim->status,
                'reviewed_at' => optional($lockedClaim->reviewed_at)->toDateTimeString(),
                'rejection_reason' => (string) ($lockedClaim->rejection_reason ?? ''),
            ];

            DB::afterCommit(function () use ($lockedClaim, $actorId, $notificationPayload): void {
                $this->recordClaimActivity(
                    claim: $lockedClaim,
                    actorId: $actorId,
                    event: 'repair_warranty_claim_rejected',
                    description: 'Warranty claim rejected by repair workflow reviewer.',
                    properties: $notificationPayload
                );

                $this->dispatchNotificationSafely(function () use ($notificationPayload): void {
                    $this->notificationService?->notifyRepairWarrantyClaimRejected(
                        (int) $notificationPayload['shop_owner_id'],
                        $notificationPayload
                    );
                });
            });

            return $lockedClaim;
        });
    }

    /**
     * @return array<string, int|float>
     */
    public function getKpiSummary(int $shopOwnerId, ?int $actorUserId = null, bool $restrictToActor = false, int $days = 30): array
    {
        $shopOwnerId = max(0, $shopOwnerId);
        $days = max(1, min(365, $days));
        $windowStart = now()->subDays($days);

        if ($shopOwnerId <= 0) {
            return [
                'window_days' => $days,
                'total_claims' => 0,
                'pending_count' => 0,
                'approved_count' => 0,
                'rejected_count' => 0,
                'expired_count' => 0,
                'from_pos_count' => 0,
                'from_customer_portal_count' => 0,
                'approval_rate' => 0.0,
                'average_review_hours' => 0.0,
            ];
        }

        $baseQuery = RepairWarrantyClaim::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '>=', $windowStart);

        if ($restrictToActor && $actorUserId && $actorUserId > 0) {
            $baseQuery->where(function ($query) use ($actorUserId) {
                $query->where('repair_handler_user_id', $actorUserId)
                    ->orWhereHas('originalRepair', function ($repairQuery) use ($actorUserId) {
                        $repairQuery->where('assigned_repairer_id', $actorUserId);
                    });
            });
        }

        $total = (clone $baseQuery)->count();
        $pending = (clone $baseQuery)->where('status', RepairWarrantyClaim::STATUS_PENDING_REPAIRER)->count();
        $approved = (clone $baseQuery)->where('status', RepairWarrantyClaim::STATUS_APPROVED)->count();
        $rejected = (clone $baseQuery)->where('status', RepairWarrantyClaim::STATUS_REJECTED)->count();
        $expired = (clone $baseQuery)->where('status', RepairWarrantyClaim::STATUS_EXPIRED)->count();
        $fromPos = (clone $baseQuery)->where('source_channel', 'manual_pos_walk_in')->count();
        $fromCustomerPortal = (clone $baseQuery)->where('source_channel', 'customer_portal')->count();

        $reviewDurations = (clone $baseQuery)
            ->whereNotNull('reviewed_at')
            ->get(['created_at', 'reviewed_at'])
            ->map(function (RepairWarrantyClaim $claim): float {
                if (!$claim->created_at || !$claim->reviewed_at) {
                    return 0.0;
                }

                return max(0.0, (float) $claim->created_at->floatDiffInHours($claim->reviewed_at));
            })
            ->filter(fn (float $duration) => $duration > 0.0)
            ->values();

        $averageReviewHours = $reviewDurations->isEmpty()
            ? 0.0
            : round((float) $reviewDurations->avg(), 2);

        return [
            'window_days' => $days,
            'total_claims' => $total,
            'pending_count' => $pending,
            'approved_count' => $approved,
            'rejected_count' => $rejected,
            'expired_count' => $expired,
            'from_pos_count' => $fromPos,
            'from_customer_portal_count' => $fromCustomerPortal,
            'approval_rate' => $total > 0 ? round(($approved / $total) * 100, 2) : 0.0,
            'average_review_hours' => $averageReviewHours,
        ];
    }

    /**
     * @param UploadedFile[] $images
     * @param array{warranty_started_at: Carbon, warranty_expires_at: Carbon, warranty_days: int} $window
     */
    private function createClaimRecord(
        RepairRequest $repair,
        array $validated,
        array $images,
        array $window,
        string $sourceChannel,
        int $actorId,
        ?int $customerUserId
    ): RepairWarrantyClaim {
        if (empty($images)) {
            throw ValidationException::withMessages([
                'images' => ['At least one evidence image is required.'],
            ]);
        }

        if (count($images) > self::MAX_EVIDENCE_IMAGES) {
            throw ValidationException::withMessages([
                'images' => [sprintf('You may upload up to %d evidence images only.', self::MAX_EVIDENCE_IMAGES)],
            ]);
        }

        if (!(bool) ($validated['same_issue_confirmation'] ?? false)) {
            throw ValidationException::withMessages([
                'same_issue_confirmation' => ['Same issue confirmation is required for warranty claims.'],
            ]);
        }

        [$handlerUserId, $handlerSource] = $this->resolveHandlerForRepair($repair);

        $evidenceMedia = $this->storeEvidenceMedia($images);

        $claim = RepairWarrantyClaim::query()->create([
            'claim_no' => $this->generateClaimNo(),
            'original_repair_request_id' => $repair->id,
            'approved_repair_request_id' => null,
            'customer_user_id' => $customerUserId,
            'shop_owner_id' => $repair->shop_owner_id,
            'repair_handler_user_id' => $handlerUserId,
            'handler_source' => $handlerSource,
            'status' => RepairWarrantyClaim::STATUS_PENDING_REPAIRER,
            'reason_code' => trim((string) $validated['reason_code']),
            'reason_details' => isset($validated['reason_details']) ? trim((string) $validated['reason_details']) : null,
            'same_issue_confirmation' => true,
            'evidence_media' => $evidenceMedia,
            'preferred_return_method' => trim((string) ($validated['preferred_return_method'] ?? 'walk_in')),
            'shipping_cost_bearer' => 'customer',
            'source_channel' => $sourceChannel,
            'warranty_started_at_snapshot' => $window['warranty_started_at'],
            'warranty_expires_at_snapshot' => $window['warranty_expires_at'],
            'reviewed_by_repairer_id' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'approved_once_guard' => null,
            'created_by' => $actorId > 0 ? $actorId : null,
        ]);

        $notificationPayload = [
            'claim_id' => (int) $claim->id,
            'claim_no' => (string) $claim->claim_no,
            'request_id' => (string) ($repair->request_id ?: $repair->id),
            'customer_user_id' => (int) ($claim->customer_user_id ?? 0),
            'shop_owner_id' => (int) $claim->shop_owner_id,
            'source_channel' => (string) ($claim->source_channel ?? 'customer_portal'),
            'repair_handler_user_id' => (int) ($claim->repair_handler_user_id ?? 0),
            'handler_source' => (string) ($claim->handler_source ?? ''),
            'status' => (string) $claim->status,
            'reason_code' => (string) $claim->reason_code,
            'created_at' => optional($claim->created_at)->toDateTimeString(),
        ];

        $this->recordClaimActivity(
            claim: $claim,
            actorId: $actorId,
            event: 'repair_warranty_claim_filed',
            description: 'Warranty claim filed.',
            properties: $notificationPayload
        );

        $this->dispatchNotificationSafely(function () use ($notificationPayload): void {
            $this->notificationService?->notifyRepairWarrantyClaimFiled(
                (int) $notificationPayload['shop_owner_id'],
                $notificationPayload
            );
        });

        return $claim;
    }

    private function recordClaimActivity(
        RepairWarrantyClaim $claim,
        int $actorId,
        string $event,
        string $description,
        array $properties = []
    ): void {
        try {
            $activity = activity('repair_warranty_claim')
                ->performedOn($claim)
                ->event($event)
                ->withProperties($properties);

            if ($actorId > 0) {
                $actor = User::query()->find($actorId);
                if ($actor) {
                    $activity->causedBy($actor);
                }
            }

            $activity->log($description);
        } catch (\Throwable $exception) {
            Log::warning('Failed to log repair warranty claim activity.', [
                'claim_id' => $claim->id,
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Keep warranty workflows resilient even when notification channels fail.
     */
    private function dispatchNotificationSafely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            Log::warning('Failed to dispatch repair warranty notification.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param UploadedFile[] $images
     * @return string[]
     */
    private function storeEvidenceMedia(array $images): array
    {
        $stored = [];

        foreach ($images as $image) {
            if (!$image instanceof UploadedFile) {
                continue;
            }

            $stored[] = $image->store('repair-warranty-claims', 'public');
        }

        if (empty($stored)) {
            throw ValidationException::withMessages([
                'images' => ['At least one valid evidence image is required.'],
            ]);
        }

        if (count($stored) > self::MAX_EVIDENCE_IMAGES) {
            throw ValidationException::withMessages([
                'images' => [sprintf('You may upload up to %d evidence images only.', self::MAX_EVIDENCE_IMAGES)],
            ]);
        }

        return $stored;
    }

    /**
     * @return array{0: int|null, 1: string}
     */
    private function resolveHandlerForRepair(RepairRequest $repair): array
    {
        $shopOwner = $repair->shopOwner ?: ShopOwner::query()->find($repair->shop_owner_id);
        $registrationType = strtolower((string) ($shopOwner?->registration_type ?? 'company'));

        if ($registrationType === 'individual') {
            return [$this->resolveOwnerLinkedUserId($shopOwner), 'individual_owner'];
        }

        $assignedRepairerId = (int) ($repair->assigned_repairer_id ?? 0);
        if ($assignedRepairerId > 0) {
            return [$assignedRepairerId, 'business_employee'];
        }

        $fallback = $this->resolveLeastLoadedRepairerUserId((int) ($repair->shop_owner_id ?? 0));

        return [$fallback, 'business_employee'];
    }

    private function resolveOwnerLinkedUserId(?ShopOwner $shopOwner): ?int
    {
        if (!$shopOwner) {
            return null;
        }

        $shopOwnerId = (int) ($shopOwner->id ?? 0);
        if ($shopOwnerId <= 0) {
            return null;
        }

        $email = trim((string) ($shopOwner->email ?? ''));
        if ($email !== '') {
            $id = (int) (User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('email', $email)
                ->value('id') ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        $roleMatchId = (int) (User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', ['Shop Owner', 'Manager']);
            })
            ->orderBy('id')
            ->value('id') ?? 0);

        if ($roleMatchId > 0) {
            return $roleMatchId;
        }

        $fallbackId = (int) (User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('id')
            ->value('id') ?? 0);

        return $fallbackId > 0 ? $fallbackId : null;
    }

    private function resolveLeastLoadedRepairerUserId(int $shopOwnerId): ?int
    {
        if ($shopOwnerId <= 0) {
            return null;
        }

        $activeStatuses = [
            'assigned_to_repairer',
            'repairer_accepted',
            'pending',
            'received',
            'in_progress',
            'awaiting_parts',
            'ready_for_pickup',
            'waiting_customer_confirmation',
            'confirmed',
            'owner_approval_pending',
            'owner_approved',
            'manager_reviewing',
            'manager_approved',
        ];

        $candidate = User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->whereHas('roles', function ($query) {
                $query->where('name', 'Repairer');
            })
            ->withCount([
                'assignedRepairs as active_repairs_count' => function ($query) use ($activeStatuses) {
                    $query->whereIn('status', $activeStatuses);
                }
            ])
            ->orderBy('active_repairs_count')
            ->orderBy('id')
            ->first();

        if ($candidate) {
            return (int) $candidate->id;
        }

        $fallbackId = (int) (User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id') ?? 0);

        return $fallbackId > 0 ? $fallbackId : null;
    }

    private function resolveWarrantyWindowStart(RepairRequest $repair): ?Carbon
    {
        foreach (['picked_up_at', 'received_at', 'customer_confirmed_at'] as $field) {
            $value = $repair->{$field} ?? null;
            if ($value !== null) {
                return Carbon::parse($value);
            }
        }

        return null;
    }

    private function generateClaimNo(): string
    {
        $counter = RepairWarrantyClaim::query()
            ->whereDate('created_at', now()->toDateString())
            ->count() + 1;

        $claimNo = sprintf('WCLM-%s-%04d', now()->format('Ymd'), $counter);
        while (RepairWarrantyClaim::query()->where('claim_no', $claimNo)->exists()) {
            $counter++;
            $claimNo = sprintf('WCLM-%s-%04d', now()->format('Ymd'), $counter);
        }

        return $claimNo;
    }

    private function generateRepairRequestId(): string
    {
        $counter = RepairRequest::query()
            ->whereDate('created_at', now()->toDateString())
            ->count() + 1;

        $requestId = 'REP-' . now()->format('Ymd') . str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
        while (RepairRequest::query()->where('request_id', $requestId)->exists()) {
            $counter++;
            $requestId = 'REP-' . now()->format('Ymd') . str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
        }

        return $requestId;
    }

    private function isManualPosRepair(RepairRequest $repair): bool
    {
        if (str_starts_with((string) ($repair->request_id ?? ''), 'REP-POS-')) {
            return true;
        }

        $mode = strtolower((string) (is_array($repair->pricing_breakdown) ? ($repair->pricing_breakdown['mode'] ?? '') : ''));

        return $mode === 'manual_pos';
    }
}
