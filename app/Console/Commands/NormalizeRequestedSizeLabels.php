<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeRequestedSizeLabels extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sizes:normalize-requested-size-labels
                            {--dry-run : Preview updates without writing changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize requested_size values to canonical format like "US 8" across stock requests, purchase requests, and purchase orders';

    /**
     * Allowed size systems.
     */
    private const SIZE_SYSTEMS = ['US', 'UK', 'EU', 'AU', 'CN'];

    /**
     * Local cache for inferred size systems.
     *
     * @var array<string, string|null>
     */
    private array $sizeSystemCache = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Normalizing requested_size labels to canonical system format (US/UK/EU/AU/CN)...');
        if ($dryRun) {
            $this->comment('Running in dry-run mode. No database writes will be performed.');
        }

        $tableConfigs = [
            ['table' => 'stock_request_approvals', 'label' => 'Stock Requests'],
            ['table' => 'purchase_requests', 'label' => 'Purchase Requests'],
            ['table' => 'purchase_orders', 'label' => 'Purchase Orders'],
        ];

        $summary = [];

        foreach ($tableConfigs as $config) {
            $stats = $this->processTable(
                table: $config['table'],
                label: $config['label'],
                dryRun: $dryRun,
            );

            $summary[] = $stats;
        }

        $this->newLine();
        $this->info('Normalization summary:');

        $totalScanned = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;

        foreach ($summary as $stats) {
            $totalScanned += $stats['scanned'];
            $totalUpdated += $stats['updated'];
            $totalSkipped += $stats['skipped'];

            $this->line(sprintf(
                '- %s: scanned=%d, updated=%d, skipped=%d',
                $stats['label'],
                $stats['scanned'],
                $stats['updated'],
                $stats['skipped'],
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. Total scanned=%d, updated=%d, skipped=%d%s',
            $totalScanned,
            $totalUpdated,
            $totalSkipped,
            $dryRun ? ' (dry-run)' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{label:string,scanned:int,updated:int,skipped:int}
     */
    private function processTable(string $table, string $label, bool $dryRun): array
    {
        $this->newLine();
        $this->info("Processing {$label} ({$table})...");

        $stats = [
            'label' => $label,
            'scanned' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        DB::table($table)
            ->select('id', 'inventory_item_id', 'requested_size')
            ->whereNotNull('requested_size')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table, $dryRun, &$stats) {
                foreach ($rows as $row) {
                    $raw = trim((string) $row->requested_size);

                    if ($raw === '') {
                        $stats['skipped']++;
                        continue;
                    }

                    $stats['scanned']++;

                    $parsed = $this->parseRequestedSize($raw);
                    $sizeValue = $parsed['size'];

                    if ($sizeValue === '') {
                        $stats['skipped']++;
                        continue;
                    }

                    $system = $parsed['system'];

                    if ($system === null) {
                        $system = $this->inferSizeSystem(
                            inventoryItemId: $row->inventory_item_id ? (int) $row->inventory_item_id : null,
                            sizeValue: $sizeValue,
                        ) ?? 'US';
                    }

                    $canonical = $system . ' ' . $sizeValue;

                    if (mb_strlen($canonical) > 20) {
                        $stats['skipped']++;
                        $this->warn("Skipped {$table}#{$row->id}: canonical value exceeds 20 chars ({$canonical})");
                        continue;
                    }

                    if ($canonical === $raw) {
                        continue;
                    }

                    if ($dryRun) {
                        $stats['updated']++;
                        $this->line("[dry-run] {$table}#{$row->id}: '{$raw}' => '{$canonical}'");
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([
                            'requested_size' => $canonical,
                            'updated_at' => now(),
                        ]);

                    $stats['updated']++;
                    $this->line("Updated {$table}#{$row->id}: '{$raw}' => '{$canonical}'");
                }
            }, 'id');

        return $stats;
    }

    /**
     * @return array{system:string|null,size:string}
     */
    private function parseRequestedSize(string $raw): array
    {
        $value = trim($raw);

        if ($value === '') {
            return ['system' => null, 'size' => ''];
        }

        if (preg_match('/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i', $value, $matches)) {
            $system = strtoupper((string) $matches[1]);
            $size = trim((string) $matches[2]);
            return ['system' => $system, 'size' => $size];
        }

        if (preg_match('/^size\s+(.+)$/i', $value, $matches)) {
            $value = trim((string) $matches[1]);
        }

        return ['system' => null, 'size' => $value];
    }

    private function inferSizeSystem(?int $inventoryItemId, string $sizeValue): ?string
    {
        if (!$inventoryItemId) {
            return null;
        }

        $cacheKey = $inventoryItemId . '|' . $sizeValue;
        if (array_key_exists($cacheKey, $this->sizeSystemCache)) {
            return $this->sizeSystemCache[$cacheKey];
        }

        $systems = DB::table('inventory_sizes')
            ->where('inventory_item_id', $inventoryItemId)
            ->where('size', $sizeValue)
            ->selectRaw("UPPER(COALESCE(NULLIF(size_system, ''), 'US')) as inferred_system")
            ->distinct()
            ->pluck('inferred_system')
            ->filter()
            ->values()
            ->all();

        $system = null;

        if (count($systems) === 1 && in_array($systems[0], self::SIZE_SYSTEMS, true)) {
            $system = $systems[0];
        }

        $this->sizeSystemCache[$cacheKey] = $system;

        return $system;
    }
}
