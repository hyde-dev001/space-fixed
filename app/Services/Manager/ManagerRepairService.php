<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\AuditLog;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ShopOwnerApprovalPolicyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ManagerRepairService
{
    /** @var list<string> */
    private const ACTIVE_STATUSES = [
        'assigned_to_repairer',
        'repairer_accepted',
        'pending',
        'received',
        'in_progress',
        'awaiting_parts',
        'waiting_customer_confirmation',
        'confirmed',
        'owner_approval_pending',
        'owner_approved',
        'manager_reviewing',
        'manager_approved',
    ];

    /** @var list<string> */
    private const UNAVAILABLE_REASSIGNABLE_STATUSES = [
        'assigned_to_repairer',
        'repairer_accepted',
        'pending',
        'received',
        'in_progress',
        'awaiting_parts',
        'waiting_customer_confirmation',
        'confirmed',
        'reassignment_required',
    ];

    /** @var list<string> */
    private const TERMINAL_STATUSES = [
        'completed',
        'ready_for_pickup',
        'picked_up',
        'cancelled',
        'rejected',
        'manager_rejected',
        'owner_rejected',
    ];

    public function __construct(
        private readonly ManagerAuthorizationService $authorization,
        private readonly ManagerAssignmentEligibilityService $eligibility,
        private readonly NotificationService $notifications,
        private readonly ShopOwnerApprovalPolicyService $approvalPolicy,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function list(User $manager, array $filters): LengthAwarePaginator
    {
        return $this->listForShopOwnerId($this->authorizedShopOwnerId($manager), $filters);
    }

    /**
     * Read the normalized repair workload projection for a Shop Owner.
     * Mutation methods remain User/Manager-only below.
     */
    public function listForShopOwner(ShopOwner $owner, array $filters): LengthAwarePaginator
    {
        return $this->listForShopOwnerId((int) $owner->getKey(), $filters);
    }

    private function listForShopOwnerId(int $shopOwnerId, array $filters): LengthAwarePaginator
    {
        $query = RepairRequest::query()
            ->with([
                'repairer' => fn ($relation) => $relation->withTrashed(),
                'repairerRejectedBy',
                'managerReviewedBy',
                'ownerReviewedBy',
            ])
            ->where('shop_owner_id', $shopOwnerId);

        $this->applyFilters($query, $filters, $shopOwnerId);

        $perPage = max(5, min((int) ($filters['per_page'] ?? 25), 100));
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort = match ((string) ($filters['sort'] ?? 'created_at')) {
            'request_id', 'status', 'assigned_repairer_id', 'created_at', 'updated_at' => (string) ($filters['sort'] ?? 'created_at'),
            default => 'created_at',
        };

        $paginator = $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $workloads = $this->workloadMap(
            $shopOwnerId,
            $paginator->getCollection()->pluck('assigned_repairer_id')->filter()->map(fn ($id): int => (int) $id)->all(),
        );

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (RepairRequest $repair): array => $this->serialize($repair, $shopOwnerId, $workloads),
            ),
        );

        return $paginator;
    }

    /** @return array<string, mixed> */
    public function show(User $manager, int $repairId): array
    {
        return $this->showForShopOwnerId($this->authorizedShopOwnerId($manager), $repairId);
    }

    /** @return array<string, mixed> */
    public function showForShopOwner(ShopOwner $owner, int $repairId): array
    {
        return $this->showForShopOwnerId((int) $owner->getKey(), $repairId);
    }

    /** @return array<string, mixed> */
    private function showForShopOwnerId(int $shopOwnerId, int $repairId): array
    {
        $repair = $this->findRepair($shopOwnerId, $repairId);
        $workloads = $this->workloadMap($shopOwnerId, [(int) $repair->assigned_repairer_id]);

        return $this->serialize($repair, $shopOwnerId, $workloads);
    }

    /** @return list<array{id: int, name: string, email: string, workload: int}> */
    public function eligibleRepairers(User $manager, int $repairId): array
    {
        $shopOwnerId = $this->authorizedShopOwnerId($manager);
        $repair = $this->findRepair($shopOwnerId, $repairId);
        $excludedIds = array_values(array_filter([
            (int) ($repair->assigned_repairer_id ?? 0),
            (int) ($repair->repairer_rejected_by ?? 0),
        ]));
        $workDate = $this->workDate($repair);
        $workloadLimit = $this->workloadLimit($shopOwnerId);

        return $this->repairerCandidates($shopOwnerId, $excludedIds)
            ->get()
            ->filter(function (User $repairer) use ($shopOwnerId, $workDate, $workloadLimit): bool {
                $decision = $this->eligibility->evaluate(
                    assignee: $repairer,
                    shopOwnerId: $shopOwnerId,
                    workType: 'repair',
                    activeWorkDate: $workDate,
                );

                return $decision['eligible']
                    && $this->activeWorkload($shopOwnerId, (int) $repairer->id) < $workloadLimit;
            })
            ->map(fn (User $repairer): array => [
                'id' => (int) $repairer->id,
                'name' => $this->displayName($repairer),
                'email' => (string) $repairer->email,
                'workload' => $this->activeWorkload($shopOwnerId, (int) $repairer->id),
            ])
            ->values()
            ->all();
    }

    /**
     * Assign a new repair request using the shared workload/eligibility policy.
     *
     * The optional over-limit fallback is retained for the existing manual POS
     * flow, where a walk-in must remain visible even when every repairer is at
     * the configured advisory limit.
     */
    public function autoAssign(RepairRequest $repair, ?User $actor = null, bool $allowOverLimit = false): RepairRequest
    {
        $didAssign = false;
        $result = DB::transaction(function () use ($repair, $actor, $allowOverLimit, &$didAssign): RepairRequest {
            $locked = RepairRequest::query()
                ->whereKey($repair->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->assigned_repairer_id !== null || $this->isTerminal($locked)) {
                return $locked;
            }

            $previousStatus = $this->value($locked->status);

            $candidate = $this->selectCandidate(
                shopOwnerId: (int) $locked->shop_owner_id,
                workDate: $this->workDate($locked),
                excludedIds: [],
                allowOverLimit: $allowOverLimit,
            );

            if ($candidate === null) {
                $locked->forceFill([
                    'status' => 'awaiting_assignment',
                    'assigned_repairer_id' => null,
                    'assigned_at' => null,
                ])->save();

                $this->audit(
                    repair: $locked,
                    shopOwnerId: (int) $locked->shop_owner_id,
                    actorId: $actor?->id,
                    action: 'repair_awaiting_assignment',
                    metadata: [
                        'previous_state' => [
                            'status' => $previousStatus,
                            'assigned_repairer_id' => null,
                        ],
                        'new_state' => [
                            'status' => 'awaiting_assignment',
                            'assigned_repairer_id' => null,
                        ],
                        'reason' => 'No active eligible repairer was available.',
                        'reference_id' => 'repair:' . $locked->id,
                    ],
                );

                return $locked;
            }

            $locked->forceFill([
                'assigned_repairer_id' => $candidate->id,
                'assigned_at' => now(),
                'assignment_method' => 'auto',
                'assigned_by' => null,
                'assignment_notes' => null,
                'status' => 'assigned_to_repairer',
            ])->save();
            $didAssign = true;

            $this->audit(
                repair: $locked,
                shopOwnerId: (int) $locked->shop_owner_id,
                actorId: $actor?->id,
                action: 'repair_autoassigned',
                metadata: [
                    'previous_state' => [
                        'status' => $previousStatus,
                        'assigned_repairer_id' => null,
                    ],
                    'new_state' => [
                        'status' => 'assigned_to_repairer',
                        'assigned_repairer_id' => (int) $candidate->id,
                    ],
                    'selected_repairer_id' => (int) $candidate->id,
                    'workload_before_assignment' => $this->activeWorkload((int) $locked->shop_owner_id, (int) $candidate->id),
                    'assignment_strategy' => 'lowest_active_workload',
                    'reference_id' => 'repair:' . $locked->id,
                ],
            );

            return $locked;
        });

        $assignedRepairerId = (int) ($result->assigned_repairer_id ?? 0);
        if ($didAssign && $assignedRepairerId > 0) {
            try {
                $this->notifications->notifyRepairerAssignment(
                    $assignedRepairerId,
                    [
                        'repair_id' => (int) $result->id,
                        'request_id' => (string) $result->request_id,
                        'order_number' => (string) $result->request_id,
                        'customer_name' => (string) $result->customer_name,
                        'shoe_type' => $result->shoe_type,
                        'brand' => $result->brand,
                        'total' => $result->total,
                    ],
                    (int) $result->shop_owner_id,
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $result->fresh(['repairer']) ?? $result;
    }

    /**
     * Preserve the manual POS self-assignment exception while centralizing all
     * candidate selection and workload checks in this service.
     */
    public function assignManualPos(RepairRequest $repair, object $actor, int $shopOwnerId): RepairRequest
    {
        if ($actor instanceof User && (int) $actor->shop_owner_id === $shopOwnerId) {
            $decision = $this->eligibility->evaluate(
                assignee: $actor,
                shopOwnerId: $shopOwnerId,
                workType: 'repair',
                activeWorkDate: $this->workDate($repair),
            );

            if ($decision['eligible']) {
                $assigned = DB::transaction(function () use ($repair, $actor, $shopOwnerId): RepairRequest {
                    $locked = RepairRequest::query()
                        ->whereKey($repair->getKey())
                        ->where('shop_owner_id', $shopOwnerId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $previousStatus = $this->value($locked->status);

                    if ($locked->assigned_repairer_id === null) {
                        $locked->forceFill([
                            'assigned_repairer_id' => $actor->id,
                            'assigned_at' => now(),
                            'assignment_method' => 'manual',
                            'assigned_by' => $actor->id,
                            'assignment_notes' => 'Assigned from manual POS checkout by repairer actor',
                            'status' => 'assigned_to_repairer',
                        ])->save();

                        $this->audit(
                            repair: $locked,
                            shopOwnerId: $shopOwnerId,
                            actorId: (int) $actor->id,
                            action: 'repair_manually_assigned',
                            metadata: [
                                'previous_state' => [
                                    'status' => $previousStatus,
                                    'assigned_repairer_id' => null,
                                ],
                                'new_state' => [
                                    'status' => 'assigned_to_repairer',
                                    'assigned_repairer_id' => (int) $actor->id,
                                ],
                                'selected_repairer_id' => (int) $actor->id,
                                'source' => 'manual_pos',
                                'reference_id' => 'repair:' . $locked->id,
                            ],
                        );
                    }

                    return $locked;
                });

                return $assigned->fresh(['repairer']) ?? $assigned;
            }
        }

        return $this->autoAssign(
            repair: $repair,
            actor: $actor instanceof User ? $actor : null,
            allowOverLimit: true,
        );
    }

    public function recordRepairerRejection(
        User $repairer,
        int $repairId,
        string $reason,
        string $category = 'other',
    ): RepairRequest {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A rejection reason is required.'],
            ]);
        }

        $shopOwnerId = (int) ($repairer->shop_owner_id ?? 0);
        if ($shopOwnerId < 1) {
            throw new AccessDeniedHttpException('Repairer shop scope is required.');
        }

        $result = DB::transaction(function () use ($repairer, $repairId, $reason, $category, $shopOwnerId): RepairRequest {
            $locked = RepairRequest::query()
                ->whereKey($repairId)
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw (new ModelNotFoundException())->setModel(RepairRequest::class, [$repairId]);
            }

            if ((int) $locked->assigned_repairer_id !== (int) $repairer->id) {
                throw ValidationException::withMessages([
                    'assignment' => ['This repair is not assigned to you.'],
                ]);
            }

            if (! in_array($this->value($locked->status), ['assigned_to_repairer', 'repairer_accepted'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Repair request cannot be rejected in its current state.'],
                ]);
            }

            $manager = $this->shopManager($shopOwnerId);
            $shop = ShopOwner::query()->find($shopOwnerId);
            $previousStatus = $this->value($locked->status);
            $requiresOwnerApproval = $shop !== null
                && (bool) $shop->require_two_way_approval
                && $this->approvalPolicy->requiresOwnerApprovalForRepairReject(
                    $shopOwnerId,
                    (float) ($locked->total ?? 0),
                );
            $locked->forceFill([
                'status' => 'repairer_rejected',
                'repairer_rejection_reason' => $reason,
                'repairer_rejection_reason_category' => $category,
                'repairer_rejected_at' => now(),
                'repairer_rejected_by' => $repairer->id,
                'assigned_manager_id' => $manager?->id,
                'requires_owner_approval' => $requiresOwnerApproval,
                'manager_decision' => null,
                'manager_review_notes' => null,
                'manager_reviewed_at' => null,
                'manager_reviewed_by' => null,
            ])->save();

            $this->audit(
                repair: $locked,
                shopOwnerId: $shopOwnerId,
                actorId: (int) $repairer->id,
                action: 'repairer_rejected',
                metadata: [
                    'previous_state' => [
                        'status' => $previousStatus,
                        'assigned_repairer_id' => (int) $repairer->id,
                    ],
                    'new_state' => [
                        'status' => 'repairer_rejected',
                        'assigned_repairer_id' => (int) $repairer->id,
                    ],
                    'reason' => $reason,
                    'reason_category' => $category,
                    'manager_id' => $manager?->id,
                    'reference_id' => 'repair:' . $locked->id,
                ],
            );

            return $locked;
        });

        try {
            $this->notifications->notifyRepairRejectedToManager(
                (int) $result->shop_owner_id,
                [
                    'repair_id' => (int) $result->id,
                    'request_id' => (string) $result->request_id,
                    'order_number' => (string) $result->request_id,
                    'reason' => (string) $result->repairer_rejection_reason,
                    'reason_category' => (string) $result->repairer_rejection_reason_category,
                    'assigned_manager_id' => $result->assigned_manager_id,
                    'rejected_by_user_id' => $repairer->id,
                ],
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $result->fresh(['user', 'services', 'repairer', 'manager']) ?? $result;
    }

    public function reassign(User $manager, int $repairId, int $replacementRepairerId, string $reason): RepairRequest
    {
        $shopOwnerId = $this->authorizedMutationShopOwnerId($manager);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A reassignment reason is required.'],
            ]);
        }

        $result = DB::transaction(function () use ($manager, $shopOwnerId, $repairId, $replacementRepairerId, $reason): RepairRequest {
            $repair = RepairRequest::query()
                ->whereKey($repairId)
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->first();

            if ($repair === null) {
                throw (new ModelNotFoundException())->setModel(RepairRequest::class, [$repairId]);
            }

            $status = $this->value($repair->status);
            if ($this->isTerminal($repair)) {
                throw ValidationException::withMessages([
                    'status' => ['Terminal repair requests cannot be reassigned.'],
                ]);
            }

            $currentId = (int) ($repair->assigned_repairer_id ?? 0);
            $current = $currentId > 0
                ? User::withTrashed()->whereKey($currentId)->where('shop_owner_id', $shopOwnerId)->first()
                : null;
            $currentDecision = $current
                ? $this->eligibility->evaluate(
                    assignee: $current,
                    shopOwnerId: $shopOwnerId,
                    workType: 'repair',
                    activeWorkDate: $this->workDate($repair),
                )
                : [
                    'eligible' => false,
                    'reason_code' => 'handler_missing',
                    'reason_label' => 'The assigned repairer is no longer available.',
                ];

            $rejectionReview = $status === 'repairer_rejected';
            $unavailabilityException = in_array($status, self::UNAVAILABLE_REASSIGNABLE_STATUSES, true)
                && ! $currentDecision['eligible'];
            if (! $rejectionReview && ! $unavailabilityException) {
                throw ValidationException::withMessages([
                    'assignment' => ['Reassignment is only allowed after rejection or when the repairer is unavailable.'],
                ]);
            }

            if ($replacementRepairerId === $currentId || $replacementRepairerId === (int) ($repair->repairer_rejected_by ?? 0)) {
                throw ValidationException::withMessages([
                    'replacement_repairer_id' => ['Choose a different eligible repairer.'],
                ]);
            }

            $replacement = $this->repairerCandidates($shopOwnerId, [$currentId, (int) ($repair->repairer_rejected_by ?? 0)])
                ->whereKey($replacementRepairerId)
                ->lockForUpdate()
                ->first();

            if ($replacement === null) {
                throw ValidationException::withMessages([
                    'replacement_repairer_id' => ['The replacement repairer is not eligible for this shop.'],
                ]);
            }

            $replacementDecision = $this->eligibility->evaluate(
                assignee: $replacement,
                shopOwnerId: $shopOwnerId,
                workType: 'repair',
                activeWorkDate: $this->workDate($repair),
            );
            $replacementWorkload = $this->activeWorkload($shopOwnerId, (int) $replacement->id);
            if (! $replacementDecision['eligible']) {
                throw ValidationException::withMessages([
                    'replacement_repairer_id' => [$replacementDecision['reason_label'] ?? 'The replacement repairer is not eligible.'],
                ]);
            }
            if ($replacementWorkload >= $this->workloadLimit($shopOwnerId)) {
                throw ValidationException::withMessages([
                    'replacement_repairer_id' => ['The replacement repairer is at the active workload limit.'],
                ]);
            }

            $previousStatus = $status;

            $repair->forceFill([
                'status' => 'assigned_to_repairer',
                'assigned_repairer_id' => $replacement->id,
                'assigned_at' => now(),
                'assignment_method' => 'manual',
                'assigned_by' => $manager->id,
                'assignment_notes' => $reason,
                'reassignment_count' => (int) ($repair->reassignment_count ?? 0) + 1,
                'last_reassigned_at' => now(),
                'assigned_manager_id' => $manager->id,
                'manager_decision' => 'override_accept',
                'manager_review_notes' => $reason,
                'manager_reviewed_at' => now(),
                'manager_reviewed_by' => $manager->id,
            ])->save();

            $this->audit(
                repair: $repair,
                shopOwnerId: $shopOwnerId,
                actorId: (int) $manager->id,
                action: 'repair_reassigned',
                metadata: [
                    'previous_state' => [
                        'status' => $previousStatus,
                        'assigned_repairer_id' => $currentId > 0 ? $currentId : null,
                    ],
                    'new_state' => [
                        'status' => 'assigned_to_repairer',
                        'assigned_repairer_id' => (int) $replacement->id,
                    ],
                    'previous_repairer_id' => $currentId > 0 ? $currentId : null,
                    'replacement_repairer_id' => (int) $replacement->id,
                    'reason' => $reason,
                    'reference_id' => 'repair:' . $repair->id,
                    'trigger_reason_code' => $rejectionReview ? 'repairer_rejected' : $currentDecision['reason_code'],
                    'trigger_reason_label' => $rejectionReview
                        ? 'Repairer rejected the request.'
                        : $currentDecision['reason_label'],
                ],
            );

            return $repair;
        });

        try {
            $this->notifications->notifyRepairerAssignment(
                (int) $result->assigned_repairer_id,
                [
                    'repair_id' => (int) $result->id,
                    'request_id' => (string) $result->request_id,
                    'order_number' => (string) $result->request_id,
                    'customer_name' => (string) $result->customer_name,
                    'assignment_reason' => $reason,
                ],
                $shopOwnerId,
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $result->fresh(['repairer', 'managerReviewedBy']) ?? $result;
    }

    public function finalReject(User $manager, int $repairId, string $reason, bool $legacyStageOnly = false): RepairRequest
    {
        $shopOwnerId = $this->authorizedMutationShopOwnerId($manager);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A final rejection reason is required.'],
            ]);
        }

        $result = DB::transaction(function () use ($manager, $shopOwnerId, $repairId, $reason, $legacyStageOnly): RepairRequest {
            $repair = RepairRequest::query()
                ->whereKey($repairId)
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->first();

            if ($repair === null) {
                throw (new ModelNotFoundException())->setModel(RepairRequest::class, [$repairId]);
            }

            if ($repair->requires_owner_approval === true && $this->value($repair->status) !== 'manager_reviewing') {
                throw ValidationException::withMessages([
                    'status' => ['This repair follows an explicit Owner approval policy. Forward it to the Shop Owner instead of finalizing it here.'],
                ]);
            }

            $this->assertManagerDecisionState(
                repair: $repair,
                allowedStatuses: $legacyStageOnly ? ['manager_reviewing'] : null,
            );
            $previousStatus = $this->value($repair->status);
            $repair->forceFill([
                'status' => 'rejected',
                'manager_decision' => 'approve_rejection',
                'manager_review_notes' => $reason,
                'manager_reviewed_at' => now(),
                'manager_reviewed_by' => $manager->id,
                'assigned_manager_id' => $manager->id,
            ])->save();

            $this->audit(
                repair: $repair,
                shopOwnerId: $shopOwnerId,
                actorId: (int) $manager->id,
                action: 'repair_manager_final_rejected',
                metadata: [
                    'previous_state' => [
                        'status' => $previousStatus,
                    ],
                    'new_state' => [
                        'status' => 'rejected',
                    ],
                    'previous_status' => $previousStatus,
                    'new_status' => 'rejected',
                    'reason' => $reason,
                    'reference_id' => 'repair:' . $repair->id,
                ],
            );

            return $repair;
        });

        if ((int) ($result->user_id ?? 0) > 0) {
            try {
                $this->notifications->notifyRepairRejected((int) $result->user_id, [
                    'repair_id' => (int) $result->id,
                    'order_number' => (string) $result->request_id,
                    'reason' => $reason,
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $result->fresh(['user', 'services', 'managerReviewedBy']) ?? $result;
    }

    public function finalizeLegacy(User $manager, int $repairId, string $reason): RepairRequest
    {
        return $this->finalReject($manager, $repairId, $reason, legacyStageOnly: true);
    }

    /** Explicit exceptional Owner-stage workflow; never used by default. */
    public function forwardToOwner(User $manager, int $repairId, string $reason): RepairRequest
    {
        $shopOwnerId = $this->authorizedMutationShopOwnerId($manager);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A forwarding reason is required.'],
            ]);
        }

        $result = DB::transaction(function () use ($manager, $shopOwnerId, $repairId, $reason): RepairRequest {
            $repair = RepairRequest::query()
                ->whereKey($repairId)
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->first();

            if ($repair === null) {
                throw (new ModelNotFoundException())->setModel(RepairRequest::class, [$repairId]);
            }

            if ($repair->requires_owner_approval !== true) {
                throw ValidationException::withMessages([
                    'status' => ['This repair does not have an explicit Owner approval requirement.'],
                ]);
            }

            $this->assertManagerDecisionState($repair);
            $previousStatus = $this->value($repair->status);
            $repair->forceFill([
                'status' => 'owner_approval_pending',
                'manager_decision' => 'approve_rejection',
                'manager_review_notes' => $reason,
                'manager_reviewed_at' => now(),
                'manager_reviewed_by' => $manager->id,
                'assigned_manager_id' => $manager->id,
            ])->save();

            $this->audit(
                repair: $repair,
                shopOwnerId: $shopOwnerId,
                actorId: (int) $manager->id,
                action: 'repair_forwarded_to_owner',
                metadata: [
                    'previous_state' => [
                        'status' => $previousStatus,
                    ],
                    'new_state' => [
                        'status' => 'owner_approval_pending',
                    ],
                    'reason' => $reason,
                    'owner_stage' => 'explicit_policy',
                    'reference_id' => 'repair:' . $repair->id,
                ],
            );

            return $repair;
        });

        try {
            $this->notifications->notifyRepairRejectApprovalRequest($shopOwnerId, [
                'repair_id' => (int) $result->id,
                'request_id' => (string) $result->request_id,
                'order_number' => (string) $result->request_id,
                'reason' => $reason,
                'manager_id' => (int) $manager->id,
                'manager_name' => $this->displayName($manager),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $result->fresh(['user', 'services', 'managerReviewedBy']) ?? $result;
    }

    /**
     * Compatibility transition for the old two-step Manager review screen.
     * The canonical Manager page uses finalReject() or reassign() directly;
     * this method only preserves the explicitly retained policy-off flow.
     */
    public function beginLegacyManagerReview(User $manager, int $repairId, string $reason): RepairRequest
    {
        $shopOwnerId = $this->authorizedMutationShopOwnerId($manager);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A Manager review reason is required.'],
            ]);
        }

        $existingRepair = RepairRequest::query()
            ->whereKey($repairId)
            ->where('shop_owner_id', $shopOwnerId)
            ->first();

        if ($existingRepair === null) {
            throw (new ModelNotFoundException())->setModel(RepairRequest::class, [$repairId]);
        }

        if ($existingRepair->requires_owner_approval === true) {
            return $this->forwardToOwner($manager, $repairId, $reason);
        }

        $result = DB::transaction(function () use ($manager, $shopOwnerId, $repairId, $reason): RepairRequest {
            $repair = RepairRequest::query()
                ->whereKey($repairId)
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->first();

            if ($repair === null) {
                throw (new ModelNotFoundException())->setModel(RepairRequest::class, [$repairId]);
            }

            if ($repair->requires_owner_approval === true) {
                throw ValidationException::withMessages([
                    'status' => ['This repair now requires the explicit Shop Owner approval stage.'],
                ]);
            }

            if ($this->value($repair->status) !== 'repairer_rejected') {
                throw ValidationException::withMessages([
                    'status' => ['This repair request is not waiting for initial Manager review.'],
                ]);
            }

            $repair->forceFill([
                'status' => 'manager_reviewing',
                'manager_decision' => 'approve_rejection',
                'manager_review_notes' => $reason,
                'manager_reviewed_at' => now(),
                'manager_reviewed_by' => $manager->id,
                'assigned_manager_id' => $manager->id,
            ])->save();

            $this->audit(
                repair: $repair,
                shopOwnerId: $shopOwnerId,
                actorId: (int) $manager->id,
                action: 'repair_manager_reviewed',
                metadata: [
                    'previous_state' => [
                        'status' => 'repairer_rejected',
                    ],
                    'new_state' => [
                        'status' => 'manager_reviewing',
                    ],
                    'reason' => $reason,
                    'next_stage' => 'manager_final_decision',
                    'reference_id' => 'repair:' . $repair->id,
                ],
            );

            return $repair;
        });

        return $result->fresh(['user', 'services', 'managerReviewedBy']) ?? $result;
    }

    /** @return list<string> */
    public function activeStatuses(): array
    {
        return self::ACTIVE_STATUSES;
    }

    private function authorizedShopOwnerId(User $manager): int
    {
        $shopOwnerId = $this->authorization->shopOwnerId($manager);
        if ($shopOwnerId === null) {
            throw new AccessDeniedHttpException('Manager shop scope is required.');
        }

        return $shopOwnerId;
    }

    private function authorizedMutationShopOwnerId(User $manager): int
    {
        $shopOwnerId = $this->authorizedShopOwnerId($manager);
        if (! $this->authorization->allows($manager, ManagerAuthorizationService::REPAIR_REVIEW, $shopOwnerId)) {
            throw new AccessDeniedHttpException('Manager repair review is not authorized.');
        }

        return $shopOwnerId;
    }

    private function findRepair(int $shopOwnerId, int $repairId): RepairRequest
    {
        return RepairRequest::query()
            ->with([
                'repairer' => fn ($relation) => $relation->withTrashed(),
                'repairerRejectedBy',
                'managerReviewedBy',
                'ownerReviewedBy',
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->whereKey($repairId)
            ->firstOrFail();
    }

    private function repairerCandidates(int $shopOwnerId, array $excludedIds = []): Builder
    {
        return User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->when($excludedIds !== [], fn (Builder $query) => $query->whereNotIn('id', array_values(array_unique(array_filter($excludedIds)))))
            ->where(function (Builder $query): void {
                $query
                    ->whereRaw('UPPER(role) = ?', ['REPAIRER'])
                    ->orWhereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'Repairer'));
            })
            ->orderBy('id');
    }

    private function selectCandidate(
        int $shopOwnerId,
        \Carbon\CarbonInterface $workDate,
        array $excludedIds,
        bool $allowOverLimit,
    ): ?User {
        $limit = $this->workloadLimit($shopOwnerId);
        $rankedCandidates = [];
        foreach ($this->repairerCandidates($shopOwnerId, $excludedIds)->get() as $candidate) {
            $decision = $this->eligibility->evaluate(
                assignee: $candidate,
                shopOwnerId: $shopOwnerId,
                workType: 'repair',
                activeWorkDate: $workDate,
            );
            if (! $decision['eligible']) {
                continue;
            }

            $rankedCandidates[] = [
                'candidate' => $candidate,
                'workload' => $this->activeWorkload($shopOwnerId, (int) $candidate->id),
            ];
        }

        usort($rankedCandidates, static function (array $left, array $right): int {
            return [$left['workload'], (int) $left['candidate']->id]
                <=> [$right['workload'], (int) $right['candidate']->id];
        });

        $fallback = $rankedCandidates[0]['candidate'] ?? null;
        foreach ($rankedCandidates as $rankedCandidate) {
            $candidate = $rankedCandidate['candidate'];
            $lockedCandidate = User::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $lockedCandidate instanceof User) {
                continue;
            }

            $decision = $this->eligibility->evaluate(
                assignee: $lockedCandidate,
                shopOwnerId: $shopOwnerId,
                workType: 'repair',
                activeWorkDate: $workDate,
            );
            if (! $decision['eligible']) {
                continue;
            }

            $workload = $this->activeWorkload($shopOwnerId, (int) $lockedCandidate->id);
            if ($workload < $limit) {
                return $lockedCandidate;
            }
        }

        return $allowOverLimit ? $fallback : null;
    }

    private function activeWorkload(int $shopOwnerId, int $repairerId): int
    {
        if ($repairerId < 1) {
            return 0;
        }

        return RepairRequest::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('assigned_repairer_id', $repairerId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();
    }

    /** @param list<int> $repairerIds */
    private function workloadMap(int $shopOwnerId, array $repairerIds): Collection
    {
        $repairerIds = array_values(array_unique(array_filter($repairerIds, fn (int $id): bool => $id > 0)));
        if ($repairerIds === []) {
            return collect();
        }

        return RepairRequest::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('assigned_repairer_id', $repairerIds)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->selectRaw('assigned_repairer_id, COUNT(*) as workload')
            ->groupBy('assigned_repairer_id')
            ->pluck('workload', 'assigned_repairer_id')
            ->map(fn ($count): int => (int) $count);
    }

    private function applyFilters(Builder $query, array $filters, int $shopOwnerId): void
    {
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if (isset($filters['repairer_id']) && is_numeric($filters['repairer_id'])) {
            $query->where('assigned_repairer_id', (int) $filters['repairer_id']);
        }

        $reviewPending = filter_var($filters['review_pending'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($reviewPending) {
            $query->whereIn('status', ['repairer_rejected', 'reassignment_required']);
        }

        $assignmentState = strtolower(trim((string) ($filters['assignment_state'] ?? '')));
        if ($assignmentState === 'awaiting_assignment') {
            $query->where('status', 'awaiting_assignment');
        } elseif ($assignmentState === 'reassignment_required') {
            $ids = $this->reassignmentRequiredIds($shopOwnerId);
            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('request_id', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (($dateFrom = trim((string) ($filters['date_from'] ?? ''))) !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if (($dateTo = trim((string) ($filters['date_to'] ?? ''))) !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $overdue = filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $slaMinutes = $this->slaMinutes();
        if ($overdue && $slaMinutes !== null) {
            $query->where('created_at', '<=', now()->subMinutes($slaMinutes));
        } elseif ($overdue) {
            $query->whereRaw('1 = 0');
        }
    }

    /** @return list<int> */
    private function reassignmentRequiredIds(int $shopOwnerId): array
    {
        return RepairRequest::query()
            ->with(['repairer' => fn ($relation) => $relation->withTrashed()])
            ->where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('assigned_repairer_id')
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->get()
            ->filter(function (RepairRequest $repair) use ($shopOwnerId): bool {
                $repairer = $repair->repairer;
                if (! $repairer instanceof User) {
                    return true;
                }

                return ! $this->eligibility->evaluate(
                    assignee: $repairer,
                    shopOwnerId: $shopOwnerId,
                    workType: 'repair',
                    activeWorkDate: $this->workDate($repair),
                )['eligible'];
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @param Collection<int|string, int> $workloads */
    private function serialize(RepairRequest $repair, int $shopOwnerId, Collection $workloads): array
    {
        $repairer = $repair->repairer;
        $decision = $repairer instanceof User
            ? $this->eligibility->evaluate(
                assignee: $repairer,
                shopOwnerId: $shopOwnerId,
                workType: 'repair',
                activeWorkDate: $this->workDate($repair),
            )
            : [
                'eligible' => false,
                'reason_code' => 'handler_missing',
                'reason_label' => 'The assigned repairer is no longer available.',
            ];
        $status = $this->value($repair->status);
        $assignmentState = $status === 'awaiting_assignment'
            ? 'awaiting_assignment'
            : ($status === 'repairer_rejected'
                ? 'pending_manager_review'
                : ($repairer === null || ! $decision['eligible'] ? 'reassignment_required' : 'assigned'));
        $reviewState = $status === 'repairer_rejected' || $assignmentState === 'reassignment_required'
            ? 'pending_manager_review'
            : ($status === 'owner_approval_pending' ? 'pending_owner_review' : 'none');
        $ageMinutes = max(0, (int) ($repair->created_at?->diffInMinutes(now()) ?? 0));

        return [
            'id' => (int) $repair->id,
            'request_id' => (string) $repair->request_id,
            'customer_name' => (string) ($repair->customer_name ?? 'Guest'),
            'shoe_type' => $repair->shoe_type,
            'brand' => $repair->brand,
            'status' => $status,
            'display_status' => $this->statusLabel($status),
            'assigned_repairer' => $repairer instanceof User ? [
                'id' => (int) $repairer->id,
                'name' => $this->displayName($repairer),
                'status' => (string) ($repairer->status ?? 'active'),
            ] : null,
            'repairer_workload' => (int) $workloads->get((int) ($repair->assigned_repairer_id ?? 0), 0),
            'age_minutes' => $ageMinutes,
            'overdue' => $this->isOverdue($ageMinutes),
            'assignment_state' => $assignmentState,
            'review_state' => $reviewState,
            'rejection_reason' => $repair->repairer_rejection_reason,
            'rejection_reason_category' => $repair->repairer_rejection_reason_category,
            'reassignment_reason_code' => $assignmentState === 'reassignment_required' ? $decision['reason_code'] : null,
            'reassignment_reason_label' => $assignmentState === 'reassignment_required' ? $decision['reason_label'] : null,
            'requires_owner_approval' => (bool) $repair->requires_owner_approval,
            'next_action' => $this->nextAction($status, $assignmentState, (bool) $repair->requires_owner_approval),
            'created_at' => $repair->created_at?->toISOString(),
            'updated_at' => $repair->updated_at?->toISOString(),
        ];
    }

    private function nextAction(string $status, string $assignmentState, bool $requiresOwnerApproval): string
    {
        if ($status === 'awaiting_assignment') {
            return 'Manager review required: no eligible repairer';
        }
        if ($status === 'repairer_rejected') {
            return $requiresOwnerApproval ? 'Manager review: explicit Owner stage available' : 'Manager decision required';
        }
        if ($assignmentState === 'reassignment_required') {
            return 'Manager reassignment required';
        }
        if ($status === 'owner_approval_pending') {
            return 'Waiting for Shop Owner decision';
        }
        if ($this->isTerminalStatus($status)) {
            return 'No action required';
        }

        return 'Assigned repairer to continue';
    }

    /** @param list<string>|null $allowedStatuses */
    private function assertManagerDecisionState(RepairRequest $repair, ?array $allowedStatuses = null): void
    {
        $status = $this->value($repair->status);
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['This repair request is already in a terminal state.'],
            ]);
        }

        $allowedStatuses ??= ['repairer_rejected', 'reassignment_required', 'assigned_to_repairer', 'manager_reviewing'];
        if (! in_array($status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => ['This repair request is not waiting for Manager review.'],
            ]);
        }

        if ($status === 'assigned_to_repairer') {
            $repairer = $repair->assigned_repairer_id
                ? User::withTrashed()->find((int) $repair->assigned_repairer_id)
                : null;
            if ($repairer instanceof User && $this->eligibility->evaluate(
                assignee: $repairer,
                shopOwnerId: (int) $repair->shop_owner_id,
                workType: 'repair',
                activeWorkDate: $this->workDate($repair),
            )['eligible']) {
                throw ValidationException::withMessages([
                    'status' => ['This repair is still assigned to an eligible repairer.'],
                ]);
            }
        }
    }

    private function shopManager(int $shopOwnerId): ?User
    {
        return User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query
                    ->whereRaw('UPPER(role) = ?', ['MANAGER'])
                    ->orWhereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'Manager'));
            })
            ->orderBy('id')
            ->first();
    }

    private function workloadLimit(int $shopOwnerId): int
    {
        return max(1, (int) (ShopOwner::query()->whereKey($shopOwnerId)->value('repair_workload_limit') ?? 20));
    }

    private function workDate(RepairRequest $repair): \Carbon\CarbonInterface
    {
        return $repair->scheduled_dropoff_date ?? now();
    }

    private function isOverdue(int $ageMinutes): bool
    {
        $slaMinutes = $this->slaMinutes();

        return $slaMinutes !== null && $ageMinutes >= $slaMinutes;
    }

    private function slaMinutes(): ?int
    {
        $configured = config('manager.repair_sla_minutes');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : null;
    }

    private function isTerminal(RepairRequest $repair): bool
    {
        return $this->isTerminalStatus($this->value($repair->status));
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'assigned_to_repairer' => 'Assigned to Repairer',
            'repairer_accepted' => 'Repairer Accepted',
            'repairer_rejected' => 'Pending Manager Review',
            'reassignment_required' => 'Reassignment Required',
            'awaiting_assignment' => 'Awaiting Assignment',
            'owner_approval_pending' => 'Pending Shop Owner Review',
            'in_progress' => 'In Progress',
            'awaiting_parts' => 'Awaiting Parts',
            'ready_for_pickup' => 'Ready for Pickup',
            'completed' => 'Completed',
            'rejected' => 'Rejected by Manager',
            default => ucwords(str_replace(['_', '-'], ' ', $status)),
        };
    }

    private function displayName(User $user): string
    {
        return trim((string) ($user->name ?: (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')))) ?: (string) $user->email;
    }

    private function value(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return strtolower(trim((string) $value));
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        RepairRequest $repair,
        int $shopOwnerId,
        ?int $actorId,
        string $action,
        array $metadata,
    ): void {
        AuditLog::create([
            'shop_owner_id' => $shopOwnerId,
            'user_id' => $actorId,
            'actor_user_id' => $actorId,
            'action' => $action,
            'object_type' => 'repair_request',
            'object_id' => $repair->id,
            'target_type' => 'repair_request',
            'target_id' => $repair->id,
            'metadata' => array_merge([
                'repair_request_id' => (int) $repair->id,
                'timestamp' => now()->toISOString(),
            ], $metadata),
        ]);
    }
}
