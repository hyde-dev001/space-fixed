<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
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
                actionUrl: '/finance?section=refund-approvals',
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

        $customerId = $this->resolveCustomerUserId($refund);
        if ($customerId > 0) {
            $this->notificationService->sendToUser(
                userId: $customerId,
                type: NotificationType::REFUND_REQUEST,
                title: 'Repair Refund Rejected',
                message: 'Your repair refund request was rejected after repairer inspection. Please check the details in My Repairs.',
                data: [
                    'refund_id' => (int) $refund->id,
                    'refund_no' => (string) $refund->refund_no,
                    'repairer_status' => 'rejected',
                    'status' => 'rejected',
                    'reason' => trim($reason),
                ],
                actionUrl: '/my-repairs',
                shopId: (int) $refund->shop_owner_id,
                priority: 'high',
            );
        }

        if ($this->isIndividualShopOwner((int) $refund->shop_owner_id)) {
            $this->notifyShopOwnerWithFallback(
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
            $this->notifyShopOwnerWithFallback(
                shopOwnerId: (int) $refund->shop_owner_id,
                type: NotificationType::REFUND_REQUEST,
                title: 'Repair Refund Rejected By Repairer',
                message: "Repair refund {$refund->refund_no} was rejected by repairer after inspection.",
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

    private function resolveCustomerUserId(PosRefund $refund): int
    {
        $refund->loadMissing([
            'sourceTransaction:id,customer_id',
            'repairRequest:id,user_id',
        ]);

        $customerId = (int) ($refund->sourceTransaction?->customer_id ?? 0);
        if ($customerId > 0) {
            return $customerId;
        }

        return (int) ($refund->repairRequest?->user_id ?? 0);
    }

    private function notifyShopOwnerWithFallback(
        int $shopOwnerId,
        NotificationType $type,
        string $title,
        string $message,
        array $data,
        string $actionUrl,
        string $priority = 'high',
        bool $requiresAction = true,
    ): void {
        $notification = $this->notificationService->sendToShopOwner(
            shopOwnerId: $shopOwnerId,
            type: $type,
            title: $title,
            message: $message,
            data: $data,
            actionUrl: $actionUrl,
            priority: $priority,
            requiresAction: $requiresAction,
        );

        if ($notification) {
            return;
        }

        Notification::create([
            'shop_owner_id' => $shopOwnerId,
            'type' => $type->value,
            'priority' => $priority,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'action_url' => $actionUrl,
            'requires_action' => $requiresAction,
            'shop_id' => $shopOwnerId,
        ]);
    }
}
