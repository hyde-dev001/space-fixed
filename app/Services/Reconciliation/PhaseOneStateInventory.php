<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PhaseOneStateInventory
{
    private const DOMAINS = ['orders', 'employees'];

    /**
     * Inspect phase-one rows without mutating them unless apply is enabled.
     *
     * @return array{run_id: string, reports: array<int, array{owner_id: int, domains: array<string, array<string, mixed>>}>, totals: array<string, array<string, mixed>>}
     */
    public function inspect(?int $shopOwnerId, string $domain, int $chunk, bool $apply): array
    {
        $runId = (string) Str::uuid();
        $reports = [];
        $totals = [];

        $this->shopOwnerQuery($shopOwnerId, $domain)
            ->orderBy('id')
            ->chunkById($chunk, function ($owners) use (&$reports, &$totals, $domain, $apply): void {
                foreach ($owners as $owner) {
                    $ownerId = (int) $owner->id;
                    $domains = [];

                    foreach ($this->domains($domain) as $currentDomain) {
                        $report = $apply
                            ? DB::transaction(fn (): array => $this->inspectDomain($ownerId, $currentDomain, true))
                            : $this->inspectDomain($ownerId, $currentDomain, false);

                        $domains[$currentDomain] = $report;
                        $this->addToTotals($totals, $currentDomain, $report);
                    }

                    $reports[] = [
                        'owner_id' => $ownerId,
                        'domains' => $domains,
                    ];
                }
            });

        return [
            'run_id' => $runId,
            'reports' => $reports,
            'totals' => $totals,
        ];
    }

    /** @return array<int, string> */
    private function domains(string $domain): array
    {
        return $domain === 'all' ? self::DOMAINS : [$domain];
    }

    private function shopOwnerQuery(?int $shopOwnerId, string $domain)
    {
        return DB::table('shop_owners')
            ->select('shop_owners.id')
            ->when($shopOwnerId !== null, fn ($query) => $query->where('shop_owners.id', $shopOwnerId))
            ->where(function ($query) use ($domain): void {
                if ($domain === 'all' || $domain === 'orders') {
                    $query->orWhereExists(function ($subquery): void {
                        $subquery->selectRaw('1')
                            ->from('orders')
                            ->whereColumn('orders.shop_owner_id', 'shop_owners.id');
                    });
                }

                if ($domain === 'all' || $domain === 'employees') {
                    $query->orWhereExists(function ($subquery): void {
                        $subquery->selectRaw('1')
                            ->from('employees')
                            ->whereColumn('employees.shop_owner_id', 'shop_owners.id');
                    });
                }
            });
    }

    /** @return array<string, mixed> */
    private function inspectDomain(int $shopOwnerId, string $domain, bool $apply): array
    {
        return $domain === 'orders'
            ? $this->inspectOrders($shopOwnerId)
            : $this->inspectEmployees($shopOwnerId, $apply);
    }

    /** @return array<string, mixed> */
    private function inspectOrders(int $shopOwnerId): array
    {
        $report = $this->emptyReport();
        $rows = DB::table('orders')
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('id')
            ->get(['id', 'status']);

        foreach ($rows as $row) {
            $classification = $this->classifyOrderStatus((string) ($row->status ?? ''));
            $this->recordClassification($report, $classification, (int) $row->id);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function inspectEmployees(int $shopOwnerId, bool $apply): array
    {
        $report = $this->emptyReport();
        $rows = DB::table('employees')
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('id')
            ->get(['id', 'status']);

        foreach ($rows as $row) {
            $classification = $this->classifyEmployeeStatus((string) ($row->status ?? ''));
            $this->recordClassification($report, $classification, (int) $row->id);

            if ($apply && $classification['classification'] === 'normalizable') {
                $report['updated'] += DB::table('employees')
                    ->where('id', $row->id)
                    ->where('shop_owner_id', $shopOwnerId)
                    ->update([
                        'status' => 'active',
                        'updated_at' => now(),
                    ]);
            }
        }

        return $report;
    }

    /** @return array{classification: string, reason: string|null} */
    private function classifyOrderStatus(string $status): array
    {
        return match ($status) {
            'pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled'
                => ['classification' => 'canonical', 'reason' => null],
            'refund'
                => ['classification' => 'unresolved', 'reason' => 'legacy_refund_fulfillment_unknown'],
            default
                => ['classification' => 'unresolved', 'reason' => 'unknown_order_status'],
        };
    }

    /** @return array{classification: string, reason: string|null} */
    private function classifyEmployeeStatus(string $status): array
    {
        return match (strtolower(trim($status))) {
            'active', 'inactive', 'suspended', 'terminated'
                => ['classification' => 'canonical', 'reason' => null],
            'on_leave', 'on-leave'
                => ['classification' => 'normalizable', 'reason' => 'legacy_on_leave_status'],
            default
                => ['classification' => 'unresolved', 'reason' => 'unknown_employee_status'],
        };
    }

    /** @return array<string, mixed> */
    private function emptyReport(): array
    {
        return [
            'examined' => 0,
            'canonical' => 0,
            'normalizable' => 0,
            'unresolved' => 0,
            'updated' => 0,
            'unresolved_ids' => [],
            'reasons' => [],
        ];
    }

    /** @param array<string, mixed> $report @param array{classification: string, reason: string|null} $classification */
    private function recordClassification(array &$report, array $classification, int $rowId): void
    {
        $report['examined']++;
        $report[$classification['classification']]++;

        if ($classification['reason'] !== null) {
            $report['reasons'][$classification['reason']] = ($report['reasons'][$classification['reason']] ?? 0) + 1;
        }

        if ($classification['classification'] === 'unresolved') {
            $report['unresolved_ids'][] = $rowId;
        }
    }

    /** @param array<string, array<string, mixed>> $totals @param array<string, mixed> $report */
    private function addToTotals(array &$totals, string $domain, array $report): void
    {
        if (! isset($totals[$domain])) {
            $totals[$domain] = $this->emptyReport();
        }

        foreach (['examined', 'canonical', 'normalizable', 'unresolved', 'updated'] as $key) {
            $totals[$domain][$key] += $report[$key];
        }

        foreach ($report['reasons'] as $reason => $count) {
            $totals[$domain]['reasons'][$reason] = ($totals[$domain]['reasons'][$reason] ?? 0) + $count;
        }

        $totals[$domain]['unresolved_ids'] = array_merge(
            $totals[$domain]['unresolved_ids'],
            $report['unresolved_ids'],
        );
    }
}
