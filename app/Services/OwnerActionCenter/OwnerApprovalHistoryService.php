<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter;

use App\Models\Approval;
use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Models\PriceChangeRequest;
use App\Models\PurchaseRequest;
use App\Models\RepairPackage;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\User;
use App\Models\Finance\Expense;
use App\Models\HR\Payroll;
use App\Models\HR\SalaryChange;
use App\Support\OwnerActionCenter\OwnerApprovalHistoryItem;
use App\Support\OwnerActionCenter\OwnerApprovalHistoryResult;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

final class OwnerApprovalHistoryService
{
    /** @var array<string, array{source: string, coverage: string}> */
    private const GENERIC_APPROVABLES = [
        PriceChangeRequest::class => ['source' => 'product_price_change', 'coverage' => 'prices'],
        RepairService::class => ['source' => 'repair_price_change', 'coverage' => 'prices'],
        RepairPackage::class => ['source' => 'repair_package_price_change', 'coverage' => 'prices'],
        Payroll::class => ['source' => 'payslip', 'coverage' => 'payslips'],
        Expense::class => ['source' => 'expense', 'coverage' => 'expenses'],
    ];

    /**
     * Read completed owner-level decisions without changing the active queue.
     */
    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerApprovalHistoryResult
    {
        $historyCoverages = $this->coverageSourcesFor($owner);
        $coverageCounts = array_fill_keys($historyCoverages, 0);

        if ($query->coverage !== 'all' && ! in_array($query->coverage, $historyCoverages, true)) {
            return $this->emptyResult($query, $coverageCounts);
        }

        /** @var array<string, OwnerApprovalHistoryItem> $itemsByRecord */
        $itemsByRecord = [];
        $add = function (OwnerApprovalHistoryItem $item) use (&$itemsByRecord, $query): void {
            if ($query->coverage !== 'all' && $query->coverage !== $item->coverageSource) {
                return;
            }

            $recordKey = $item->sourceType.':'.$item->sourceId;
            $itemsByRecord[$recordKey] ??= $item;
        };

        foreach ($this->genericApprovalHistory($owner, $historyCoverages) as $item) {
            $add($item);
        }

        foreach ($this->directApprovalHistory($owner, $historyCoverages, $itemsByRecord) as $item) {
            $add($item);
        }

        $items = array_values($itemsByRecord);
        usort($items, static function (OwnerApprovalHistoryItem $left, OwnerApprovalHistoryItem $right): int {
            $dateComparison = strcmp($right->decisionAt, $left->decisionAt);
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            return strcmp($left->attentionKey, $right->attentionKey);
        });

        foreach ($items as $item) {
            $coverageCounts[$item->coverageSource] = ($coverageCounts[$item->coverageSource] ?? 0) + 1;
        }

        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $query->perPage));
        $page = min($query->page, $lastPage);
        $pageItems = array_slice($items, ($page - 1) * $query->perPage, $query->perPage);

        return new OwnerApprovalHistoryResult(
            items: $pageItems,
            coverageCounts: $coverageCounts,
            coverage: $query->coverage,
            page: $page,
            perPage: $query->perPage,
            total: $total,
            lastPage: $lastPage,
        );
    }

    /**
     * History remains available for every approval family supported by the
     * account, even when the owner stage is disabled for new requests.
     *
     * @return array<int, string>
     */
    public function coverageSourcesFor(ShopOwner $owner): array
    {
        if ($owner->registration_type === 'individual') {
            return ['refunds'];
        }

        return array_keys($this->coverageLabels());
    }

    /**
     * @param array<int, string> $enabledCoverages
     * @return array<int, OwnerApprovalHistoryItem>
     */
    private function genericApprovalHistory(ShopOwner $owner, array $enabledCoverages): array
    {
        $ownerUserIds = User::query()
            ->where('shop_owner_id', $owner->getKey())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ownerUserIds === []) {
            return [];
        }

        $approvals = Approval::query()
            ->whereIn('shop_owner_id', $ownerUserIds)
            ->whereNotNull('approvable_type')
            ->whereNotNull('approvable_id')
            ->whereIn('approvable_type', array_keys(self::GENERIC_APPROVABLES))
            ->with('approvable')
            ->get();

        $items = [];
        foreach ($approvals as $approval) {
            $approvable = $approval->approvable;
            if (! $approvable instanceof Model) {
                continue;
            }

            $definition = self::GENERIC_APPROVABLES[get_class($approvable)] ?? null;
            if ($definition === null || ! in_array($definition['coverage'], $enabledCoverages, true)) {
                continue;
            }

            $ownerStage = $this->approvalHasRole($approval, 'shop_owner');
            $review = $this->shopOwnerReview($approval);
            if ($review === null) {
                $review = $this->finalApprovalReview($approval);
            }
            if ($review === null) {
                continue;
            }

            $decisionAt = $this->dateString($review['reviewed_at'] ?? null)
                ?? $this->dateString($approval->reviewed_at)
                ?? $this->dateString($approval->updated_at);
            if ($decisionAt === null) {
                continue;
            }

            $source = $definition['source'];
            $name = $this->recordName($approvable, $source);
            $items[] = $this->item(
                sourceType: $source,
                sourceId: (int) $approvable->getKey(),
                coverage: $definition['coverage'],
                title: $this->genericTitle($source, $name),
                summary: $this->reviewSummary($review, $ownerStage),
                status: $this->labelForStatus($review['action']),
                decisionAt: $decisionAt,
                requestedAt: $this->dateString($approval->created_at),
                amount: $this->numericAmount($approval->amount)
                    ?? $this->genericAmount($approvable, $source),
                comments: $this->text($review['comments'] ?? null),
                reviewedBy: $this->reviewerName($owner, $review['user_id'] ?? null)
                    ?? ($this->normalize($review['role'] ?? null) === 'shop_owner'
                        ? null
                        : $this->roleLabel($review['role'] ?? null)),
            );
        }

        return array_values(array_filter($items));
    }

    /**
     * @param array<int, string> $enabledCoverages
     * @param array<string, OwnerApprovalHistoryItem> $itemsByRecord
     * @return array<int, OwnerApprovalHistoryItem>
     */
    private function directApprovalHistory(ShopOwner $owner, array $enabledCoverages, array $itemsByRecord): array
    {
        $items = [];

        if (in_array('refunds', $enabledCoverages, true)) {
            foreach (OrderRefund::query()->where('shop_owner_id', $owner->getKey())->get() as $refund) {
                if ($refund->requires_owner_approval === false) {
                    $review = $this->finalDirectReview(
                        $refund->finance_status,
                        $refund->finance_approved_at ?? $refund->approved_at,
                        $refund->failed_at ?? $refund->approved_at ?? $refund->updated_at,
                        $refund->finance_approved_by ?? $refund->processed_by,
                    );
                    if ($review !== null) {
                        $items[] = $this->item(
                            'order_refund', (int) $refund->getKey(), 'refunds',
                            'Order refund #'.$refund->getKey(),
                            $this->directReviewSummary($review, 'Finance', false), $review['action'],
                            $review['decision_at'], $this->dateString($refund->requested_at),
                            $this->numericAmount($refund->amount),
                            $this->text($refund->rejection_reason ?: $refund->reason_note),
                            $this->localReviewerName($owner, $review['reviewer_id']) ?? 'Finance',
                        );
                    }
                    continue;
                }

                $status = $this->decisionStatus($refund->shop_owner_status);
                if ($status !== null) {
                    $items[] = $this->item(
                        'order_refund', (int) $refund->getKey(), 'refunds',
                        'Order refund #'.$refund->getKey(),
                        'Order refund decision recorded by the shop owner.', $status,
                        $this->dateString($refund->shop_owner_approved_at) ?? $this->dateString($refund->updated_at),
                        $this->dateString($refund->requested_at),
                        $this->numericAmount($refund->amount),
                        $this->text($refund->rejection_reason ?: $refund->reason_note),
                        $this->ownerDisplayName($owner),
                    );
                }
            }

            foreach (PosRefund::query()
                ->where('shop_owner_id', $owner->getKey())
                ->where('module_type', 'repair')
                ->get() as $refund) {
                if ($refund->requires_owner_approval === false
                    || $this->normalize($refund->shop_owner_status) === 'skipped') {
                    $review = $this->finalDirectReview(
                        $refund->finance_status,
                        $refund->approved_at,
                        $refund->failed_at ?? $refund->approved_at ?? $refund->updated_at,
                        $refund->approved_by,
                    );
                    if ($review !== null) {
                        $items[] = $this->item(
                            'repair_refund', (int) $refund->getKey(), 'refunds',
                            'Repair refund #'.$refund->getKey(),
                            $this->directReviewSummary($review, 'Finance', false), $review['action'],
                            $review['decision_at'], $this->dateString($refund->requested_at),
                            $this->numericAmount($refund->approved_amount) ?? $this->numericAmount($refund->requested_amount),
                            $this->text($refund->reason_notes),
                            $this->localReviewerName($owner, $review['reviewer_id']) ?? 'Finance',
                        );
                    }
                    continue;
                }

                $status = $this->decisionStatus($refund->shop_owner_status);
                if ($status !== null) {
                    $items[] = $this->item(
                        'repair_refund', (int) $refund->getKey(), 'refunds',
                        'Repair refund #'.$refund->getKey(),
                        'Repair refund decision recorded by the shop owner.', $status,
                        $this->dateString($refund->approved_at) ?? $this->dateString($refund->updated_at),
                        $this->dateString($refund->requested_at),
                        $this->numericAmount($refund->approved_amount) ?? $this->numericAmount($refund->requested_amount),
                        $this->text($refund->reason_notes),
                        $this->ownerDisplayName($owner),
                    );
                }
            }
        }

        if (in_array('purchase_requests', $enabledCoverages, true)) {
            foreach (PurchaseRequest::query()
                ->where('shop_owner_id', $owner->getKey())
                ->get() as $request) {
                $hasOwnerDecision = $request->approved_by_shop_owner_id !== null
                    || $request->rejected_by_shop_owner_id !== null;
                $financeRejected = $this->decisionStatus($request->status) === 'rejected'
                    && $request->rejected_by_user_id !== null
                    && $request->rejected_by_shop_owner_id === null;

                if (! $hasOwnerDecision || $financeRejected) {
                    $review = $this->finalDirectReview(
                        $request->status,
                        $request->approved_date,
                        $request->rejected_at ?? $request->reviewed_date,
                        $request->approved_by ?? $request->rejected_by_user_id ?? $request->reviewed_by,
                    );
                    if ($review !== null) {
                        $items[] = $this->item(
                            'purchase_request', (int) $request->getKey(), 'purchase_requests',
                            'Purchase request '.($request->pr_number ?: '#'.$request->getKey()),
                            $this->directReviewSummary(
                                $review,
                                'Finance',
                                $request->requires_owner_approval !== false,
                            ),
                            $review['action'], $review['decision_at'],
                            $this->dateString($request->requested_date) ?? $this->dateString($request->created_at),
                            $this->numericAmount($request->total_cost),
                            $this->text($request->rejection_reason ?: $request->notes),
                            $this->localReviewerName($owner, $review['reviewer_id']) ?? 'Finance',
                        );
                    }
                    continue;
                }

                $approvedAt = $this->dateString($request->shop_owner_approved_at);
                $rejectedAt = $this->dateString($request->rejected_at);
                $status = $this->latestDecision($approvedAt, $rejectedAt);
                if ($status !== null) {
                    $items[] = $this->item(
                        'purchase_request', (int) $request->getKey(), 'purchase_requests',
                        'Purchase request '.($request->pr_number ?: '#'.$request->getKey()),
                        'Purchase request decision recorded by the shop owner.', $status,
                        $status === 'approved' ? $approvedAt : $rejectedAt,
                        $this->dateString($request->requested_date) ?? $this->dateString($request->created_at),
                        $this->numericAmount($request->total_cost),
                        $this->text($request->rejection_reason ?: $request->notes),
                        $this->ownerDisplayName($owner),
                    );
                }
            }
        }

        if (in_array('suspensions', $enabledCoverages, true)) {
            foreach (SuspensionRequest::query()->where('owner_id', $owner->getKey())->get() as $request) {
                $status = $this->decisionStatus($request->owner_status);
                $decisionAt = $this->dateString($request->owner_reviewed_at);
                if ($status !== null && $decisionAt !== null) {
                    $items[] = $this->item(
                        'suspension_request', (int) $request->getKey(), 'suspensions',
                        'Suspension request #'.$request->getKey(),
                        'Employee suspension decision recorded by the shop owner.', $status,
                        $decisionAt, $this->dateString($request->created_at), null,
                        $this->text($request->owner_note ?: $request->reason),
                        $this->ownerDisplayName($owner),
                    );
                }
            }
        }

        if (in_array('repair_rejections', $enabledCoverages, true)) {
            foreach (RepairRequest::query()->where('shop_owner_id', $owner->getKey())->get() as $request) {
                $managerFinal = $this->decisionStatus($request->status) === 'rejected'
                    && $this->normalize($request->manager_decision) === 'approve_rejection'
                    && $this->dateString($request->manager_reviewed_at) !== null;
                if ($managerFinal) {
                    $review = [
                        'action' => 'rejected',
                        'decision_at' => $this->dateString($request->manager_reviewed_at),
                        'reviewer_id' => $request->manager_reviewed_by,
                    ];
                    $items[] = $this->item(
                        'repair_rejection', (int) $request->getKey(), 'repair_rejections',
                        'Repair rejection #'.$request->getKey(),
                        $this->directReviewSummary(
                            $review,
                            'Manager',
                            $request->requires_owner_approval !== false,
                        ),
                        $review['action'], $review['decision_at'], $this->dateString($request->created_at),
                        $this->numericAmount($request->final_total) ?? $this->numericAmount($request->total),
                        $this->text($request->manager_review_notes ?: $request->repairer_rejection_reason),
                        $this->localReviewerName($owner, $review['reviewer_id']) ?? 'Manager',
                    );
                    continue;
                }

                $decision = $this->decisionStatus($request->owner_decision);
                $decisionAt = $this->dateString($request->owner_reviewed_at);
                if ($decision !== null && $decisionAt !== null) {
                    $items[] = $this->item(
                        'repair_rejection', (int) $request->getKey(), 'repair_rejections',
                        'Repair rejection #'.$request->getKey(),
                        'Repair rejection review recorded by the shop owner.', $decision,
                        $decisionAt, $this->dateString($request->created_at),
                        $this->numericAmount($request->final_total) ?? $this->numericAmount($request->total),
                        $this->text($request->owner_approval_notes ?: $request->repairer_rejection_reason),
                        $this->ownerDisplayName($owner),
                    );
                }
            }
        }

        if (in_array('salary_changes', $enabledCoverages, true)) {
            foreach (SalaryChange::query()->where('shop_owner_id', $owner->getKey())->get() as $change) {
                $status = $this->decisionStatus($change->status);
                $decisionAt = $status === 'approved'
                    ? $this->dateString($change->approved_at)
                    : $this->dateString($change->rejected_at);
                $reviewerId = $status === 'approved' ? $change->approved_by : $change->rejected_by;
                if ($status !== null && $decisionAt !== null && $this->reviewerBelongsToOwner($owner, $reviewerId)) {
                    $ownerApprovalRequired = $change->requires_owner_approval !== false;
                    $items[] = $this->item(
                        'salary_change', (int) $change->getKey(), 'salary_changes',
                        'Salary adjustment #'.$change->getKey(),
                        $ownerApprovalRequired
                            ? 'Salary adjustment decision recorded by the shop owner.'
                            : $this->directReviewSummary(
                                ['action' => $status],
                                'Finance',
                                false,
                            ),
                        $status,
                        $decisionAt, $this->dateString($change->created_at),
                        $this->numericAmount($change->new_salary),
                        $this->text($change->notes ?: $change->reason),
                        $this->localReviewerName($owner, $reviewerId)
                            ?? ($ownerApprovalRequired ? null : 'Finance'),
                    );
                }
            }
        }

        foreach ($this->legacyPriceHistory($owner, $enabledCoverages, $itemsByRecord) as $item) {
            $items[] = $item;
        }

        return array_values(array_filter($items));
    }

    /**
     * @param array<string, OwnerApprovalHistoryItem> $itemsByRecord
     * @return array<int, OwnerApprovalHistoryItem>
     */
    private function legacyPriceHistory(ShopOwner $owner, array $enabledCoverages, array $itemsByRecord): array
    {
        if (! in_array('prices', $enabledCoverages, true)) {
            return [];
        }

        $items = [];
        foreach (PriceChangeRequest::query()->where('shop_owner_id', $owner->getKey())->get() as $request) {
            if (isset($itemsByRecord['product_price_change:'.$request->getKey()])
                || (int) $request->owner_reviewed_by !== (int) $owner->getKey()
                || $this->dateString($request->owner_reviewed_at) === null) {
                continue;
            }

            $status = $this->decisionStatus($request->status);
            if ($status === null) {
                continue;
            }

            $items[] = $this->item(
                'product_price_change', (int) $request->getKey(), 'prices',
                'Product price change '.($request->product_name ?: '#'.$request->getKey()),
                'Product price change decision recorded by the shop owner.', $status,
                $this->dateString($request->owner_reviewed_at), $this->dateString($request->created_at),
                $this->numericAmount($request->proposed_price),
                $this->text($request->owner_rejection_reason ?: $request->reason),
                $this->ownerDisplayName($owner),
            );
        }

        foreach (RepairService::query()->where('shop_owner_id', $owner->getKey())->get() as $service) {
            if (isset($itemsByRecord['repair_price_change:'.$service->getKey()])
                || (int) $service->owner_reviewed_by !== (int) $owner->getKey()
                || $this->dateString($service->owner_reviewed_at) === null) {
                continue;
            }

            $status = $this->decisionStatus($service->status) ?? ($this->text($service->rejection_reason) !== null ? 'rejected' : 'approved');
            $items[] = $this->item(
                'repair_price_change', (int) $service->getKey(), 'prices',
                'Repair service price change '.($service->name ?: '#'.$service->getKey()),
                'Repair service price change decision recorded by the shop owner.', $status,
                $this->dateString($service->owner_reviewed_at), $this->dateString($service->created_at),
                $this->numericAmount($service->price),
                $this->text($service->rejection_reason),
                $this->ownerDisplayName($owner),
            );
        }

        foreach (RepairPackage::query()->where('shop_owner_id', $owner->getKey())->get() as $package) {
            if (isset($itemsByRecord['repair_package_price_change:'.$package->getKey()])
                || (int) $package->owner_reviewed_by !== (int) $owner->getKey()
                || $this->dateString($package->owner_reviewed_at) === null) {
                continue;
            }

            $status = $this->decisionStatus($package->approval_status);
            if ($status === null) {
                continue;
            }

            $items[] = $this->item(
                'repair_package_price_change', (int) $package->getKey(), 'prices',
                'Repair package price change '.($package->name ?: '#'.$package->getKey()),
                'Repair package price change decision recorded by the shop owner.', $status,
                $this->dateString($package->owner_reviewed_at), $this->dateString($package->created_at),
                $this->numericAmount($package->package_price),
                $this->text($package->owner_notes),
                $this->ownerDisplayName($owner),
            );
        }

        return array_values(array_filter($items));
    }

    private function approvalHasRole(Approval $approval, string $role): bool
    {
        $roles = is_array($approval->approval_roles) ? $approval->approval_roles : [];
        $expected = $this->normalize($role);

        foreach ($roles as $configuredRole) {
            if ($this->normalize($configuredRole) === $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{action: string, user_id: mixed, comments: mixed, reviewed_at: mixed, role: mixed, level: mixed}|null
     */
    private function finalApprovalReview(Approval $approval): ?array
    {
        $action = $this->decisionStatus($approval->status);
        if ($action === null) {
            return null;
        }

        $reviews = is_array($approval->level_reviewers) ? $approval->level_reviewers : [];
        $latest = null;
        foreach ($reviews as $level => $review) {
            if (! is_array($review)) {
                continue;
            }

            $reviewAction = $this->decisionStatus($review['action'] ?? $review['status'] ?? null);
            if ($reviewAction === null) {
                continue;
            }

            $reviewedAt = $this->dateString($review['reviewed_at'] ?? $review['created_at'] ?? null);
            $candidate = [
                'action' => $reviewAction,
                'user_id' => $review['user_id'] ?? $review['reviewer_id'] ?? null,
                'comments' => $review['comments'] ?? $review['reason'] ?? null,
                'reviewed_at' => $reviewedAt,
                'role' => $review['role'] ?? $this->approvalRole($approval, $level),
                'level' => $level,
            ];

            if ($latest === null || strcmp((string) ($latest['reviewed_at'] ?? ''), (string) ($reviewedAt ?? '')) < 0) {
                $latest = $candidate;
            }
        }

        $reviewedBy = $approval->reviewed_by;
        if ($reviewedBy === null && $latest !== null) {
            $reviewedBy = $latest['user_id'] ?? null;
        }

        return [
            'action' => $action,
            'user_id' => $reviewedBy,
            'comments' => $latest['comments'] ?? $approval->comments,
            'reviewed_at' => $latest['reviewed_at'] ?? $this->dateString($approval->reviewed_at),
            'role' => $latest['role'] ?? $this->approvalRole($approval, $approval->current_level),
            'level' => $latest['level'] ?? $approval->current_level,
        ];
    }

    /** @param array{action: string, role?: mixed} $review */
    private function reviewSummary(array $review, bool $ownerStage): string
    {
        $status = ucfirst($this->labelForStatus($review['action']));
        $role = $this->normalize($review['role'] ?? null);
        if ($ownerStage && ($role === '' || $role === 'shop_owner')) {
            return $status.' by the shop owner.';
        }

        $reason = $ownerStage
            ? 'owner approval was not reached.'
            : 'owner approval was not required.';

        return $status.' by '.$this->roleLabel($review['role'] ?? null).'; '.$reason;
    }

    /**
     * @return array{action: string, decision_at: string, reviewer_id: mixed}|null
     */
    private function finalDirectReview(
        mixed $status,
        mixed $approvedAt,
        mixed $rejectedAt,
        mixed $reviewerId,
    ): ?array {
        $action = $this->decisionStatus($status);
        if ($action === null) {
            return null;
        }

        $decisionAt = $action === 'approved'
            ? $this->dateString($approvedAt)
            : $this->dateString($rejectedAt);
        if ($decisionAt === null) {
            return null;
        }

        return [
            'action' => $action,
            'decision_at' => $decisionAt,
            'reviewer_id' => $reviewerId,
        ];
    }

    /** @param array{action: string} $review */
    private function directReviewSummary(array $review, string $role, bool $ownerApprovalRequired): string
    {
        $reason = $ownerApprovalRequired
            ? 'owner approval was not reached.'
            : 'owner approval was not required.';

        return ucfirst($this->labelForStatus($review['action'])).' by '.$role.'; '.$reason;
    }

    private function approvalRole(Approval $approval, mixed $level): ?string
    {
        $roles = is_array($approval->approval_roles) ? $approval->approval_roles : [];
        $role = null;
        if (is_int($level) || (is_string($level) && ctype_digit($level))) {
            $role = $roles[(string) $level] ?? null;
        }

        if (! is_string($role) || trim($role) === '') {
            $role = $approval->current_approver_role;
        }

        return is_string($role) && trim($role) !== '' ? $role : null;
    }

    private function roleLabel(mixed $role): string
    {
        return match ($this->normalize($role)) {
            'finance', 'finance_final' => 'Finance',
            'shop_owner' => 'Shop Owner',
            default => ($normalized = trim(str_replace('_', ' ', $this->normalize($role)))) !== ''
                ? ucwords($normalized)
                : 'Approver',
        };
    }

    /** @return array{action: string, user_id: mixed, comments: mixed, reviewed_at: mixed, role: string}|null */
    private function shopOwnerReview(Approval $approval): ?array
    {
        $roles = is_array($approval->approval_roles) ? $approval->approval_roles : [];
        $reviews = is_array($approval->level_reviewers) ? $approval->level_reviewers : [];
        $latest = null;

        foreach ($reviews as $level => $review) {
            if (! is_array($review)) {
                continue;
            }

            $role = $review['role'] ?? ($roles[(string) $level] ?? $roles[$level] ?? null);
            if ($this->normalize($role) !== 'shop_owner') {
                continue;
            }

            $action = $this->decisionStatus($review['action'] ?? $review['status'] ?? null);
            if ($action === null) {
                continue;
            }

            $reviewedAt = $this->dateString($review['reviewed_at'] ?? $review['created_at'] ?? null);
            if ($latest === null || strcmp((string) ($latest['reviewed_at'] ?? ''), (string) ($reviewedAt ?? '')) < 0) {
                $latest = [
                    'action' => $action,
                    'user_id' => $review['user_id'] ?? $review['reviewer_id'] ?? null,
                    'comments' => $review['comments'] ?? $review['reason'] ?? null,
                    'reviewed_at' => $reviewedAt,
                    'role' => 'shop_owner',
                ];
            }
        }

        return $latest;
    }

    private function item(
        string $sourceType,
        int $sourceId,
        string $coverage,
        string $title,
        string $summary,
        ?string $status,
        ?string $decisionAt,
        ?string $requestedAt,
        ?float $amount,
        ?string $comments,
        ?string $reviewedBy,
    ): ?OwnerApprovalHistoryItem {
        if ($status === null || $decisionAt === null) {
            return null;
        }

        return new OwnerApprovalHistoryItem(
            sourceType: $sourceType,
            sourceId: $sourceId,
            title: $this->limitText($title, 160),
            conciseSummary: $this->limitText($summary, 500),
            coverageSource: $coverage,
            status: $status,
            decisionAt: $decisionAt,
            requestedAt: $requestedAt,
            comparableMonetaryExposure: $amount,
            comments: $comments,
            reviewedBy: $reviewedBy,
            destinationUrl: $this->historyUrl($coverage, $sourceType, $sourceId),
        );
    }

    private function historyUrl(string $coverage, string $sourceType, int $sourceId): string
    {
        return '/shop-owner/action-center?view=history&source='.$coverage.'&approval='.$sourceType.':'.$sourceId;
    }

    private function decisionStatus(mixed $value): ?string
    {
        $value = $this->normalize($value);
        if (in_array($value, ['approved', 'owner_approved', 'approve', 'applied'], true)) {
            return 'approved';
        }
        if (in_array($value, ['rejected', 'owner_rejected', 'reject'], true)) {
            return 'rejected';
        }

        return null;
    }

    private function latestDecision(?string $approvedAt, ?string $rejectedAt): ?string
    {
        if ($approvedAt === null && $rejectedAt === null) {
            return null;
        }
        if ($rejectedAt === null || ($approvedAt !== null && strcmp($approvedAt, $rejectedAt) >= 0)) {
            return 'approved';
        }

        return 'rejected';
    }

    private function normalize(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function numericAmount(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return $amount >= 0 && is_finite($amount) ? $amount : null;
    }

    private function genericAmount(Model $record, string $source): ?float
    {
        return match ($source) {
            'repair_price_change' => $this->numericAmount($record->price),
            'repair_package_price_change' => $this->numericAmount($record->package_price),
            'payslip' => $this->numericAmount($record->net_salary) ?? $this->numericAmount($record->gross_salary),
            'expense' => $this->numericAmount($record->amount),
            default => $this->numericAmount($record->proposed_price),
        };
    }

    private function recordName(Model $record, string $source): string
    {
        return $this->text(match ($source) {
            'product_price_change' => $record->product_name,
            'repair_price_change', 'repair_package_price_change' => $record->name,
            'expense' => $record->reference ?: $record->description,
            'payslip' => 'Payroll #'.$record->getKey(),
            default => '#'.$record->getKey(),
        }) ?: '#'.$record->getKey();
    }

    private function genericTitle(string $source, string $name): string
    {
        return match ($source) {
            'product_price_change' => 'Product price change '.$name,
            'repair_price_change' => 'Repair service price change '.$name,
            'repair_package_price_change' => 'Repair package price change '.$name,
            'payslip' => 'Payslip approval '.$name,
            'expense' => 'Expense approval '.$name,
            default => 'Approval '.$name,
        };
    }

    private function labelForStatus(string $status): string
    {
        return $status === 'rejected' ? 'rejected' : 'approved';
    }

    private function reviewerBelongsToOwner(ShopOwner $owner, mixed $reviewerId): bool
    {
        $id = (int) $reviewerId;
        if ($id < 1) {
            return false;
        }
        if ($id === (int) $owner->getKey()) {
            return true;
        }

        return User::query()
            ->whereKey($id)
            ->where('shop_owner_id', $owner->getKey())
            ->exists();
    }

    private function reviewerName(ShopOwner $owner, mixed $reviewerId): ?string
    {
        if (! $this->reviewerBelongsToOwner($owner, $reviewerId)) {
            return null;
        }
        if ((int) $reviewerId === (int) $owner->getKey()) {
            return $this->ownerDisplayName($owner);
        }

        $reviewer = User::query()
            ->whereKey((int) $reviewerId)
            ->where('shop_owner_id', $owner->getKey())
            ->first();

        return $reviewer?->name ?: $this->ownerDisplayName($owner);
    }

    private function localReviewerName(ShopOwner $owner, mixed $reviewerId): ?string
    {
        $id = (int) $reviewerId;
        if ($id < 1) {
            return null;
        }

        return User::query()
            ->whereKey($id)
            ->where('shop_owner_id', $owner->getKey())
            ->value('name');
    }

    private function ownerDisplayName(ShopOwner $owner): string
    {
        $name = trim((string) ($owner->business_name ?: ($owner->first_name.' '.$owner->last_name)));

        return $name !== '' ? $name : 'Shop owner';
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $this->limitText($value, 1000);
    }

    private function limitText(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    /** @return array<string, string> */
    private function coverageLabels(): array
    {
        return [
            'refunds' => 'Refunds',
            'prices' => 'Price Changes',
            'payslips' => 'Payslips',
            'salary_changes' => 'Salary Adjustments',
            'purchase_requests' => 'Purchase Requests',
            'suspensions' => 'Suspension Requests',
            'expenses' => 'Expenses',
            'repair_rejections' => 'Repair Rejections',
        ];
    }

    /** @param array<string, int> $coverageCounts */
    private function emptyResult(OwnerAttentionQuery $query, array $coverageCounts): OwnerApprovalHistoryResult
    {
        return new OwnerApprovalHistoryResult(
            items: [],
            coverageCounts: $coverageCounts,
            coverage: $query->coverage,
            page: 1,
            perPage: $query->perPage,
            total: 0,
            lastPage: 1,
        );
    }
}
