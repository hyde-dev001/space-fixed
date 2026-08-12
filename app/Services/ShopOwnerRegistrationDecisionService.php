<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PrivilegedDeliveryType;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ShopOwnerRegistrationDecisionService
{
    public function __construct(
        private readonly ShopOwnerDocumentRequirementService $documentRequirements,
        private readonly ShopModuleProvisioningService $shopModuleProvisioning,
        private readonly PrivilegedAudit $privilegedAudit,
        private readonly PrivilegedMailDispatcher $privilegedMailDispatcher,
    ) {}

    /**
     * @return array{applied: bool, shop_owner: ShopOwner}
     */
    public function approve(Request $request, SuperAdmin $actor, int $shopOwnerId): array
    {
        $outcome = DB::transaction(function () use ($request, $actor, $shopOwnerId): array {
            $shopOwner = ShopOwner::query()->lockForUpdate()->findOrFail($shopOwnerId);
            $status = $this->statusValue($shopOwner);

            if ($status === 'approved') {
                return [
                    'applied' => false,
                    'shop_owner' => $shopOwner->fresh(),
                    'setup_token' => null,
                ];
            }

            if ($status !== 'pending') {
                throw new ConflictHttpException('Only pending registrations can be approved.');
            }

            $documents = $shopOwner->documents()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $documentState = $this->documentRequirements->evaluate($documents);

            $errors = [];
            foreach ($documentState['missing'] as $type) {
                $errors[$type] = ['This document is required before approval.'];
            }
            foreach ($documentState['invalid'] as $type => $reason) {
                $errors[$type] = [$reason];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $setupToken = Str::random(64);
            DB::table('password_reset_tokens')
                ->where('email', $shopOwner->email)
                ->lockForUpdate()
                ->first();

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $shopOwner->email],
                [
                    'email' => $shopOwner->email,
                    'token' => hash('sha256', $setupToken),
                    'created_at' => now(),
                ],
            );

            $shopOwner->forceFill([
                'status' => 'approved',
                'rejection_reason' => null,
            ])->save();

            foreach ($documentState['latest'] as $document) {
                $document->forceFill(['status' => 'approved'])->save();
            }

            $eligibleModuleKeys = $this->shopModuleProvisioning->eligibleKeysFor($shopOwner);
            $this->shopModuleProvisioning->initializeMissing($shopOwner, $eligibleModuleKeys);

            $this->privilegedAudit->shopRegistrationApproved(
                $request,
                $actor,
                $shopOwner,
                array_map(static fn ($document): int => (int) $document->getKey(), $documentState['latest']),
                $eligibleModuleKeys,
            );

            $this->privilegedMailDispatcher->dispatch(
                type: PrivilegedDeliveryType::SHOP_REGISTRATION_APPROVED,
                businessEventId: 'shop-registration-approved:'.$shopOwner->getKey(),
                recipientType: 'shop_owner',
                recipientId: (int) $shopOwner->getKey(),
                payload: [
                    'setup_token' => $setupToken,
                ],
                correlationId: $this->privilegedAudit->correlationId($request),
            );

            return [
                'applied' => true,
                'shop_owner' => $shopOwner->fresh(),
                'setup_token' => $setupToken,
            ];
        });

        unset($outcome['setup_token']);

        return $outcome;
    }

    /**
     * @return array{applied: bool, shop_owner: ShopOwner}
     */
    public function reject(Request $request, SuperAdmin $actor, int $shopOwnerId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => ['A rejection reason is required.'],
            ]);
        }

        $outcome = DB::transaction(function () use ($request, $actor, $shopOwnerId, $reason): array {
            $shopOwner = ShopOwner::query()->lockForUpdate()->findOrFail($shopOwnerId);
            $status = $this->statusValue($shopOwner);

            if ($status === 'rejected') {
                if ((string) $shopOwner->rejection_reason !== $reason) {
                    throw new ConflictHttpException('This registration was already rejected with a different reason.');
                }

                return [
                    'applied' => false,
                    'shop_owner' => $shopOwner->fresh(),
                ];
            }

            if ($status !== 'pending') {
                throw new ConflictHttpException('Only pending registrations can be rejected.');
            }

            $documents = $shopOwner->documents()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $documentState = $this->documentRequirements->evaluate($documents);

            $shopOwner->forceFill([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ])->save();

            foreach ($documentState['latest'] as $document) {
                if (strtolower(trim((string) $document->status)) === 'pending') {
                    $document->forceFill(['status' => 'rejected'])->save();
                }
            }

            $this->privilegedAudit->shopRegistrationRejected(
                $request,
                $actor,
                $shopOwner,
                $reason,
                array_map(static fn ($document): int => (int) $document->getKey(), $documentState['latest']),
            );

            $this->privilegedMailDispatcher->dispatch(
                type: PrivilegedDeliveryType::SHOP_REGISTRATION_REJECTED,
                businessEventId: 'shop-registration-rejected:'.$shopOwner->getKey(),
                recipientType: 'shop_owner',
                recipientId: (int) $shopOwner->getKey(),
                payload: [
                    'rejection_reason' => $reason,
                ],
                correlationId: $this->privilegedAudit->correlationId($request),
            );

            return [
                'applied' => true,
                'shop_owner' => $shopOwner->fresh(),
            ];
        });

        return $outcome;
    }

    private function statusValue(ShopOwner $shopOwner): string
    {
        $status = $shopOwner->status;

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

}
