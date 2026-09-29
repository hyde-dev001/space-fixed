<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use JsonException;

final class LegacyPrivilegedAuditMapper
{
    /**
     * @return array{status: 'importable', record: array<string, mixed>}
     *     |array{status: 'skipped', reason: string}
     */
    public function map(AuditLog $audit): array
    {
        $action = trim((string) $audit->action);
        $events = [
            'user_suspended' => 'user_suspended',
            'user_activated' => 'user_reactivated',
            'shop_activated' => 'shop_reactivated',
            'shop_owner_approved' => 'shop_registration_approved',
            'shop_report_dismiss' => 'shop_reports_moderated',
            'shop_report_warn' => 'shop_reports_moderated',
            'shop_report_suspend' => 'shop_reports_moderated',
            'suspension_appeal_approved' => 'suspension_appeal_decided',
            'suspension_appeal_rejected' => 'suspension_appeal_decided',
        ];

        if (! array_key_exists($action, $events)) {
            return $this->skip('action_not_allowlisted');
        }

        $data = $this->decodeJson($audit, 'data');
        $metadata = $this->decodeJson($audit, 'metadata');
        if ($data === null || $metadata === null) {
            return $this->skip('malformed_json');
        }

        return match (true) {
            str_starts_with($action, 'shop_report_') => $this->mapReport(
                $audit,
                $action,
                $events[$action],
                $data,
            ),
            str_starts_with($action, 'suspension_appeal_') => $this->mapAppeal(
                $audit,
                $action,
                $events[$action],
                $data,
            ),
            default => $this->mapAccountAction(
                $audit,
                $action,
                $events[$action],
                $data,
                $metadata,
            ),
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     * @return array{status: 'importable', record: array<string, mixed>}
     *     |array{status: 'skipped', reason: string}
     */
    private function mapAccountAction(
        AuditLog $audit,
        string $action,
        string $event,
        array $data,
        array $metadata,
    ): array {
        $expectedType = in_array($action, ['user_suspended', 'user_activated'], true)
            ? 'user'
            : 'shop_owner';

        if ($this->normalizeTargetType($audit->target_type) !== $expectedType) {
            return $this->skip('target_type_invalid');
        }

        if ($this->hasConflictingObjectType($audit->object_type, $expectedType)) {
            return $this->skip('target_conflict');
        }

        $targetId = $this->consistentId([
            $audit->target_id,
            $audit->object_id,
        ]);
        if ($targetId === null) {
            return $this->skip('target_conflict');
        }

        $subject = $expectedType === 'user'
            ? User::query()->find($targetId)
            : ShopOwner::query()->find($targetId);
        if (! $subject instanceof Model) {
            return $this->skip('target_missing');
        }

        $actor = $this->resolveActor($audit);
        if ($actor['status'] !== 'importable') {
            return $actor;
        }

        $properties = $this->baseProperties(
            audit: $audit,
            event: $event,
            actor: $actor['actor'],
            subject: $subject,
            action: $action,
        );
        $newStatus = match ($action) {
            'user_suspended' => 'suspended',
            'user_activated' => 'active',
            'shop_activated', 'shop_owner_approved' => 'approved',
        };
        $properties['new_status'] = $newStatus;

        $priorStatus = $this->safeStatus($data['prior_status'] ?? $metadata['prior_status'] ?? null);
        if ($priorStatus !== null) {
            $properties['prior_status'] = $priorStatus;
        }

        return $this->importable(
            audit: $audit,
            event: $event,
            actor: $actor['actor'],
            subject: $subject,
            properties: $properties,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: 'importable', record: array<string, mixed>}
     *     |array{status: 'skipped', reason: string}
     */
    private function mapReport(
        AuditLog $audit,
        string $action,
        string $event,
        array $data,
    ): array {
        if ($this->normalizeTargetType($audit->target_type) !== 'shop_owner') {
            return $this->skip('target_type_invalid');
        }

        if ($this->hasConflictingObjectType($audit->object_type, 'shop_owner')) {
            return $this->skip('target_conflict');
        }

        $shopId = $this->consistentId([
            $audit->target_id,
            $audit->object_id,
            $audit->shop_owner_id,
            $data['shop_owner_id'] ?? null,
        ]);
        if ($shopId === null) {
            return $this->skip('target_conflict');
        }

        $shop = ShopOwner::query()->find($shopId);
        if (! $shop instanceof ShopOwner) {
            return $this->skip('target_missing');
        }

        $outcomeFromAction = Str::after($action, 'shop_report_');
        $requestedAction = $this->safeDecision($data['requested_action'] ?? $outcomeFromAction);
        $appliedAction = $this->safeDecision($data['applied_action'] ?? $outcomeFromAction);
        if ($requestedAction === null || $appliedAction === null) {
            return $this->skip('report_outcome_unrecognized');
        }

        $adminId = $this->positiveInt($data['admin_id'] ?? null);
        if ($adminId === null) {
            return $this->skip('actor_unknown');
        }

        $actorUserId = $this->positiveInt($audit->actor_user_id);
        if ($actorUserId !== null && $actorUserId !== $adminId) {
            return $this->skip('actor_conflict');
        }

        $actor = SuperAdmin::query()->find($adminId);
        if (! $actor instanceof SuperAdmin) {
            return $this->skip('actor_unknown');
        }

        $properties = $this->baseProperties(
            audit: $audit,
            event: $event,
            actor: $actor,
            subject: $shop,
            action: $action,
        );
        $properties['requested_action'] = $requestedAction;
        $properties['applied_action'] = $appliedAction;
        $properties['outcome'] = $appliedAction;

        foreach (['report_count', 'warning_strike', 'warning_limit'] as $key) {
            $value = $this->nonNegativeInt($data[$key] ?? null);
            if ($value !== null) {
                $properties[$key] = $value;
            }
        }

        return $this->importable(
            audit: $audit,
            event: $event,
            actor: $actor,
            subject: $shop,
            properties: $properties,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: 'importable', record: array<string, mixed>}
     *     |array{status: 'skipped', reason: string}
     */
    private function mapAppeal(
        AuditLog $audit,
        string $action,
        string $event,
        array $data,
    ): array {
        $rawTargetType = $this->normalizeTargetType($audit->target_type);
        if ($rawTargetType === null) {
            return $this->skip('target_type_invalid');
        }

        $appeal = null;
        $accountType = null;
        $accountId = null;

        if ($rawTargetType === 'suspension_appeal') {
            $appealId = $this->consistentId([$audit->target_id, $audit->object_id]);
            if ($appealId === null) {
                return $this->skip('target_conflict');
            }

            $appeal = SuspensionAppeal::query()->find($appealId);
            if (! $appeal instanceof SuspensionAppeal) {
                return $this->skip('appeal_missing');
            }

            $accountType = $this->normalizeAccountType($appeal->account_type);
            $accountId = $this->positiveInt($appeal->account_id);
            if ($accountType === null || $accountId === null) {
                return $this->skip('target_type_invalid');
            }

            $account = $accountType === 'user'
                ? User::query()->find($accountId)
                : ShopOwner::query()->find($accountId);
            if (! $account instanceof Model) {
                return $this->skip('target_missing');
            }
        } else {
            $accountType = $rawTargetType;
            $accountId = $this->consistentId([
                $audit->target_id,
                $audit->object_id,
                $data['account_id'] ?? null,
            ]);
            if ($accountId === null) {
                return $this->skip('target_conflict');
            }

            $account = $accountType === 'user'
                ? User::query()->find($accountId)
                : ShopOwner::query()->find($accountId);
            if (! $account instanceof Model) {
                return $this->skip('target_missing');
            }

            $appealId = $this->positiveInt($data['appeal_id'] ?? null);
            $appealQuery = SuspensionAppeal::query()
                ->where('account_type', $accountType === 'user' ? 'customer' : 'shop_owner')
                ->where('account_id', $accountId);

            if ($appealId !== null) {
                $appealQuery->whereKey($appealId);
            }

            $appeals = $appealQuery->get();
            if ($appeals->count() !== 1) {
                return $this->skip('appeal_missing');
            }

            $appeal = $appeals->first();
        }

        if (! $appeal instanceof SuspensionAppeal || $accountId === null || $accountType === null) {
            return $this->skip('appeal_missing');
        }

        $actor = $this->resolveActor($audit);
        if ($actor['status'] !== 'importable') {
            return $actor;
        }

        $properties = $this->baseProperties(
            audit: $audit,
            event: $event,
            actor: $actor['actor'],
            subject: $appeal,
            action: $action,
        );
        $properties['decision'] = Str::after($action, 'suspension_appeal_');
        $properties['appeal_id'] = (int) $appeal->getKey();
        $properties['account_type'] = $accountType;
        $properties['account_id'] = $accountId;

        return $this->importable(
            audit: $audit,
            event: $event,
            actor: $actor['actor'],
            subject: $appeal,
            properties: $properties,
        );
    }

    /**
     * @return array{status: 'importable', actor: SuperAdmin}
     *     |array{status: 'skipped', reason: string}
     */
    private function resolveActor(AuditLog $audit): array
    {
        $actorId = $this->positiveInt($audit->actor_user_id);
        if ($actorId === null) {
            $legacyUserId = $this->positiveInt($audit->user_id);
            if ($legacyUserId !== null
                && User::query()->whereKey($legacyUserId)->exists()
                && SuperAdmin::query()->whereKey($legacyUserId)->exists()) {
                return $this->skip('actor_ambiguous');
            }

            return $this->skip('actor_unknown');
        }

        $actor = SuperAdmin::query()->find($actorId);
        if (! $actor instanceof SuperAdmin) {
            return $this->skip('actor_unknown');
        }

        return [
            'status' => 'importable',
            'actor' => $actor,
        ];
    }

    /**
     * @param array<int, mixed> $values
     */
    private function consistentId(array $values): ?int
    {
        $ids = [];
        foreach ($values as $value) {
            $id = $this->positiveInt($value);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $unique = array_values(array_unique($ids));
        return count($unique) <= 1 ? ($unique[0] ?? null) : null;
    }

    private function hasConflictingObjectType(mixed $objectType, string $expectedType): bool
    {
        if ($objectType === null || trim((string) $objectType) === '') {
            return false;
        }

        return $this->normalizeTargetType($objectType) !== $expectedType;
    }

    private function normalizeTargetType(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return match (strtolower(trim($value))) {
            'user', 'customer' => 'user',
            'shop', 'shop_owner', 'shopowner' => 'shop_owner',
            'appeal', 'suspension_appeal', 'suspensionappeal' => 'suspension_appeal',
            default => null,
        };
    }

    private function normalizeAccountType(mixed $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'customer', 'user' => 'user',
            'shop_owner', 'shopowner', 'shop' => 'shop_owner',
            default => null,
        };
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value && $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return min($value, 1_000_000);
        }

        if (is_string($value) && ctype_digit($value)) {
            return min((int) $value, 1_000_000);
        }

        return null;
    }

    private function safeDecision(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        return in_array($value, ['dismiss', 'warn', 'suspend'], true) ? $value : null;
    }

    private function safeStatus(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        return in_array($value, [
            'active',
            'approved',
            'archived',
            'deactivated',
            'inactive',
            'pending',
            'rejected',
            'suspended',
        ], true) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(AuditLog $audit, string $attribute): ?array
    {
        $raw = $audit->getRawOriginal($attribute);
        if ($raw === null) {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        try {
            $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseProperties(
        AuditLog $audit,
        string $event,
        SuperAdmin $actor,
        Model $subject,
        string $action,
    ): array {
        return [
            'actor_type' => 'super_admin',
            'actor_guard' => 'super_admin',
            'actor_id' => (int) $actor->getKey(),
            'actor_role' => 'legacy_unknown',
            'actor_role_verified' => false,
            'event' => $event,
            'target_type' => Str::snake(class_basename($subject)),
            'target_id' => (int) $subject->getKey(),
            'source' => 'legacy_import',
            'correlation_id' => (string) Str::uuid(),
            'legacy_action' => $action,
            'legacy_source' => 'audit_logs',
            'legacy_id' => (int) $audit->getKey(),
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @return array{status: 'importable', record: array<string, mixed>}
     */
    private function importable(
        AuditLog $audit,
        string $event,
        SuperAdmin $actor,
        Model $subject,
        array $properties,
    ): array {
        $createdAt = $audit->getRawOriginal('created_at');
        $updatedAt = $audit->getRawOriginal('updated_at') ?? $createdAt;

        return [
            'status' => 'importable',
            'record' => [
                'event' => $event,
                'actor' => $actor,
                'subject' => $subject,
                'properties' => $properties,
                'legacy_source' => 'audit_logs',
                'legacy_id' => (int) $audit->getKey(),
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ],
        ];
    }

    /** @return array{status: 'skipped', reason: string} */
    private function skip(string $reason): array
    {
        return [
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }
}
