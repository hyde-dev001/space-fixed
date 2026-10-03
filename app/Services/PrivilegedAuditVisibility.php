<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountSuspension;
use App\Models\IdentityVerification;
use App\Models\MaintenanceWindow;
use App\Models\PremiumPlan;
use App\Models\ReviewReport;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
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
        'shop_document_renewal_approved' => 'Shop document renewal approved',
        'shop_document_renewal_rejected' => 'Shop document renewal rejected',
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
        'identity_verification_approved' => 'Identity verification approved',
        'identity_verification_rejected' => 'Identity verification rejected',
        'identity_verification_inspected' => 'Identity verification reviewed',
        'legacy_subscription_corrected' => 'Legacy subscription corrected',
        'subscription_cancelled' => 'Subscription cancelled',
        'subscription_refund_initiated' => 'Subscription refund initiated',
        'subscription_refund_succeeded' => 'Subscription refund completed',
        'subscription_refund_processing' => 'Subscription refund processing',
        'subscription_refund_failed' => 'Subscription refund failed',
        'subscription_refund_unknown' => 'Subscription refund needs review',
        'subscription_refund_reconciled' => 'Subscription refund reconciled',
        'platform_maintenance_created' => 'Maintenance window created',
        'platform_maintenance_scheduled' => 'Maintenance scheduled',
        'platform_maintenance_updated' => 'Maintenance window updated',
        'platform_maintenance_cancelled' => 'Maintenance cancelled',
        'platform_maintenance_activated' => 'Maintenance activated',
        'platform_maintenance_extended' => 'Maintenance extended',
        'platform_maintenance_progress_updated' => 'Maintenance progress updated',
        'platform_maintenance_public_update_changed' => 'Maintenance update published',
        'platform_maintenance_ended' => 'Maintenance ended',
        'privileged_capability_denied' => 'Privileged capability denied',
        'privileged_page_denied' => 'Privileged page access denied',
        'privileged_admin_page_access_changed' => 'Administrator page access changed',
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
            'shop_document_renewal_approved',
            'shop_document_renewal_rejected',
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
        'identity_verification' => IdentityVerification::class,
        'maintenance_window' => MaintenanceWindow::class,
        'shop_owner_subscription' => ShopOwnerSubscription::class,
    ];

    /** @var array<string, string> */
    private const SOURCE_LABELS = [
        'http' => 'Admin dashboard',
        'console' => 'System process',
        'legacy_import' => 'Historical import',
        'provider_webhook' => 'Payment provider webhook',
        'provider_reconciliation' => 'Payment provider reconciliation',
    ];

    /** @var array<string, string> */
    private const RESULT_LABELS = [
        'completed' => 'Completed',
        'failed' => 'Failed',
        'denied' => 'Denied',
        'in_progress' => 'In progress',
        'needs_review' => 'Needs review',
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

    /** @return array<int, string> */
    public static function sourceValues(): array
    {
        return array_keys(self::SOURCE_LABELS);
    }

    /** @return array<int, string> */
    public static function resultValues(): array
    {
        return array_keys(self::RESULT_LABELS);
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
                'label' => $this->friendlyTargetType($value),
            ],
            self::targetTypeValues(),
        ));
    }

    /** @return array<int, array{value: string, label: string}> */
    public function resultOptions(): array
    {
        return array_map(
            fn (string $value): array => ['value' => $value, 'label' => self::RESULT_LABELS[$value]],
            array_keys(self::RESULT_LABELS),
        );
    }

    /** @return array<int, array{value: string, label: string}> */
    public function sourceOptions(): array
    {
        return array_map(
            fn (string $value): array => ['value' => $value, 'label' => self::SOURCE_LABELS[$value]],
            array_keys(self::SOURCE_LABELS),
        );
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
            ->orderBy('created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');

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
            $query->where('properties->correlation_id', $filters['correlation_id']);
        }

        foreach (['search', 'actor_search', 'target_search'] as $searchFilter) {
            $term = trim((string) ($filters[$searchFilter] ?? ''));
            if ($term === '') {
                continue;
            }

            $this->applyTextFilter($query, $term, $searchFilter);
        }

        if (! empty($filters['result'])) {
            $query->whereIn('event', $this->eventsForResult((string) $filters['result']));
        }

        if (! empty($filters['source'])) {
            $query->where('properties->source', $filters['source']);
        }

        if (! empty($filters['ip_address'])) {
            if ($viewer->role !== SuperAdmin::ROLE_SUPER_ADMIN) {
                $query->where('causer_type', SuperAdmin::class)->where('causer_id', (int) $viewer->getKey());
            }
            $query->where('properties->ip_address', $filters['ip_address']);
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
            'audit_reference' => sprintf('AUD-%08d', (int) $activity->getKey()),
            'event' => $event,
            'event_label' => $event === 'unclassified'
                ? 'Unclassified privileged event'
                : self::EVENT_LABELS[$event],
            'actor' => [
                'id' => $actorId,
                'type' => $actorId === null ? 'system' : 'super_admin',
                'label' => $this->actorLabel($activity, $actorId),
                'role' => $this->safeRole($properties['actor_role'] ?? $activity->causer?->role),
            ],
            'target' => [
                'id' => $targetId,
                'internal_type' => $this->targetInternalType($activity, $properties),
                'type' => $this->targetType($activity, $properties),
                'label' => $this->targetLabel($activity),
            ],
            'outcome' => $this->outcome($properties),
            'result' => [
                'key' => $this->resultKey($event),
                'label' => self::RESULT_LABELS[$this->resultKey($event)],
            ],
            'source' => $source,
            'source_label' => self::SOURCE_LABELS[$source] ?? 'Unknown source',
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
            return $name !== '' ? $name : 'Administrator';
        }

        return $actorId === null ? 'System' : 'Administrator';
    }

    private function targetType(Activity $activity, array $properties): string
    {
        $classToType = array_flip(self::TARGET_CLASSES);
        if (isset($classToType[$activity->subject_type])) {
            return $this->friendlyTargetType($classToType[$activity->subject_type]);
        }

        $propertyType = $properties['target_type'] ?? null;
        if (is_string($propertyType) && in_array($propertyType, self::targetTypeValues(), true)) {
            return $this->friendlyTargetType($propertyType);
        }

        return 'Record';
    }

    private function targetInternalType(Activity $activity, array $properties): string
    {
        $classToType = array_flip(self::TARGET_CLASSES);
        $type = $classToType[$activity->subject_type] ?? ($properties['target_type'] ?? null);

        return is_string($type) && in_array($type, self::targetTypeValues(), true) ? $type : 'unknown';
    }

    private function friendlyTargetType(string $type): string
    {
        return match ($type) {
            'user' => 'Customer account',
            'shop_owner' => 'Shop account',
            'super_admin' => 'Administrator',
            'premium_plan' => 'Subscription plan',
            'shop_owner_subscription' => 'Subscription',
            'maintenance_window' => 'Maintenance window',
            'identity_verification' => 'Identity verification',
            default => Str::headline($type),
        };
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

        if ($subject instanceof MaintenanceWindow) {
            return trim((string) $subject->title) ?: 'Maintenance window';
        }

        if ($subject instanceof ShopOwnerSubscription) {
            return Str::headline((string) ($subject->plan_code ?: 'subscription'));
        }

        return match (true) {
            $subject instanceof ShopDocument => 'Private document',
            $subject instanceof ReviewReport => 'Flagged account report',
            $subject instanceof SuspensionAppeal => 'Suspension appeal',
            $subject instanceof ShopOwnerUpgradeRequest => 'Shop owner upgrade request',
            $subject instanceof ShopReportModerationAction => 'Shop report moderation',
            $subject instanceof AccountSuspension => 'Account suspension',
            $subject instanceof IdentityVerification => 'Identity verification',
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
        return is_string($source) && in_array($source, self::sourceValues(), true)
            ? $source
            : 'unknown';
    }

    private function resultKey(string $event): string
    {
        if (in_array($event, ['privileged_capability_denied'], true) || str_ends_with($event, '_rejected')) {
            return 'denied';
        }

        if ($event === 'privileged_workflow_conflict' || $event === 'subscription_refund_unknown') {
            return 'needs_review';
        }

        if (in_array($event, ['subscription_refund_initiated', 'subscription_refund_processing'], true)) {
            return 'in_progress';
        }

        if (str_ends_with($event, '_failed')) {
            return 'failed';
        }

        return 'completed';
    }

    /** @return array<int, string> */
    private function eventsForResult(string $result): array
    {
        return array_values(array_filter(
            self::eventValues(),
            fn (string $event): bool => $this->resultKey($event) === $result,
        ));
    }

    private function applyTextFilter(Builder $query, string $term, string $filter): void
    {
        $like = '%'.addcslashes($term, '\\%_').'%';
        $query->where(function (Builder $matches) use ($like, $filter): void {
            if ($filter !== 'actor_search') {
                $matches->whereHasMorph('subject', [
                    User::class,
                    ShopOwner::class,
                    SuperAdmin::class,
                    ShopDocument::class,
                    PremiumPlan::class,
                    MaintenanceWindow::class,
                    ShopOwnerSubscription::class,
                ], function (Builder $subject, string $type) use ($like): void {
                        $columns = match ($type) {
                            User::class => ['name', 'email'],
                            ShopOwner::class => ['business_name', 'email'],
                            SuperAdmin::class => ['first_name', 'last_name', 'email'],
                            ShopDocument::class => ['document_type', 'logical_slot'],
                            PremiumPlan::class => ['name'],
                            MaintenanceWindow::class => ['title'],
                            ShopOwnerSubscription::class => ['plan_code', 'status'],
                            default => [],
                        };

                        $subject->where(function (Builder $fields) use ($columns, $like): void {
                            foreach ($columns as $column) {
                                $fields->orWhere($column, 'like', $like);
                            }
                        });
                    });
                if ($filter === 'search') {
                    $matches->orWhere('event', 'like', $like);
                }
            }

            if ($filter !== 'target_search') {
                $matches->orWhereHasMorph('causer', [SuperAdmin::class], function (Builder $actor) use ($like): void {
                    $actor->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            }
        });
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
            'submitted_document_type',
            'logical_slot',
            'version_number',
            'predecessor_document_id',
            'issued_on',
            'expiration_mode',
            'expires_on',
            'submitted_issued_on',
            'submitted_expiration_mode',
            'submitted_expires_on',
            'mime',
            'disposition',
            'method',
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
            if ($key === 'method' && ! in_array($value, ['totp', 'recovery_code'], true)) {
                continue;
            }
            if (is_int($value) || is_bool($value)) {
                $metadata[$key] = $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $metadata[$key] = Str::limit(preg_replace('/\s+/', ' ', trim($value)) ?? '', 200, '...');
            }
        }

        return $metadata;
    }
}
