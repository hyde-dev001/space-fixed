<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationPreferenceRoutingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function approval_notifications_use_their_workflow_preference_group(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        $approvalTypes = [
            [NotificationType::PRICE_CHANGE_REQUEST, 'browser_approvals'],
            [NotificationType::REPAIR_SERVICE_REQUEST, 'browser_approvals'],
            [NotificationType::HIGH_VALUE_APPROVAL, 'browser_approvals'],
            [NotificationType::REFUND_REQUEST, 'browser_approvals'],
            [NotificationType::EMPLOYEE_SUSPENSION_REQUEST, 'browser_approvals'],
            [NotificationType::REPAIR_REJECTION_REVIEW, 'browser_approvals'],
            [NotificationType::SALARY_CHANGE_SUBMITTED, 'browser_approvals'],
            [NotificationType::SUSPENSION_REQUEST_PENDING, 'browser_approvals'],
            [NotificationType::LEAVE_APPROVAL, 'browser_leave_approval'],
            [NotificationType::LEAVE_REQUEST_PENDING, 'browser_leave_approval'],
            [NotificationType::LEAVE_SUBMITTED, 'browser_leave_approval'],
            [NotificationType::EXPENSE_APPROVAL, 'browser_expense_approval'],
            [NotificationType::EXPENSE_REQUEST_PENDING, 'browser_expense_approval'],
            [NotificationType::EXPENSE_SUBMITTED, 'browser_expense_approval'],
            [NotificationType::INVOICE_CREATED_FINANCE, 'browser_invoice_created'],
            [NotificationType::PURCHASE_REQUEST_SUBMITTED, 'browser_approvals'],
            [NotificationType::OVERTIME_SUBMITTED, 'browser_hr_updates'],
        ];

        foreach ($approvalTypes as [$type, $preferenceField]) {
            $recipient = User::factory()->create([
                'shop_owner_id' => $shopOwner->id,
            ]);

            $preferences = NotificationPreference::getOrCreateForUser((int) $recipient->id);
            $preferences->forceFill([$preferenceField => false])->save();

            $result = app(NotificationService::class)->sendToUser(
                userId: (int) $recipient->id,
                type: $type,
                title: 'Approval notification test',
                message: 'This notification should respect the workflow preference.',
                data: ['test' => true],
                actionUrl: '/test',
                shopId: $shopOwner->id,
                requiresAction: true,
            );

            $this->assertNull($result, "{$type->value} bypassed {$preferenceField}.");
            $this->assertDatabaseMissing('notifications', [
                'user_id' => $recipient->id,
                'type' => $type->value,
            ]);
        }
    }
}
