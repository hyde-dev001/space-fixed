<?php

declare(strict_types=1);

namespace App\Services\OwnerShell;

use App\Enums\OwnerShellPresentation;
use App\Enums\OwnerShellSelectionReason;
use App\Models\ShopOwner;
use App\Support\OwnerShell\OwnerShellSelection;
use UnexpectedValueException;
use Throwable;

final class OwnerShellRolloutPolicy
{
    public function select(ShopOwner $owner): OwnerShellSelection
    {
        $context = $this->registrationContext($owner);

        if ($context === null) {
            return $this->existing(OwnerShellSelectionReason::InvalidRegistrationContext);
        }

        try {
            if (! (bool) config('owner_shell.enabled', false)) {
                return $this->existing(OwnerShellSelectionReason::GlobalDisabled, $context);
            }

            $allowlistedShopIds = config('owner_shell.allowlisted_shop_ids', []);
            if (! is_array($allowlistedShopIds)) {
                throw new UnexpectedValueException('Owner shell allowlist must be an array.');
            }

            if (! in_array($owner->getKey(), $allowlistedShopIds, true)) {
                return $this->existing(OwnerShellSelectionReason::ShopNotAllowlisted, $context);
            }

            return new OwnerShellSelection(
                OwnerShellPresentation::Canonical,
                OwnerShellSelectionReason::ShopAllowlisted,
                $context,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->existing(OwnerShellSelectionReason::CohortEvaluationFailed, $context);
        }
    }

    private function registrationContext(ShopOwner $owner): ?string
    {
        return match (strtolower(trim((string) $owner->registration_type))) {
            'individual' => 'individual',
            'company' => 'company',
            default => null,
        };
    }

    private function existing(OwnerShellSelectionReason $reason, ?string $context = null): OwnerShellSelection
    {
        return new OwnerShellSelection(OwnerShellPresentation::Existing, $reason, $context);
    }
}
