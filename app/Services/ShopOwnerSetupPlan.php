<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HR\BranchPayrollSetting;
use App\Models\ShopOwner;
use App\Models\ShopPaymentIntegration;
use App\Models\ShopPolicyVersion;

final class ShopOwnerSetupPlan
{
    public function __construct(
        private readonly ShopModuleAccessService $modules,
        private readonly BusinessAccessControlService $access,
        private readonly CodEligibilityService $cod,
    ) {}

    public function for(ShopOwner $owner): array
    {
        $type = $this->access->normalizeBusinessType((string) $owner->business_type);
        $repair = in_array($type, ['repair', 'both'], true);
        $workforce = $this->modules->canAccess($owner, 'hr_employees');
        $tasks = [];
        $add = static function (string $key, string $group, string $label, bool $required, bool $complete, string $url) use (&$tasks): void {
            $tasks[] = compact('key', 'group', 'label', 'required', 'url') + [
                'status' => $complete ? 'complete' : 'incomplete',
            ];
        };
        $profile = '/shop-owner/shop-profile';
        $settings = '/shop-owner/settings';

        $add('profile_photos', 'profile', 'Add shop pictures', false, filled($owner->profile_photo) && filled($owner->cover_photo), $profile);
        $add('operating_hours', 'profile', 'Set operating hours', true, $this->hasHours($owner), $profile);
        $add('paymongo', 'business', 'Configure PayMongo', true, filled($owner->paymongo_secret_key), $settings . '/payments-approvals');
        $deadline = (int) $owner->order_refund_deadline_days;
        $add('refund_deadline', 'business', 'Review refund deadline', false, $deadline >= 1 && $deadline <= 30, $settings . '/operations');

        if ($repair) {
            $add('repair_payment_policy', 'business', 'Learn the repair payment policy', false, true, $settings . '/operations');
        }

        $add('terms_policy', 'business', 'Publish terms and conditions', true, $this->hasPublishedPolicy($owner, $type), $settings . '/policies-compliance');

        if ($workforce) {
            $add('first_employee', 'team', 'Create your first employee', true, Employee::query()->where('shop_owner_id', $owner->id)->whereHas('user')->exists(), '/shop-owner/erp/hr/user-access-control');
            $add('payroll_cutoff', 'team', 'Set payroll cutoff', true, BranchPayrollSetting::query()->forShopOwner((int) $owner->id)->active()->exists(), $settings . '/operations');
            $add('attendance_geofence', 'team', 'Set attendance geofence', true, (bool) $owner->attendance_geofence_enabled && $owner->shop_latitude !== null && $owner->shop_longitude !== null && (int) $owner->shop_geofence_radius >= 10, $settings . '/operations');
        }

        if ($this->modules->canAccess($owner, 'procurement')) {
            $integration = ShopPaymentIntegration::query()->forSupplierPayouts((int) $owner->id)->first();
            $add('xendit', 'business', 'Connect Xendit supplier payouts', true, $integration?->isConnected() ?? false, $settings . '/payments-approvals');
        }

        if (in_array($type, ['retail', 'both'], true) && $this->modules->canAccess($owner, 'logistics')) {
            $add('cod', 'business', 'Configure Cash on Delivery', false, $this->cod->baseAvailability($owner)['eligible'], $settings . '/operations');
        }

        $add('articles', 'help', 'Explore Articles', false, false, '/shop-owner/erp/articles');
        $add('customer_preview', 'help', 'Preview your shop', false, false, '/shop-profile/' . $owner->id);
        $required = array_filter($tasks, static fn (array $task): bool => $task['required']);

        return [
            'tasks' => $tasks,
            'completed' => count(array_filter($required, static fn (array $task): bool => $task['status'] === 'complete')),
            'total' => count($required),
        ];
    }

    private function hasHours(ShopOwner $owner): bool
    {
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $open = $owner->getAttribute($day . '_open');
            $close = $owner->getAttribute($day . '_close');
            if ($open && $close && $open < $close) {
                return true;
            }
        }
        return false;
    }

    private function hasPublishedPolicy(ShopOwner $owner, string $type): bool
    {
        $policy = ShopPolicyVersion::query()->where('shop_owner_id', $owner->id)->where('status', 'published')->latest('version_number')->first();
        if (! $policy || $policy->business_type_scope !== $type) {
            return false;
        }
        $sections = $policy->policy_sections_json ?? [];
        $required = ['refund_payment_terms'];
        if (in_array($type, ['retail', 'both'], true)) {
            $required[] = 'retail_terms';
        }
        if (in_array($type, ['repair', 'both'], true)) {
            $required[] = 'repair_service_terms';
        }
        foreach ($required as $key) {
            if (! filled($sections[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }
}