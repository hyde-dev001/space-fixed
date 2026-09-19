<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopOwner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

final class OwnerOperationAudit
{
    /** @var array<int, string> */
    private const CONTEXT_KEYS = [
        'correlation_id',
        'reason_code',
        'source',
        'guard',
        'target_type',
        'target_id',
        'before',
        'after',
    ];

    /** @var array<int, string> */
    private const SNAPSHOT_KEYS = [
        'status',
        'enabled',
        'state',
        'stage',
        'current_approval_level',
        'module_key',
        'reason_code',
        'source',
        'guard',
        'result',
    ];

    /**
     * Record one owner operation inside the caller's existing transaction.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        ShopOwner $actor,
        int $tenantOwnerId,
        string $module,
        string $action,
        string $result,
        ?Model $subject = null,
        array $context = [],
    ): Activity {
        $this->assertTenant($actor, $tenantOwnerId);

        $module = $this->requiredValue($module, 'module');
        $action = $this->requiredValue($action, 'action');
        $result = $this->requiredValue($result, 'result');

        if ($subject instanceof Model && array_key_exists('shop_owner_id', $subject->getAttributes())
            && (int) $subject->getAttribute('shop_owner_id') !== $tenantOwnerId) {
            throw new AuthorizationException('The owner operation subject does not belong to the authenticated owner.');
        }

        $properties = [
            'shop_owner_id' => $tenantOwnerId,
            'module' => $module,
            'action' => $action,
            'result' => $result,
            'correlation_id' => $this->correlationId($context['correlation_id'] ?? null),
        ];

        foreach (self::CONTEXT_KEYS as $key) {
            if ($key === 'correlation_id' || ! array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];
            if ($key === 'before' || $key === 'after') {
                if (is_array($value)) {
                    $properties[$key] = $this->safeSnapshot($value);
                }

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $properties[$key] = $value;
            }
        }

        $logger = activity('owner_operation')
            ->causedBy($actor)
            ->setEvent($action)
            ->withProperties($properties);

        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        $activity = $logger->log($action);

        if (! $activity instanceof Activity) {
            throw new RuntimeException('Owner operation audit logging is disabled.');
        }

        return $activity;
    }

    private function correlationId(mixed $value): string
    {
        if ($value === null) {
            return (string) Str::uuid();
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException('correlation_id must be scalar.');
        }

        $value = trim((string) $value);

        return $value === '' ? (string) Str::uuid() : $value;
    }

    private function assertTenant(ShopOwner $actor, int $tenantOwnerId): void
    {
        if ($tenantOwnerId < 1 || (int) $actor->getKey() !== $tenantOwnerId) {
            throw new AuthorizationException('The owner operation tenant does not match the authenticated owner.');
        }
    }

    private function requiredValue(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("{$field} must not be empty.");
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $snapshot
     * @return array<string, scalar|null>
     */
    private function safeSnapshot(array $snapshot): array
    {
        $safe = [];

        foreach (self::SNAPSHOT_KEYS as $key) {
            if (! array_key_exists($key, $snapshot)) {
                continue;
            }

            $value = $snapshot[$key];
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
