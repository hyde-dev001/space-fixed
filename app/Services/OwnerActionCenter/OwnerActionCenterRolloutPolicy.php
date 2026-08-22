<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter;

use App\Enums\OwnerActionCenterDegradationStatus;
use App\Enums\OwnerActionCenterRolloutReason;
use App\Enums\OwnerShellPresentation;
use App\Models\ShopOwner;
use App\Services\OwnerShell\OwnerShellRolloutPolicy;
use App\Support\OwnerActionCenter\OwnerActionCenterSelection;
use UnexpectedValueException;
use Throwable;

final class OwnerActionCenterRolloutPolicy
{
    public function __construct(
        private readonly OwnerShellRolloutPolicy $ownerShellRolloutPolicy,
    ) {}

    public function select(ShopOwner $owner): OwnerActionCenterSelection
    {
        $shellSelection = $this->ownerShellRolloutPolicy->select($owner);

        if ($shellSelection->presentation !== OwnerShellPresentation::Canonical) {
            return $this->notSelected(
                OwnerActionCenterRolloutReason::CanonicalShellNotSelected,
                $shellSelection->context,
            );
        }

        try {
            if (! (bool) config('owner_action_center.enabled', false)) {
                return $this->notSelected(
                    OwnerActionCenterRolloutReason::GlobalDisabled,
                    $shellSelection->context,
                );
            }

            $allowlistedShopIds = config('owner_action_center.allowlisted_shop_ids', []);
            if (! is_array($allowlistedShopIds)) {
                throw new UnexpectedValueException('Owner Action Center allowlist must be an array.');
            }

            if ($allowlistedShopIds !== [] && ! in_array($owner->getKey(), $allowlistedShopIds, true)) {
                return $this->notSelected(
                    OwnerActionCenterRolloutReason::ShopNotAllowlisted,
                    $shellSelection->context,
                );
            }

            return new OwnerActionCenterSelection(
                selected: true,
                reason: $allowlistedShopIds === []
                    ? OwnerActionCenterRolloutReason::AlwaysOn
                    : OwnerActionCenterRolloutReason::ShopAllowlisted,
                degradationStatus: $this->degradationStatus(),
                context: $shellSelection->context,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->notSelected(
                OwnerActionCenterRolloutReason::CohortEvaluationFailed,
                $shellSelection->context,
            );
        }
    }

    private function degradationStatus(): OwnerActionCenterDegradationStatus
    {
        $coverage = config('owner_action_center.coverage', []);
        if (! is_array($coverage)) {
            throw new UnexpectedValueException('Owner Action Center coverage must be an array.');
        }

        $enabled = [];
        foreach ([
            'refunds',
            'prices',
            'payslips',
            'salary_changes',
            'purchase_requests',
            'expenses',
            'repair_rejections',
        ] as $family) {
            if (! array_key_exists($family, $coverage) || ! is_bool($coverage[$family])) {
                throw new UnexpectedValueException("Owner Action Center coverage [{$family}] must be boolean.");
            }

            $enabled[] = $coverage[$family];
        }

        return in_array(true, $enabled, true)
            ? OwnerActionCenterDegradationStatus::None
            : OwnerActionCenterDegradationStatus::NoEnabledAdapters;
    }

    private function notSelected(
        OwnerActionCenterRolloutReason $reason,
        ?string $context,
    ): OwnerActionCenterSelection {
        return new OwnerActionCenterSelection(
            selected: false,
            reason: $reason,
            degradationStatus: OwnerActionCenterDegradationStatus::NotSelected,
            context: $context,
        );
    }
}
