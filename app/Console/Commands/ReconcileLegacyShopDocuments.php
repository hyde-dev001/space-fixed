<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ShopOwner;
use App\Services\LegacyShopDocumentReconciler;
use Illuminate\Console\Command;

final class ReconcileLegacyShopDocuments extends Command
{
    protected $signature = 'shop-documents:reconcile-legacy
        {--apply : Persist reliable lifecycle metadata; without this option the command is read-only}
        {--shop-owner-id= : Limit reconciliation to one shop owner ID}
        {--chunk=100 : Shop owners per batch, capped at 1000}';

    protected $description = 'Conservatively reconcile legacy shop-document rows into the Phase 6 lifecycle.';

    public function handle(LegacyShopDocumentReconciler $reconciler): int
    {
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
        $this->info('Mode: '.($apply ? 'apply' : 'dry-run'));

        $totals = [
            'inspected' => 0,
            'updated' => 0,
            'already_reconciled' => 0,
            'unresolved' => 0,
        ];
        $unresolvedIds = [];

        $owners = ShopOwner::query()
            ->whereHas('documents', function ($query): void {
                $query->whereNull('logical_slot')
                    ->orWhereNull('version_number')
                    ->orWhereNull('expiration_mode')
                    ->orWhereNull('is_current');
            })
            ->when($ownerId !== null, fn ($query) => $query->whereKey($ownerId))
            ->orderBy('id');

        $owners->chunkById($chunk, function ($owners) use ($reconciler, $apply, &$totals, &$unresolvedIds): void {
            foreach ($owners as $owner) {
                $report = $reconciler->reconcile($owner, $apply);
                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $report[$key];
                }
                $unresolvedIds = array_merge($unresolvedIds, $report['unresolved_ids']);
                $this->line(sprintf(
                    'Shop owner %d: inspected %d, updated %d, already reconciled %d, unresolved %d.',
                    $report['owner_id'],
                    $report['inspected'],
                    $report['updated'],
                    $report['already_reconciled'],
                    $report['unresolved'],
                ));
            }
        });

        $this->info(sprintf(
            'Totals: inspected %d, updated %d, already reconciled %d, unresolved %d.',
            $totals['inspected'],
            $totals['updated'],
            $totals['already_reconciled'],
            $totals['unresolved'],
        ));
        if ($unresolvedIds !== []) {
            $this->warn('Unresolved document IDs: '.implode(',', array_values(array_unique($unresolvedIds))));
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
