<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountSuspension;
use App\Models\PremiumPlan;
use App\Models\ReviewReport;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\ShopReportModerationAction;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class PrivilegedAuditVisibility
{
    /** @var array<string, string> */
    public const EVENT_LABELS = [
        'privileged_bootstrap_created' => 'Privileged bootstrap created',
        'privileged_invitation_created' => 'Administrator invitation created',
        'privileged_invitation_resent' => 'Administrator invitation resent',
        'privileged_setup_exchange_succeeded' => 'Administrator setup exchange succeeded',
        'privileged_setup_exchange_failed' => 'Administrator setup exchange failed',
        'privileged_setup_password_completed' => 'Administrator setup password completed',
        'privileged_mfa_enrollment_verified' => 'MFA enrollment verified',
        'privileged_mfa_enrollment_started' => 'MFA enrollment started',
        'privileged_mfa_enrollment_failed' => 'MFA enrollment failed',
        'privileged_mfa_enrollment_completed' => 'MFA enrollment completed',
        'privileged_password_reset_requested' => 'Administrator password reset requested',
        'privileged_password_reset_exchange_succeeded' => 'Password reset exchange succeeded',
        'privileged_password_reset_exchange_failed' => 'Password reset exchange failed',
        'privileged_password_reset_completed' => 'Administrator password reset completed',
        'privileged_password_change_completed' => 'Administrator password changed',
        'privileged_recovery_codes_generated' => 'Recovery codes generated',
        'privileged_recovery_codes_acknowledged' => 'Recovery codes acknowledged',
        'privileged_reauthentication_succeeded' => 'Privileged reauthentication succeeded',
        'privileged_reauthentication_failed' => 'Privileged reauthentication failed',
        'privileged_administrator_suspended' => 'Administrator suspended',
        'privileged_administrator_deactivated' => 'Administrator deactivated',
        'privileged_administrator_activated' => 'Administrator activated',
        'privileged_administrator_returned_to_setup' => 'Administrator returned to setup',
        'privileged_administrator_role_changed' => 'Administrator role changed',
        'privileged_administrator_mfa_reset' => 'Administrator MFA reset',
        'privileged_own_mfa_reset' => 'Own MFA reset',
        'privileged_login_password_accepted' => 'Privileged login accepted',
        'privileged_login_failed' => 'Privileged login failed',
        'privileged_mfa_succeeded' => 'MFA verification succeeded',
        'privileged_mfa_failed' => 'MFA verification failed',
        'privileged_mfa_recovery_code_consumed' => 'MFA recovery code used',
        'document_access_initiated' => 'Private document accessed',
        'customer_valid_id_access_initiated' => 'Customer valid ID accessed',
        'super_admin_credential_rotated' => 'Administrator credential rotated',
        'legacy_account_suspension_reconciled' => 'Legacy account suspension reconciled',
        'legacy_appeal_superseded' => 'Legacy appeal superseded',
        'legacy_warning_strike_reconciled' => 'Legacy warning strike reconciled',
        'shop_registration_approved' => 'Shop registration approved',
        'shop_registration_rejected' => 'Shop registration rejected',
        'user_suspended' => 'User suspended',
        'user_reactivated' => 'User reactivated',
        'user_archived' => 'User archived',
        'user_restored' => 'User restored',
        'shop_suspended' => 'Shop suspended',
        'shop_reactivated' => 'Shop reactivated',
        'shop_archived' => 'Shop archived',
        'shop_restored' => 'Shop restored',
        'shop_reports_moderated' => 'Shop reports moderated',
        'flagged_account_moderated' => 'Flagged account moderated',
        'suspension_appeal_decided' => 'Suspension appeal decided',
        'premium_plan_created' => 'Premium plan created',
        'premium_plan_updated' => 'Premium plan updated',
        'premium_plan_archived' => 'Premium plan archived',
        'premium_plan_reactivated' => 'Premium plan reactivated',
        'shop_owner_upgrade_reviewed' => 'Shop owner upgrade reviewed',
        'shop_owner_upgrade_superseded' => 'Shop owner upgrade superseded',
        'privileged_capability_denied' => 'Privileged capability denied',
        'privileged_workflow_conflict' => 'Privileged workflow conflict',
        'privileged_workflow_failed' => 'Privileged workflow failed',
    ];

    /** @var array<string, array<int, string>> */
    private const OPERATIONAL_EVENTS_BY_CAPABILITY = [
        SuperAdmin::CAP_REVIEW_REGISTRATIONS => [
            'shop_registration_approved',
            'shop_registration_rejected',
            'document_access_initiated',
            'customer_valid_id_access_initiated',
            'shop_owner_upgrade_reviewed',
            'shop_owner_upgrade_superseded',
        ],
        SuperAdmin::CAP_INTERVENE_ACCOUNTS => [
            'user_suspended',
            'user_reactivated',
            'user_archived',
            'user_restored',
            'shop_suspended',
            'shop_reactivated',
            'shop_archived',
            'shop_restored',
            'legacy_account_suspension_reconciled',
        ],
        SuperAdmin::CAP_MODERATE_REPORTS => [
            'shop_reports_moderated',
            'flagged_account_moderated',
            'legacy_warning_strike_reconciled',
        ],
        SuperAdmin::CAP_VIEW_APPEALS => [
            'suspension_appeal_decided',
            'legacy_appeal_superseded',
        ],
    ];

    /** @var array<string, class-string> */
    private const TARGET_CLASSES = [
        'user' => User::class,
        'shop_owner' => ShopOwner::class,
        'super_admin' => SuperAdmin::class,
        'shop_document' => ShopDocument::class,
        'review_report' => ReviewReport::class,
        'suspension_appeal' => SuspensionAppeal::class,
        'premium_plan' => PremiumPlan::class,
        'shop_owner_upgrade_request' => ShopOwnerUpgradeRequest::class,
        'shop_report_moderation_action' => ShopReportModerationAction::class,
        'account_suspension' => AccountSuspension::class,
    ];

    /** @return array<int, string> */
    public static function eventValues(): array
    {
        return array_keys(self::EVENT_LABELS);
    }

    /** @return array<int, string> */
    public static function targetTypeValues(): array
    {
        return array_keys(self::TARGET_CLASSES);
    }

    /** @return array<int, array{value: string, label: string}> */
    public function eventOptions(SuperAdmin $viewer): array
    {
        $events = $viewer->role === SuperAdmin::ROLE_SUPER_ADMIN
            ? self::eventValues()
            : $this->operationalEvents($viewer);

        return array_values(array_map(
            fn (string $event): array => [
                'value' => $event,
                'label' => self::EVENT_LABELS[$event],
            ],
            $events,
        ));
    }

    /** @return array<int, array{value: string, label: string}> */
    public function targetTypeOptions(): array
    {
        return array_values(array_map(
            fn (string $value): array => [
                'value' => $value,
                'label' => Str::headline($value),
            ],
            self::targetTypeValues(),
        ));
    }

    public function visibleQuery(SuperAdmin $viewer): Builder
    {
        $query = Activity::query()->where('log_name', 'privileged');

        if ($viewer->role === SuperAdmin::ROLE_SUPER_ADMIN) {
            return $query;
        }

        $operationalEvents = $this->operationalEvents($viewer);

        return $query->where(function (Builder $visible) use ($viewer, $operationalEvents): void {
            $visible
                ->where('causer_type', SuperAdmin::class)
                ->where('causer_id', (int) $viewer->getKey());

            if ($operationalEvents !== []) {
                $visible->orWhereIn('event', $operationalEvents);
            }
        });
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(SuperAdmin $viewer, array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $query = $this->visibleQuery($viewer)
            ->with(['causer', 'subject'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($filters['event']) && $filters['event'] !== '') {
            $query->where('event', $filters['event']);
        }

        if (isset($filters['actor_id']) && $filters['actor_id'] !== '') {
            $query
                ->where('causer_type', SuperAdmin::class)
                ->where('causer_id', (int) $filters['actor_id']);
        }

        if (isset($filters['target_type']) && $filters['target_type'] !== '') {
            $targetClass = self::TARGET_CLASSES[$filters['target_type']] ?? null;
            if ($targetClass !== null) {
                $query->where('subject_type', $targetClass);
            }
        }

        if (isset($filters['target_id']) && $filters['target_id'] !== '') {
            $query->where('subject_id', (int) $filters['target_id']);
        }

        if (isset($filters['correlation_id']) && $filters['correlation_id'] !== '') {
            $query->where('properties', 'like', '%"correlation_id":"'.$filters['correlation_id'].'"%');
        }

        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $query->where('created_at', '>=', $filters['date_from'].' 00:00:00');
        }

        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Activity $activity, SuperAdmin $viewer): array
    {
        $properties = $activity->properties?->toArray() ?? [];
        $event = is_string($activity->event) && isset(self::EVENT_LABELS[$activity->event])
            ? $activity->event
            : 'unclassified';
        $actorId = $this->positiveInt($activity->causer_id);
        $targetId = $this->positiveInt($activity->subject_id ?? $properties['target_id'] ?? null);
        $source = $this->safeSource($properties['source'] ?? null);
        $correlationId = $this->safeUuid($properties['correlation_id'] ?? null);
        $isOwnAction = $actorId !== null && $actorId === (int) $viewer->getKey();

        return [
            'id' => (int) $activity->getKey(),
            'event' => $event,
            'event_label' => $event === 'unclassified'
                ? 'Unclassified privileged event'
                : self::EVENT_LABELS[$event],
            'actor' => [
                'id' => $actorId,
                'label' => $this->actorLabel($activity, $actorId),
                'role' => $this->safeRole($properties['actor_role'] ?? $activity->causer?->role),
            ],
            'target' => [
                'id' => $targetId,
                'type' => $this->targetType($activity, $properties),
                'label' => $this->targetLabel($activity),
            ],
            'outcome' => $this->outcome($properties),
            'source' => $source,
            'ip_address' => $isOwnAction || $viewer->role === SuperAdmin::ROLE_SUPER_ADMIN
                ? $this->safeIpAddress($properties['ip_address'] ?? null)
                : null,
            'correlation_id' => $correlationId,
            'metadata' => $this->safeMetadata($properties),
            'occurred_at' => $activity->created_at?->toISOString(),
        ];
    }

    public function eventLabel(?string $event): string
    {
        return is_string($event) && isset(self::EVENT_LABELS[$event])
            ? self::EVENT_LABELS[$event]
            : 'Unclassified privileged event';
    }

    /** @return array<int, string> */
    private function operationalEvents(SuperAdmin $viewer): array
    {
        $events = [];
        foreach (self::OPERATIONAL_EVENTS_BY_CAPABILITY as $capability => $capabilityEvents) {
            if ($viewer->hasCapability($capability)) {
                $events = array_merge($events, $capabilityEvents);
            }
        }

        return array_values(array_unique($events));
    }

    private function actorLabel(Activity $activity, ?int $actorId): string
    {
        $actor = $activity->causer;
        if ($actor instanceof SuperAdmin) {
            $name = trim((string) $actor->first_name.' '.(string) $actor->last_name);
            return $name !== '' ? $name : 'Administrator #'.$actor->getKey();
        }

        return $actorId === null ? 'System' : 'Administrator #'.$actorId;
    }

    private function targetType(Activity $activity, array $properties): string
    {
        $classToType = array_flip(self::TARGET_CLASSES);
        if (isset($classToType[$activity->subject_type])) {
            return Str::headline($classToType[$activity->subject_type]);
        }

        $propertyType = $properties['target_type'] ?? null;
        if (is_string($propertyType) && in_array($propertyType, self::targetTypeValues(), true)) {
            return Str::headline($propertyType);
        }

        return 'Record';
    }

    private function targetLabel(Activity $activity): string
    {
        $subject = $activity->subject;
        if ($subject instanceof User) {
            return trim((string) $subject->name) ?: 'User';
        }

        if ($subject instanceof ShopOwner) {
            return trim((string) $subject->business_name) ?: 'Shop owner';
        }

        if ($subject instanceof SuperAdmin) {
            return 'Administrator';
        }

        if ($subject instanceof PremiumPlan) {
            return trim((string) $subject->name) ?: 'Premium plan';
        }

        return match (true) {
            $subject instanceof ShopDocument => 'Private document',
            $subject instanceof ReviewReport => 'Flagged account report',
            $subject instanceof SuspensionAppeal => 'Suspension appeal',
            $subject instanceof ShopOwnerUpgradeRequest => 'Shop owner upgrade request',
            $subject instanceof ShopReportModerationAction => 'Shop report moderation',
            $subject instanceof AccountSuspension => 'Account suspension',
            default => 'Record',
        };
    }

    private function outcome(array $properties): ?string
    {
        foreach (['outcome', 'applied_action', 'decision', 'new_status'] as $key) {
            $value = $properties[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(Str::headline($value), 200, '...');
            }
        }

        return null;
    }

    private function safeSource(mixed $source): string
    {
        return is_string($source) && in_array($source, ['http', 'console', 'legacy_import'], true)
            ? $source
            : 'unknown';
    }

    private function safeRole(mixed $role): string
    {
        return is_string($role) && in_array($role, [
            SuperAdmin::ROLE_ADMIN,
            SuperAdmin::ROLE_SUPER_ADMIN,
            'legacy_unknown',
        ], true) ? $role : 'unknown';
    }

    private function safeUuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function safeIpAddress(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false
            ? $value
            : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /** @return array<string, int|string|bool|null> */
    private function safeMetadata(array $properties): array
    {
        $allowedKeys = [
            'prior_status',
            'new_status',
            'reason',
            'decision',
            'outcome',
            'requested_action',
            'applied_action',
            'report_count',
            'warning_strike_number',
            'warning_strike',
            'warning_limit',
            'document_type',
            'mime',
            'disposition',
            'account_type',
            'account_id',
            'suspension_id',
            'appeal_id',
            'moderation_action_id',
            'shop_owner_id',
            'customer_user_id',
            'old_registration_type',
            'old_business_type',
            'new_registration_type',
            'new_business_type',
            'dormant_employee_permission_warning',
        ];
        $metadata = [];

        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $properties)) {
                continue;
            }

            $value = $properties[$key];
            if (is_int($value) || is_bool($value)) {
                $metadata[$key] = $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $metadata[$key] = Str::limit(preg_replace('/\s+/', ' ', trim($value)) ?? '', 200, '...');
            }
        }

        return $metadata;
    }
}
