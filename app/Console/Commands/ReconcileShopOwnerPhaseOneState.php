<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reconciliation\PhaseOneStateInventory;
use Illuminate\Console\Command;

final class ReconcileShopOwnerPhaseOneState extends Command
{
    protected $signature = 'shop-owner:reconcile-phase-one-state
        {--domain=all : Inspect all domains, orders, or employees}
        {--shop-owner-id= : Limit inspection to one shop owner ID}
        {--chunk=500 : Shop owners per batch, capped at 1000}
        {--apply : Persist only classifications that are safe to normalize}';

    protected $description = 'Report phase-one Shop Owner state classifications without changing data by default.';

    public function handle(PhaseOneStateInventory $inventory): int
    {
        $domain = strtolower(trim((string) $this->option('domain')));
        if (! in_array($domain, ['all', 'orders', 'employees'], true)) {
            $this->error('The domain must be all, orders, or employees.');

            return self::FAILURE;
        }

        $ownerId = $this->positiveIntegerOption('shop-owner-id');
        if ($this->option('shop-owner-id') !== null && $ownerId === null) {
            $this->error('The shop owner ID must be a positive integer.');

            return self::FAILURE;
        }

        $rawChunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($rawChunk === false || $rawChunk < 1) {
            $this->error('The chunk size must be a positive integer.');

            return self::FAILURE;
        }

        $chunk = min((int) $rawChunk, 1000);
        $apply = (bool) $this->option('apply');
        $result = $inventory->inspect($ownerId, $domain, $chunk, $apply);

        $this->info('Run ID: '.$result['run_id']);
        $this->info('Mode: '.($apply ? 'apply' : 'dry-run'));

        foreach ($result['reports'] as $ownerReport) {
            foreach ($ownerReport['domains'] as $currentDomain => $report) {
                $line = sprintf(
                    'Shop owner %d %s: examined %d, canonical %d, normalizable %d, unresolved %d.',
                    $ownerReport['owner_id'],
                    $currentDomain,
                    $report['examined'],
                    $report['canonical'],
                    $report['normalizable'],
                    $report['unresolved'],
                );

                if ($report['reasons'] !== []) {
                    $reasons = $report['reasons'];
                    ksort($reasons);
                    $line .= ' Reasons: '.implode(', ', array_map(
                        static fn (string $reason, int $count): string => "{$reason}={$count}",
                        array_keys($reasons),
                        array_values($reasons),
                    )).'.';
                }

                if ($report['updated'] > 0) {
                    $line .= " Updated {$report['updated']}.";
                }

                $this->line($line);
            }
        }

        foreach ($result['totals'] as $currentDomain => $report) {
            $this->info(sprintf(
                'Totals %s: examined %d, canonical %d, normalizable %d, unresolved %d.',
                $currentDomain,
                $report['examined'],
                $report['canonical'],
                $report['normalizable'],
                $report['unresolved'],
            ));
        }

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : (int) $value;
    }
}
