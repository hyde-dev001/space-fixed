<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopOwnerSubscription;
use App\Models\SuperAdmin;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

final class LegacyPremiumSubscriptionCorrectionService
{
    public function __construct(private readonly PrivilegedAudit $audit)
    {
    }

    /**
     * @return array{subscription: ShopOwnerSubscription, corrected: bool, replayed: bool}
     */
    public function correct(
        ShopOwnerSubscription $subscription,
        SuperAdmin $actor,
        Request $request,
        string $targetStatus,
        CarbonInterface $effectiveEndsAt,
        string $reason,
        ?string $notes,
    ): array {
        $targetStatus = strtolower(trim($targetStatus));
        $reason = trim($reason);
        $notes = $notes !== null ? trim($notes) : null;

        if (! in_array($targetStatus, ['cancelled', 'expired'], true)) {
            throw new DomainException('Unsupported legacy subscription target status.');
        }

        if ($targetStatus === 'expired' && $effectiveEndsAt->isFuture()) {
            throw new DomainException('Expired legacy subscriptions require an effective end date in the past.');
        }

        if ($targetStatus === 'cancelled' && ! $effectiveEndsAt->isFuture()) {
            throw new DomainException('Cancelled legacy subscriptions require a future effective end date.');
        }

        return DB::transaction(function () use (
            $subscription,
            $actor,
            $request,
            $targetStatus,
            $effectiveEndsAt,
            $reason,
            $notes,
        ): array {
            $locked = ShopOwnerSubscription::query()
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $sameCorrection = $locked->status === $targetStatus
                && $locked->ends_at?->equalTo($effectiveEndsAt)
                && (string) ($locked->cancellation_reason ?? '') === $reason
                && (string) ($locked->cancellation_notes ?? '') === (string) ($notes ?? '');

            if ($sameCorrection && $this->hasCorrectionAudit($locked)) {
                return [
                    'subscription' => $locked->fresh(['premiumPlan', 'payments']),
                    'corrected' => false,
                    'replayed' => true,
                ];
            }

            if ($locked->status !== 'deactivated') {
                throw new DomainException('Only unresolved legacy deactivated subscriptions can be corrected.');
            }

            $priorStatus = (string) $locked->status;
            $locked->forceFill([
                'status' => $targetStatus,
                'ends_at' => $effectiveEndsAt,
                'cancellation_reason' => $reason,
                'cancellation_notes' => $notes !== '' ? $notes : null,
            ])->save();

            $this->audit->legacySubscriptionCorrected(
                request: $request,
                actor: $actor,
                subscription: $locked,
                priorStatus: $priorStatus,
                targetStatus: $targetStatus,
                effectiveEndsAt: $effectiveEndsAt,
                reason: $reason,
                notes: $notes !== '' ? $notes : null,
            );

            return [
                'subscription' => $locked->fresh(['premiumPlan', 'payments']),
                'corrected' => true,
                'replayed' => false,
            ];
        });
    }

    private function hasCorrectionAudit(ShopOwnerSubscription $subscription): bool
    {
        return Activity::query()
            ->where('log_name', 'privileged')
            ->where('description', 'legacy_subscription_corrected')
            ->where('subject_type', $subscription->getMorphClass())
            ->where('subject_id', $subscription->getKey())
            ->exists();
    }
}
