<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ManagerAuditLogService
{
    public function __construct(
        private readonly ManagerAuthorizationService $authorization,
    ) {
    }

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'api_token',
        'card_number',
        'cvv',
        'bank_account',
        'ssn',
        'tax_id',
        'ip_address',
        'user_agent',
    ];

    /**
     * Return one tenant-scoped, paginated contract over the audit sources that
     * already exist in the application. The sources are normalized in SQL so
     * actor/target hydration remains bounded to the current page.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function list(User $manager, array $filters): array
    {
        $shopOwnerId = $this->authorization->shopOwnerId($manager);

        if ($shopOwnerId === null) {
            throw new AccessDeniedHttpException('Manager shop scope is required.');
        }

        $query = $this->auditQuery($shopOwnerId);
        $this->applyFilters($query, $filters);

        $total = (clone $query)->count();
        $stats = [
            'total_logs' => $total,
            'logs_last_24h' => (clone $query)
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'action_counts' => (clone $query)
                ->select('action', DB::raw('COUNT(*) as count'))
                ->groupBy('action')
                ->orderByDesc('count')
                ->pluck('count', 'action')
                ->map(fn ($count): int => (int) $count)
                ->toArray(),
        ];

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));
        $paginator = $query
            ->orderByDesc('created_at')
            ->orderByDesc('source_id')
            ->paginate($perPage)
            ->withQueryString();

        $rows = collect($paginator->items());
        $actors = $this->actorsFor($rows);

        $data = $rows
            ->map(fn (object $row): array => $this->mapRow($row, $actors))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => $this->paginationMeta($paginator),
            'stats' => $stats,
            'filters' => [
                'actions' => array_keys($stats['action_counts']),
                'severities' => ['info', 'warning', 'critical'],
            ],
            'last_updated_at' => now()->toISOString(),
        ];
    }

    private function auditQuery(int $shopOwnerId): Builder
    {
        $central = DB::table('audit_logs')
            ->where('shop_owner_id', $shopOwnerId)
            ->select([
                DB::raw("'central' as source"),
                'id as source_id',
                DB::raw('COALESCE(actor_user_id, user_id) as actor_id'),
                DB::raw("'App\\\\Models\\\\User' as actor_type"),
                'action',
                DB::raw('NULL as event'),
                'object_type as module',
                DB::raw('NULL as description'),
                DB::raw('COALESCE(target_type, object_type) as target_type'),
                DB::raw('COALESCE(target_id, object_id) as target_id'),
                DB::raw('NULL as old_values_json'),
                DB::raw('NULL as new_values_json'),
                'data as data_json',
                'metadata as context_json',
                DB::raw('NULL as severity'),
                'created_at',
            ]);

        $hr = DB::table('hr_audit_logs')
            ->where('shop_owner_id', $shopOwnerId)
            ->select([
                DB::raw("'hr' as source"),
                'id as source_id',
                'user_id as actor_id',
                DB::raw("'App\\\\Models\\\\User' as actor_type"),
                'action',
                DB::raw('NULL as event'),
                'module',
                'description',
                'entity_type as target_type',
                'entity_id as target_id',
                'old_values as old_values_json',
                'new_values as new_values_json',
                DB::raw('NULL as data_json'),
                'tags as context_json',
                'severity',
                'created_at',
            ]);

        $activity = DB::table('activity_log')
            ->where(function ($query) use ($shopOwnerId): void {
                $query
                    ->where(function ($shopOwnerQuery) use ($shopOwnerId): void {
                        $shopOwnerQuery
                            ->where('causer_type', ShopOwner::class)
                            ->where('causer_id', $shopOwnerId);
                    })
                    ->orWhere(function ($userQuery) use ($shopOwnerId): void {
                        $userQuery
                            ->where('causer_type', User::class)
                            ->whereIn('causer_id', function ($scopedUsers) use ($shopOwnerId): void {
                                $scopedUsers
                                    ->select('id')
                                    ->from('users')
                                    ->where('shop_owner_id', $shopOwnerId);
                            });
                    });
            })
            ->select([
                DB::raw("'activity' as source"),
                'id as source_id',
                'causer_id as actor_id',
                'causer_type as actor_type',
                DB::raw("COALESCE(event, 'activity') as action"),
                'event',
                'log_name as module',
                'description',
                'subject_type as target_type',
                'subject_id as target_id',
                DB::raw('NULL as old_values_json'),
                DB::raw('NULL as new_values_json'),
                DB::raw('NULL as data_json'),
                'properties as context_json',
                DB::raw('NULL as severity'),
                'created_at',
            ]);

        $union = $central->unionAll($hr)->unionAll($activity);

        return DB::query()->fromSub($union, 'manager_audit_logs');
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $action = trim((string) ($filters['action'] ?? ''));
        $event = trim((string) ($filters['event'] ?? ''));
        $module = trim((string) ($filters['module'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $target = trim((string) ($filters['target'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));

        if ($action !== '') {
            $query->where('action', $action);
        }

        if ($event !== '') {
            $query->where(function (Builder $eventQuery) use ($event): void {
                $eventQuery->where('event', $event)->orWhere('action', $event);
            });
        }

        if ($module !== '') {
            $query->where('module', 'like', "%{$module}%");
        }

        if (isset($filters['actor_id']) && is_numeric($filters['actor_id'])) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }

        if (isset($filters['target_id']) && is_numeric($filters['target_id'])) {
            $query->where('target_id', (int) $filters['target_id']);
        }

        if ($target !== '') {
            $query->where(function (Builder $targetQuery) use ($target): void {
                $targetQuery
                    ->where('target_type', 'like', "%{$target}%")
                    ->orWhere('target_id', $target);
            });
        }

        if ($status !== '') {
            $this->whereJsonTextContains($query, $status, [
                'old_values_json',
                'new_values_json',
                'data_json',
                'context_json',
            ]);
        }

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                foreach (['action', 'event', 'module', 'description', 'target_type', 'context_json'] as $column) {
                    $searchQuery->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        if (trim((string) ($filters['severity'] ?? '')) !== '') {
            $query->where('severity', trim((string) $filters['severity']));
        }

        if (($dateFrom = trim((string) ($filters['date_from'] ?? ''))) !== '') {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }

        if (($dateTo = trim((string) ($filters['date_to'] ?? ''))) !== '') {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }
    }

    /** @param list<string> $columns */
    private function whereJsonTextContains(Builder $query, string $value, array $columns): void
    {
        $query->where(function (Builder $jsonQuery) use ($value, $columns): void {
            foreach ($columns as $column) {
                $jsonQuery->orWhere($column, 'like', "%{$value}%");
            }
        });
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<string, array<string, mixed>>
     */
    private function actorsFor(Collection $rows): array
    {
        $userIds = $rows
            ->filter(fn (object $row): bool => $this->isUserActor((string) ($row->actor_type ?? '')))
            ->pluck('actor_id')
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $shopOwnerIds = $rows
            ->filter(fn (object $row): bool => $this->isShopOwnerActor((string) ($row->actor_type ?? '')))
            ->pluck('actor_id')
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $actors = [];

        if ($userIds->isNotEmpty()) {
            User::withTrashed()
                ->whereIn('id', $userIds)
                ->get(['id', 'first_name', 'last_name', 'name', 'email', 'role'])
                ->each(function (User $user) use (&$actors): void {
                    $actors['user:' . $user->id] = [
                        'id' => (int) $user->id,
                        'name' => $this->displayName($user),
                        'email' => (string) $user->email,
                        'role' => (string) ($user->role ?: 'Staff'),
                    ];
                });
        }

        if ($shopOwnerIds->isNotEmpty()) {
            ShopOwner::withTrashed()
                ->whereIn('id', $shopOwnerIds)
                ->get(['id', 'first_name', 'last_name', 'business_name', 'email'])
                ->each(function (ShopOwner $owner) use (&$actors): void {
                    $actors['owner:' . $owner->id] = [
                        'id' => (int) $owner->id,
                        'name' => $this->displayName($owner),
                        'email' => (string) $owner->email,
                        'role' => 'Shop Owner',
                    ];
                });
        }

        return $actors;
    }

    /** @param array<string, array<string, mixed>> $actors */
    private function mapRow(object $row, array $actors): array
    {
        $context = array_merge(
            $this->decodeArray($row->data_json ?? null),
            $this->decodeArray($row->context_json ?? null),
        );
        $previousState = $this->stateFrom($row->old_values_json ?? null, $context, false);
        $newState = $this->stateFrom($row->new_values_json ?? null, $context, true);
        $targetType = $this->normalizeType($row->target_type ?? null);
        $targetId = is_numeric($row->target_id ?? null) ? (int) $row->target_id : null;
        $actorType = (string) ($row->actor_type ?? '');
        $actorKey = $this->isShopOwnerActor($actorType) ? 'owner:' : 'user:';
        $actorId = is_numeric($row->actor_id ?? null) ? (int) $row->actor_id : null;
        $actor = $actorId !== null ? ($actors[$actorKey . $actorId] ?? [
            'id' => $actorId,
            'name' => 'Unavailable actor',
            'email' => null,
            'role' => 'Unknown',
        ]) : null;
        $action = strtolower(trim((string) ($row->action ?? 'activity')));
        $reason = $this->reasonFrom($context, $newState);
        $referenceId = $this->firstString($context, [
            'reference_id',
            'reference_number',
            'request_id',
            'repair_request_id',
            'order_number',
        ]) ?? $this->firstString($newState, [
            'reference_id',
            'reference_number',
            'request_id',
            'repair_request_id',
            'order_number',
        ]);
        $correlationId = $this->firstString($context, [
            'correlation_id',
            'correlationId',
            'batch_uuid',
        ]);

        return [
            'id' => (string) $row->source . ':' . (string) $row->source_id,
            'source' => (string) $row->source,
            'source_id' => (int) $row->source_id,
            'action' => $action,
            'event' => strtolower(trim((string) ($row->event ?: $action))),
            'description' => trim((string) ($row->description ?? '')) ?: Str::headline($action),
            'actor' => $actor,
            'target' => [
                'type' => $targetType,
                'id' => $targetId,
                'label' => $this->targetLabel($targetType, $targetId, $context),
            ],
            'created_at' => $row->created_at,
            'previous_state' => $previousState,
            'new_state' => $newState,
            'reason' => $reason,
            'reference_id' => $referenceId,
            'correlation_id' => $correlationId,
            'severity' => (string) ($row->severity ?: $this->severityFor($action)),
            'metadata' => [
                'module' => $row->module,
                'source' => $row->source,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function stateFrom(mixed $stored, array $context, bool $new): array
    {
        $state = $this->decodeArray($stored);
        if ($state !== []) {
            return $this->sanitizeState($state);
        }

        $stateKey = $new ? 'new_state' : 'previous_state';
        if (is_array($context[$stateKey] ?? null)) {
            return $this->sanitizeState($context[$stateKey]);
        }

        $snapshotKey = $new ? 'attributes' : 'old';
        if (is_array($context[$snapshotKey] ?? null)) {
            return $this->sanitizeState($context[$snapshotKey]);
        }

        $statusKey = $new ? 'new_status' : 'old_status';
        if (array_key_exists($statusKey, $context)) {
            return ['status' => $context[$statusKey]];
        }

        $statusKey = $new ? 'new_status' : 'previous_status';
        if (array_key_exists($statusKey, $context)) {
            return ['status' => $context[$statusKey]];
        }

        $assigneeKeys = $new
            ? ['replacement_handler_id', 'replacement_repairer_id', 'selected_repairer_id']
            : ['previous_handler_id', 'previous_repairer_id'];
        foreach ($assigneeKeys as $assigneeKey) {
            if (array_key_exists($assigneeKey, $context)) {
                return ['assigned_user_id' => $context[$assigneeKey]];
            }
        }

        $statusKey = $new ? 'new_state' : 'previous_state';
        if (array_key_exists($statusKey, $context) && ! is_array($context[$statusKey])) {
            return ['status' => $context[$statusKey]];
        }

        return [];
    }

    /** @param array<string, mixed> $state */
    private function sanitizeState(array $state): array
    {
        $clean = [];
        foreach ($state as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $clean[(string) $key] = is_array($value)
                ? $this->sanitizeState($value)
                : $value;
        }

        return $clean;
    }

    /** @param array<string, mixed> $context */
    private function reasonFrom(array $context, array $newState): ?string
    {
        foreach (['reason', 'rejection_reason', 'manager_review_notes', 'notes'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key]) && trim((string) $context[$key]) !== '') {
                return trim((string) $context[$key]);
            }
        }

        foreach (['reason', 'rejection_reason'] as $key) {
            if (isset($newState[$key]) && is_scalar($newState[$key]) && trim((string) $newState[$key]) !== '') {
                return trim((string) $newState[$key]);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $context */
    private function targetLabel(?string $targetType, ?int $targetId, array $context): string
    {
        $reference = $this->firstString($context, [
            'reference_id',
            'reference_number',
            'request_id',
            'repair_request_id',
            'order_number',
        ]);

        if ($reference !== null) {
            return $reference;
        }

        $label = $targetType !== null ? Str::headline($targetType) : 'Record';

        return $targetId !== null ? $label . ' #' . $targetId : $label;
    }

    /** @param array<string, mixed> $context */
    private function firstString(array $context, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($context[$key]) && is_scalar($context[$key]) && trim((string) $context[$key]) !== '') {
                return trim((string) $context[$key]);
            }
        }

        return null;
    }

    private function normalizeType(mixed $type): ?string
    {
        $type = trim((string) $type);

        return $type === '' ? null : Str::snake(class_basename($type));
    }

    /** @return array<string, mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function severityFor(string $action): string
    {
        return str_contains($action, 'reject') || str_contains($action, 'reassign') || str_contains($action, 'approve')
            ? 'warning'
            : 'info';
    }

    private function isUserActor(string $type): bool
    {
        return $type === '' || $type === User::class || str_ends_with($type, '\\User');
    }

    private function isShopOwnerActor(string $type): bool
    {
        return $type === ShopOwner::class || str_ends_with($type, '\\ShopOwner');
    }

    private function displayName(User|ShopOwner $actor): string
    {
        $name = trim((string) ($actor->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($actor->first_name ?? '') . ' ' . (string) ($actor->last_name ?? ''))
            ?: (string) $actor->email;
    }

    /** @return array<string, int|null> */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
