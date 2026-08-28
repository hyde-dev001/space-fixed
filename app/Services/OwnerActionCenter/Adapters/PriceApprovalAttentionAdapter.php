<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter\Adapters;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\PriceChangeRequest;
use App\Models\RepairPackage;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Database\Eloquent\Builder;

final class PriceApprovalAttentionAdapter implements OwnerAttentionAdapter
{
    public function adapterKey(): string
    {
        return 'price_approvals';
    }

    public function coverageSource(): string
    {
        return 'prices';
    }

    public function primaryBucket(): string
    {
        return 'needs_my_decision';
    }

    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult
    {
        $shopOwnerId = (int) $owner->getKey();
        $productQuery = PriceChangeRequest::query()
            ->select([
                'id',
                'shop_owner_id',
                'product_name',
                'current_price',
                'proposed_price',
                'reason',
                'status',
                'approval_id',
                'created_at',
                'updated_at',
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'finance_approved')
            ->whereNotNull('approval_id')
            ->whereHas('approval', static function (Builder $approvalQuery) use ($shopOwnerId): void {
                $approvalQuery
                    ->where('approvals.approvable_type', PriceChangeRequest::class)
                    ->where('approvals.shop_owner_id', $shopOwnerId)
                    ->where('approvals.status', 'pending')
                    ->where('approvals.current_level', '>', 0)
                    ->where('approvals.current_approver_role', 'shop_owner');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $repairServiceQuery = RepairService::query()
            ->select([
                'id',
                'shop_owner_id',
                'name',
                'old_price',
                'price',
                'finance_notes',
                'change_reason',
                'description',
                'status',
                'approval_workflow_version',
                'created_at',
                'updated_at',
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'Pending Owner Approval')
            ->where(function (Builder $workflowQuery): void {
                $workflowQuery
                    ->whereNull('approval_workflow_version')
                    ->orWhere('approval_workflow_version', '!=', 'repair_finance_only');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $repairPackageQuery = RepairPackage::query()
            ->select([
                'id',
                'shop_owner_id',
                'name',
                'old_package_price',
                'package_price',
                'change_reason',
                'description',
                'approval_status',
                'approval_workflow_version',
                'created_at',
                'updated_at',
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('approval_status', ['finance_approved', 'pending_owner'])
            ->where(function (Builder $workflowQuery): void {
                $workflowQuery
                    ->whereNull('approval_workflow_version')
                    ->orWhere('approval_workflow_version', '!=', 'repair_finance_only');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $qualifyingCount = (clone $productQuery)->count()
            + (clone $repairServiceQuery)->count()
            + (clone $repairPackageQuery)->count();

        $items = [];
        foreach ((clone $productQuery)->limit($query->candidateLimit)->get() as $priceChange) {
            $items[] = $this->productItem($priceChange);
        }
        foreach ((clone $repairServiceQuery)->limit($query->candidateLimit)->get() as $service) {
            $items[] = $this->repairServiceItem($service);
        }
        foreach ((clone $repairPackageQuery)->limit($query->candidateLimit)->get() as $package) {
            $items[] = $this->repairPackageItem($package);
        }

        usort($items, static function (OwnerAttentionItem $left, OwnerAttentionItem $right): int {
            $actionableComparison = strcmp($right->actionableSince, $left->actionableSince);
            if ($actionableComparison !== 0) {
                return $actionableComparison;
            }

            $sourceIdComparison = $right->sourceId <=> $left->sourceId;
            if ($sourceIdComparison !== 0) {
                return $sourceIdComparison;
            }

            return $left->sourceType <=> $right->sourceType;
        });

        return new OwnerAttentionAdapterResult(
            array_slice($items, 0, $query->candidateLimit),
            $qualifyingCount,
        );
    }

    private function productItem(PriceChangeRequest $priceChange): OwnerAttentionItem
    {
        $amount = $this->priceImpact($priceChange->current_price, $priceChange->proposed_price);

        return new OwnerAttentionItem(
            sourceType: 'product_price_change',
            sourceId: (int) $priceChange->getKey(),
            category: 'price_approval',
            primaryBucket: $this->primaryBucket(),
            module: 'pricing',
            title: 'Product price approval',
            conciseSummary: 'Review the pending product price change.',
            priorityTier: 'high',
            materialityTier: $this->materialityTier($amount),
            comparableMonetaryExposure: $amount,
            urgencyAt: null,
            actionableSince: $priceChange->updated_at?->toISOString() ?? now()->toISOString(),
            waitingOn: 'shop_owner',
            ownerActionRequired: true,
            coverageSource: $this->coverageSource(),
            destinationUrl: '/shop-owner/action-center?bucket=needs_my_decision&approval=product_price_change:'
                .$priceChange->getKey(),
        );
    }

    private function repairServiceItem(RepairService $service): OwnerAttentionItem
    {
        $amount = $this->priceImpact(
            $service->old_price ?? $service->price,
            is_numeric($service->finance_notes) ? $service->finance_notes : $service->price,
        );

        return $this->repairItem(
            sourceType: 'repair_price_change',
            sourceId: (int) $service->getKey(),
            title: 'Repair service price approval',
            summary: 'Review the pending repair service price change.',
            amount: $amount,
            actionableSince: $service->updated_at?->toISOString() ?? now()->toISOString(),
        );
    }

    private function repairPackageItem(RepairPackage $package): OwnerAttentionItem
    {
        $amount = $this->priceImpact(
            $package->old_package_price ?? $package->package_price,
            $package->package_price,
        );

        return $this->repairItem(
            sourceType: 'repair_package_price_change',
            sourceId: (int) $package->getKey(),
            title: 'Repair package price approval',
            summary: 'Review the pending repair package price change.',
            amount: $amount,
            actionableSince: $package->updated_at?->toISOString() ?? now()->toISOString(),
        );
    }

    private function repairItem(
        string $sourceType,
        int $sourceId,
        string $title,
        string $summary,
        float $amount,
        string $actionableSince,
    ): OwnerAttentionItem {
        return new OwnerAttentionItem(
            sourceType: $sourceType,
            sourceId: $sourceId,
            category: 'price_approval',
            primaryBucket: $this->primaryBucket(),
            module: 'pricing',
            title: $title,
            conciseSummary: $summary,
            priorityTier: 'high',
            materialityTier: $this->materialityTier($amount),
            comparableMonetaryExposure: $amount,
            urgencyAt: null,
            actionableSince: $actionableSince,
            waitingOn: 'shop_owner',
            ownerActionRequired: true,
            coverageSource: $this->coverageSource(),
            destinationUrl: '/shop-owner/action-center?bucket=needs_my_decision&approval='
                .$sourceType.':'.$sourceId,
        );
    }

    private function priceImpact(mixed $current, mixed $proposed): float
    {
        return round(abs((float) $proposed - (float) $current), 2);
    }

    private function materialityTier(float $amount): string
    {
        return match (true) {
            $amount >= 10000 => 'high',
            $amount >= 1000 => 'medium',
            default => 'low',
        };
    }
}
