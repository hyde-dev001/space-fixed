<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter;

use App\Enums\OwnerActionCenterDegradationStatus;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerActionCenterResult;
use App\Support\OwnerActionCenter\OwnerAttentionAdapterResult;
use App\Support\OwnerActionCenter\OwnerAttentionItem;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use TypeError;

final class OwnerActionCenterService
{
    public function __construct(
        private readonly OwnerAttentionAdapterRegistry $adapterRegistry,
    ) {}

    public function summaryForHome(ShopOwner $owner): OwnerActionCenterResult
    {
        $homeLimit = config('owner_action_center.home_limit', 5);
        if (! is_int($homeLimit) || $homeLimit < 1 || $homeLimit > OwnerAttentionQuery::MAX_PER_PAGE) {
            throw new InvalidArgumentException('Owner Action Center Home limit is out of bounds.');
        }

        return $this->read(
            $owner,
            new OwnerAttentionQuery(
                perPage: $homeLimit,
                candidateLimit: $homeLimit,
            ),
        );
    }

    public function queueForActionCenter(ShopOwner $owner, OwnerAttentionQuery $query): OwnerActionCenterResult
    {
        return $this->read($owner, $query);
    }

    private function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerActionCenterResult
    {
        $startedAt = microtime(true);
        $adapters = $this->adapterRegistry->adaptersFor($query->coverage);
        $enabledAdapterKeys = array_map(
            static fn ($adapter): string => $adapter->adapterKey(),
            $adapters,
        );

        if ($adapters === []) {
            $result = $this->result(
                items: [],
                coverageCounts: ['refunds' => 0, 'expenses' => 0, 'purchase_requests' => 0],
                enabledAdapterKeys: [],
                healthyAdapterKeys: [],
                failedAdapterKeys: [],
                degradationStatus: OwnerActionCenterDegradationStatus::NoEnabledAdapters,
                query: $query,
            );

            $this->logRead($owner, $result, $startedAt);

            return $result;
        }

        /** @var array<string, OwnerAttentionItem> $itemsByKey */
        $itemsByKey = [];
        /** @var array<string, int> $reportedRemainders */
        $reportedRemainders = ['refunds' => 0, 'expenses' => 0, 'purchase_requests' => 0];
        $healthyAdapterKeys = [];
        $failedAdapterKeys = [];

        foreach ($adapters as $adapter) {
            $startedAt = microtime(true);

            try {
                $adapterResult = $adapter->read($owner, $query);
                if (! $adapterResult instanceof OwnerAttentionAdapterResult) {
                    throw new TypeError('Owner attention adapters must return OwnerAttentionAdapterResult.');
                }

                $healthyAdapterKeys[] = $adapter->adapterKey();
                $seenByAdapter = [];
                foreach ($adapterResult->items as $item) {
                    if (isset($seenByAdapter[$item->attentionKey])) {
                        continue;
                    }

                    $seenByAdapter[$item->attentionKey] = true;
                    $itemsByKey[$item->attentionKey] ??= $item;
                }

                $reportedRemainders[$adapter->coverageSource()] += max(
                    0,
                    $adapterResult->qualifyingCount - count($adapterResult->items),
                );

                Log::info('owner_action_center.adapter_read', [
                    'shop_id' => (int) $owner->getKey(),
                    'adapter_key' => $adapter->adapterKey(),
                    'coverage_source' => $adapter->coverageSource(),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'result_count' => count($adapterResult->items),
                    'correlation_id' => $this->correlationId(),
                ]);
            } catch (AuthorizationException|ModelNotFoundException $exception) {
                report($exception);
                throw $exception;
            } catch (InvalidArgumentException|TypeError $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $failedAdapterKeys[] = $adapter->adapterKey();
                report($exception);

                Log::warning('owner_action_center.adapter_failed', [
                    'shop_id' => (int) $owner->getKey(),
                    'adapter_key' => $adapter->adapterKey(),
                    'coverage_source' => $adapter->coverageSource(),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'correlation_id' => $this->correlationId(),
                ]);
            }
        }

        $items = array_values($itemsByKey);
        usort($items, [$this, 'compareItems']);

        $coverageCounts = ['refunds' => 0, 'expenses' => 0, 'purchase_requests' => 0];
        foreach ($items as $item) {
            $coverageCounts[$this->coverageFor($item)]++;
        }

        foreach ($reportedRemainders as $coverage => $remainder) {
            $coverageCounts[$coverage] += $remainder;
        }

        $degradationStatus = match (true) {
            $failedAdapterKeys === [] => OwnerActionCenterDegradationStatus::None,
            $healthyAdapterKeys === [] => OwnerActionCenterDegradationStatus::Unavailable,
            default => OwnerActionCenterDegradationStatus::Partial,
        };

        $result = $this->result(
            items: $items,
            coverageCounts: $coverageCounts,
            enabledAdapterKeys: $enabledAdapterKeys,
            healthyAdapterKeys: $healthyAdapterKeys,
            failedAdapterKeys: $failedAdapterKeys,
            degradationStatus: $degradationStatus,
            query: $query,
        );

        $this->logRead($owner, $result, $startedAt);

        return $result;
    }

    /**
     * @param array<int, OwnerAttentionItem> $items
     * @param array<string, int> $coverageCounts
     * @param array<int, string> $enabledAdapterKeys
     * @param array<int, string> $healthyAdapterKeys
     * @param array<int, string> $failedAdapterKeys
     */
    private function result(
        array $items,
        array $coverageCounts,
        array $enabledAdapterKeys,
        array $healthyAdapterKeys,
        array $failedAdapterKeys,
        OwnerActionCenterDegradationStatus $degradationStatus,
        OwnerAttentionQuery $query,
    ): OwnerActionCenterResult {
        $total = array_sum($coverageCounts);
        $lastPage = max(1, (int) ceil($total / $query->perPage));
        $page = min($query->page, $lastPage);
        $offset = ($page - 1) * $query->perPage;

        return new OwnerActionCenterResult(
            items: array_slice($items, $offset, $query->perPage),
            coverageCounts: $coverageCounts,
            enabledAdapterKeys: $enabledAdapterKeys,
            healthyAdapterKeys: $healthyAdapterKeys,
            failedAdapterKeys: $failedAdapterKeys,
            degradationStatus: $degradationStatus,
            coverage: $query->coverage,
            page: $page,
            perPage: $query->perPage,
            total: $total,
            lastPage: $lastPage,
        );
    }

    private function compareItems(OwnerAttentionItem $left, OwnerAttentionItem $right): int
    {
        $priority = ['critical' => 4, 'high' => 3, 'normal' => 2, 'low' => 1];
        $priorityComparison = $priority[$right->priorityTier] <=> $priority[$left->priorityTier];
        if ($priorityComparison !== 0) {
            return $priorityComparison;
        }

        $materiality = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'none' => 0];
        $materialityComparison = $materiality[$right->materialityTier] <=> $materiality[$left->materialityTier];
        if ($materialityComparison !== 0) {
            return $materialityComparison;
        }

        if ($left->comparableMonetaryExposure !== $right->comparableMonetaryExposure) {
            if ($left->comparableMonetaryExposure === null) {
                return 1;
            }

            if ($right->comparableMonetaryExposure === null) {
                return -1;
            }

            $exposureComparison = $right->comparableMonetaryExposure <=> $left->comparableMonetaryExposure;
            if ($exposureComparison !== 0) {
                return $exposureComparison;
            }
        }

        $urgencyComparison = $this->compareNullableDates($left->urgencyAt, $right->urgencyAt);
        if ($urgencyComparison !== 0) {
            return $urgencyComparison;
        }

        $actionableComparison = $this->timestamp($left->actionableSince) <=> $this->timestamp($right->actionableSince);
        if ($actionableComparison !== 0) {
            return $actionableComparison;
        }

        $sourceTypeComparison = $left->sourceType <=> $right->sourceType;
        if ($sourceTypeComparison !== 0) {
            return $sourceTypeComparison;
        }

        $sourceIdComparison = $left->sourceId <=> $right->sourceId;
        if ($sourceIdComparison !== 0) {
            return $sourceIdComparison;
        }

        return $left->attentionKey <=> $right->attentionKey;
    }

    private function compareNullableDates(?string $left, ?string $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        return $this->timestamp($left) <=> $this->timestamp($right);
    }

    private function timestamp(string $value): int
    {
        return (new DateTimeImmutable($value))->getTimestamp();
    }

    private function coverageFor(OwnerAttentionItem $item): string
    {
        return $item->coverageSource;
    }

    private function correlationId(): ?string
    {
        $value = request()->header('X-Request-ID') ?? request()->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/', $value) === 1
            ? $value
            : null;
    }

    private function logRead(ShopOwner $owner, OwnerActionCenterResult $result, float $startedAt): void
    {
        Log::info('owner_action_center.read', [
            'shop_id' => (int) $owner->getKey(),
            'enabled_adapter_keys' => array_values($result->enabledAdapterKeys),
            'healthy_adapter_keys' => array_values($result->healthyAdapterKeys),
            'failed_adapter_keys' => array_values($result->failedAdapterKeys),
            'degradation_status' => $result->degradationStatus->value,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'result_count' => count($result->items),
            'source' => $result->coverage,
            'page' => $result->page,
            'per_page' => $result->perPage,
            'correlation_id' => $this->correlationId(),
        ]);
    }
}
