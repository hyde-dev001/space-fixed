<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Enums\EmployeeStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PhaseOneStateInventory
{
    private const DOMAINS = ['orders', 'employees'];

    private const ROW_CHUNK_SIZE = 500;

    public function __construct(private readonly PhaseOneStateReconciler $reconciler)
    {
    }

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
                $chunkReports = $apply
                    ? DB::transaction(fn (): array => $this->inspectOwnerChunk($owners, $domain, true))
                    : $this->inspectOwnerChunk($owners, $domain, false);

                foreach ($chunkReports as $ownerReport) {
                    $reports[] = $ownerReport;

                    foreach ($ownerReport['domains'] as $currentDomain => $report) {
                        $this->addToTotals($totals, $currentDomain, $report);
                    }
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

    /**
     * Inspect one bounded shop-owner batch. Apply mode calls this inside one
     * transaction so a failed shop does not leave a partial batch behind.
     *
     * @param iterable<int, object> $owners
     * @return array<int, array{owner_id: int, domains: array<string, array<string, mixed>>}>
     */
    private function inspectOwnerChunk(iterable $owners, string $domain, bool $apply): array
    {
        $reports = [];

        foreach ($owners as $owner) {
            $ownerId = (int) $owner->id;
            $domains = [];

            foreach ($this->domains($domain) as $currentDomain) {
                $domains[$currentDomain] = $this->inspectDomain($ownerId, $currentDomain, $apply);
            }

            $reports[] = [
                'owner_id' => $ownerId,
                'domains' => $domains,
            ];
        }

        return $reports;
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
            ? $this->inspectOrders($shopOwnerId, $apply)
            : $this->inspectEmployees($shopOwnerId, $apply);
    }

    /** @return array<string, mixed> */
    private function inspectOrders(int $shopOwnerId, bool $apply): array
    {
        $report = $this->emptyReport();
        $query = DB::table('orders')
            ->where('shop_owner_id', $shopOwnerId)
            ->select(['id', 'status']);
        $rows = $apply
            ? $query->lazyById(self::ROW_CHUNK_SIZE)
            : $query->orderBy('id')->get();

        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            $classification = $this->classifyOrderStatus($status);
            $this->recordClassification($report, $classification, (int) $row->id, $status);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function inspectEmployees(int $shopOwnerId, bool $apply): array
    {
        $report = $this->emptyReport();
        $query = DB::table('employees')
            ->where('shop_owner_id', $shopOwnerId)
            ->select(['id', 'status']);
        $rows = $apply
            ? $query->lazyById(self::ROW_CHUNK_SIZE)
            : $query->orderBy('id')->get();

        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            $classification = $this->classifyEmployeeStatus($status);
            $this->recordClassification($report, $classification, (int) $row->id, $status);

            if ($apply && $classification['classification'] === 'normalizable') {
                $report['updated'] += (int) $this->reconciler->normalizeEmployee(
                    $shopOwnerId,
                    (int) $row->id,
                );
            }
        }

        return $report;
    }

    /** @return array{classification: string, reason: string|null, disposition: string|null} */
    private function classifyOrderStatus(string $status): array
    {
        return match ($status) {
            'pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled'
                => ['classification' => 'canonical', 'reason' => null, 'disposition' => null],
            'refund'
                => [
                    'classification' => 'unresolved',
                    'reason' => 'legacy_refund_fulfillment_unknown',
                    'disposition' => 'rollout_blocker',
                ],
            default
                => [
                    'classification' => 'unresolved',
                    'reason' => 'unknown_order_status',
                    'disposition' => 'rollout_blocker',
                ],
        };
    }

    /** @return array{classification: string, reason: string|null, disposition: string|null} */
    private function classifyEmployeeStatus(string $status): array
    {
        $normalized = strtolower(trim($status));

        if (in_array($normalized, EmployeeStatus::values(), true)) {
            return ['classification' => 'canonical', 'reason' => null, 'disposition' => null];
        }

        if ($this->reconciler->isLegacyLeaveStatus($normalized)) {
            return [
                'classification' => 'normalizable',
                'reason' => 'legacy_on_leave_status',
                'disposition' => null,
            ];
        }

        return [
            'classification' => 'unresolved',
            'reason' => 'unknown_employee_status',
            'disposition' => 'rollout_blocker',
        ];
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
            'enforcement_blocked' => 0,
            'unresolved_ids' => [],
            'unresolved_rows' => [],
            'dispositions' => [],
            'reasons' => [],
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @param array{classification: string, reason: string|null, disposition: string|null} $classification
     */
    private function recordClassification(array &$report, array $classification, int $rowId, string $status): void
    {
        $report['examined']++;
        $report[$classification['classification']]++;

        if ($classification['reason'] !== null) {
            $report['reasons'][$classification['reason']] = ($report['reasons'][$classification['reason']] ?? 0) + 1;
        }

        if ($classification['classification'] === 'unresolved') {
            $report['unresolved_ids'][] = $rowId;
            $report['enforcement_blocked']++;
            $disposition = (string) $classification['disposition'];
            $report['dispositions'][$disposition] = ($report['dispositions'][$disposition] ?? 0) + 1;
            $report['unresolved_rows'][] = [
                'id' => $rowId,
                'status' => $status,
                'reason' => $classification['reason'],
                'disposition' => $disposition,
                'enforcement_blocked' => true,
            ];
        }
    }

    /** @param array<string, array<string, mixed>> $totals @param array<string, mixed> $report */
    private function addToTotals(array &$totals, string $domain, array $report): void
    {
        if (! isset($totals[$domain])) {
            $totals[$domain] = $this->emptyReport();
        }

        foreach (['examined', 'canonical', 'normalizable', 'unresolved', 'updated', 'enforcement_blocked'] as $key) {
            $totals[$domain][$key] += $report[$key];
        }

        foreach ($report['reasons'] as $reason => $count) {
            $totals[$domain]['reasons'][$reason] = ($totals[$domain]['reasons'][$reason] ?? 0) + $count;
        }

        $totals[$domain]['unresolved_ids'] = array_merge(
            $totals[$domain]['unresolved_ids'],
            $report['unresolved_ids'],
        );
        $totals[$domain]['unresolved_rows'] = array_merge(
            $totals[$domain]['unresolved_rows'],
            $report['unresolved_rows'],
        );

        foreach ($report['dispositions'] as $disposition => $count) {
            $totals[$domain]['dispositions'][$disposition] =
                ($totals[$domain]['dispositions'][$disposition] ?? 0) + $count;
        }
    }
}
