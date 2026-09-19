<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\SuperAdmin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use DomainException;

final class PremiumSubscriptionCancellationService
{
    public function __construct(private readonly PrivilegedAudit $audit)
    {
    }

    /**
     * @return array{subscription: ShopOwnerSubscription, replayed: bool}
     */
    public function cancelForShopOwner(
        ShopOwnerSubscription $subscription,
        ShopOwner $owner,
        string $reason,
        ?string $notes,
    ): array {
        return $this->cancel(
            subscription: $subscription,
            reason: $reason,
            notes: $notes,
            owner: $owner,
        );
    }

    /**
     * @return array{subscription: ShopOwnerSubscription, replayed: bool}
     */
    public function cancelForSuperAdmin(
        ShopOwnerSubscription $subscription,
        SuperAdmin $admin,
        Request $request,
        string $reason,
        ?string $notes,
    ): array {
        return $this->cancel(
            subscription: $subscription,
            reason: $reason,
            notes: $notes,
            admin: $admin,
            request: $request,
        );
    }

    /**
     * @return array{subscription: ShopOwnerSubscription, replayed: bool}
     */
    private function cancel(
        ShopOwnerSubscription $subscription,
        string $reason,
        ?string $notes,
        ?ShopOwner $owner = null,
        ?SuperAdmin $admin = null,
        ?Request $request = null,
    ): array {
        $reason = trim($reason);
        $notes = $notes !== null ? trim($notes) : null;

        return DB::transaction(function () use ($subscription, $reason, $notes, $owner, $admin, $request): array {
            $locked = ShopOwnerSubscription::query()
                ->with('premiumPlan')
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($owner instanceof ShopOwner && (int) $locked->shop_owner_id !== (int) $owner->getKey()) {
                throw new DomainException('Subscription is outside the current shop owner scope.');
            }

            if ($locked->status === 'cancelled') {
                if (
                    (string) ($locked->cancellation_reason ?? '') === $reason
                    && (string) ($locked->cancellation_notes ?? '') === (string) ($notes ?? '')
                ) {
                    return [
                        'subscription' => $locked->fresh(['premiumPlan', 'payments']),
                        'replayed' => true,
                    ];
                }

                throw new DomainException('Subscription has already been cancelled with different details.');
            }

            if ($locked->status !== 'active') {
                throw new DomainException('Only an active paid subscription can be cancelled.');
            }

            $hasPendingChild = ShopOwnerSubscription::query()
                ->where(function ($query) use ($locked): void {
                    $query
                        ->where('renewal_of_subscription_id', $locked->getKey())
                        ->orWhere('replaces_subscription_id', $locked->getKey());
                })
                ->where('status', 'pending')
                ->exists();

            if ($hasPendingChild) {
                throw new DomainException('A pending replacement or renewal must be resolved first.');
            }

            $effectiveEndsAt = $locked->ends_at;
            if ($effectiveEndsAt === null && $locked->starts_at !== null && $locked->premiumPlan !== null) {
                $effectiveEndsAt = $locked->starts_at->copy()->addDays((int) $locked->premiumPlan->duration_days);
            }

            $priorStatus = (string) $locked->status;
            $locked->forceFill([
                'status' => 'cancelled',
                'auto_renew' => false,
                'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED,
                'ends_at' => $effectiveEndsAt,
                'cancellation_reason' => $reason,
                'cancellation_notes' => $notes !== '' ? $notes : null,
            ])->save();

            if ($admin instanceof SuperAdmin && $request instanceof Request) {
                $this->audit->premiumSubscriptionCancelled(
                    request: $request,
                    actor: $admin,
                    subscription: $locked,
                    priorStatus: $priorStatus,
                    reason: $reason,
                    notes: $notes !== '' ? $notes : null,
                );
            } elseif ($owner instanceof ShopOwner) {
                activity('billing')
                    ->causedBy($owner)
                    ->performedOn($locked)
                    ->withProperties([
                        'event' => 'subscription_cancelled',
                        'subscription_id' => (int) $locked->getKey(),
                        'shop_owner_id' => (int) $owner->getKey(),
                        'prior_status' => $priorStatus,
                        'reason' => $reason,
                        'notes' => $notes !== '' ? $notes : null,
                    ])
                    ->log('subscription_cancelled');
            }

            return [
                'subscription' => $locked->fresh(['premiumPlan', 'payments']),
                'replayed' => false,
            ];
        });
    }
}
