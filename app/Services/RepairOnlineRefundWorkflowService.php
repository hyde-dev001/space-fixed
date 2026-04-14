<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\PosRefund;
use App\Models\ShopOwner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RepairOnlineRefundWorkflowService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function repairerApprove(PosRefund $refund, int $actorId, string $assessmentNote, float $approvedAmount): PosRefund
    {
        if ((string) $refund->repairer_status !== 'pending') {
            throw ValidationException::withMessages([
                'repairer_status' => ['Repairer review already completed.'],
            ]);
        }

        $refund->update([
            'repairer_status' => 'approved',
            'repairer_assessment_note' => Str::limit(trim($assessmentNote), 2000, ''),
            'repairer_reviewed_by' => $actorId,
            'repairer_reviewed_at' => now(),
            'approved_amount' => round($approvedAmount, 2),
            'status' => 'requested',
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        if ($this->isIndividualShopOwner((int) $refund->shop_owner_id)) {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: (int) $refund->shop_owner_id,
                type: NotificationType::REFUND_REQUEST,
                title: 'Repair Refund Approval Required',
                message: "Repair refund {$refund->refund_no} is ready for your approval.",
                data: [
                    'refund_id' => (int) $refund->id,
                    'refund_no' => (string) $refund->refund_no,
                    'repairer_status' => 'approved',
                ],
                actionUrl: '/shop-owner/refund-approvals',
                priority: 'high',
                requiresAction: true,
            );
        } else {
            $this->notificationService->sendToErpRole(
                roleName: 'Finance',
                shopId: (int) $refund->shop_owner_id,
                type: NotificationType::REFUND_REQUEST,
                title: 'Repair Refund Ready For Finance Review',
                message: "Repair refund {$refund->refund_no} was approved by repairer and is ready for finance review.",
                data: [
                    'refund_id' => (int) $refund->id,
                    'refund_no' => (string) $refund->refund_no,
                    'repairer_status' => 'approved',
                ],
                actionUrl: '/erp/finance/repair-refunds',
                priority: 'high',
            );
        }

        return $refund->fresh();
    }

    public function repairerReject(PosRefund $refund, int $actorId, string $assessmentNote, string $reason): PosRefund
    {
        if ((string) $refund->repairer_status !== 'pending') {
            throw ValidationException::withMessages([
                'repairer_status' => ['Repairer review already completed.'],
            ]);
        }

        $refund->update([
            'repairer_status' => 'rejected',
            'repairer_assessment_note' => Str::limit(trim($assessmentNote), 2000, ''),
            'repairer_reviewed_by' => $actorId,
            'repairer_reviewed_at' => now(),
            'status' => 'rejected',
            'failure_reason' => Str::limit(trim($reason), 255, ''),
            'failed_at' => now(),
        ]);

        if ($this->isIndividualShopOwner((int) $refund->shop_owner_id)) {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: (int) $refund->shop_owner_id,
                type: NotificationType::REFUND_REQUEST,
                title: 'Repair Refund Requires Review',
                message: "Repair refund {$refund->refund_no} was rejected by repairer and needs your review.",
                data: [
                    'refund_id' => (int) $refund->id,
                    'refund_no' => (string) $refund->refund_no,
                    'repairer_status' => 'rejected',
                    'reason' => trim($reason),
                ],
                actionUrl: '/shop-owner/refund-approvals',
                priority: 'high',
                requiresAction: true,
            );
        } else {
            $this->notificationService->sendToErpRole(
                roleName: 'Finance',
                shopId: (int) $refund->shop_owner_id,
                type: NotificationType::REFUND_REQUEST,
                title: 'Repair Refund Needs Finance Review',
                message: "Repair refund {$refund->refund_no} was rejected by repairer and needs finance follow-up.",
                data: [
                    'refund_id' => (int) $refund->id,
                    'refund_no' => (string) $refund->refund_no,
                    'repairer_status' => 'rejected',
                    'reason' => trim($reason),
                ],
                actionUrl: '/erp/finance/repair-refunds',
                priority: 'high',
            );
        }

        return $refund->fresh();
    }

    private function isIndividualShopOwner(int $shopOwnerId): bool
    {
        if ($shopOwnerId <= 0) {
            return false;
        }

        $registrationType = strtolower(trim((string) (ShopOwner::query()->whereKey($shopOwnerId)->value('registration_type') ?? '')));

        if ($registrationType === 'individual') {
            return true;
        }

        if ($registrationType === '' || $registrationType === 'company') {
            return false;
        }

        return str_contains($registrationType, 'individual') || str_contains($registrationType, 'sole');
    }
}
