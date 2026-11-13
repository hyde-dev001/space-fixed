<?php

namespace App\Console\Commands;

use App\Models\Finance\Expense;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateFinanceReceiptsToPrivateStorage extends Command
{
    protected $signature = 'finance:migrate-receipts-private
        {--dry-run : Inspect and report without copying or updating metadata}
        {--chunk=100 : Number of expenses to process per batch}';

    protected $description = 'Copy Finance expense receipts to private storage without deleting legacy files';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $stats = ['scanned' => 0, 'copied' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0];

        Expense::query()
            ->whereNotNull('receipt_path')
            ->where('receipt_path', '<>', '')
            ->orderBy('id')
            ->chunkById($chunk, function ($expenses) use (&$stats, $dryRun): void {
                foreach ($expenses as $expense) {
                    $stats['scanned']++;
                    $this->migrateExpense($expense, $dryRun, $stats);
                }
            });

        $mode = $dryRun ? 'Dry run' : 'Migration';
        $this->info(sprintf(
            '%s complete: scanned=%d copied=%d skipped=%d missing=%d failed=%d',
            $mode,
            $stats['scanned'],
            $stats['copied'],
            $stats['skipped'],
            $stats['missing'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string,int> $stats */
    private function migrateExpense(Expense $expense, bool $dryRun, array &$stats): void
    {
        $sourcePath = (string) $expense->receipt_path;
        $targetPath = $this->targetPath($expense);
        $local = Storage::disk('local');
        $public = Storage::disk('public');

        if ($sourcePath === $targetPath && $local->exists($targetPath)) {
            $stats['skipped']++;
            return;
        }

        $sourceDisk = $local->exists($sourcePath) ? $local : ($public->exists($sourcePath) ? $public : null);
        if ($sourceDisk === null) {
            $stats['missing']++;
            $this->warn("Missing receipt for expense {$expense->id}: {$sourcePath}");
            return;
        }

        if ($dryRun) {
            $stats['copied']++;
            return;
        }

        try {
            $contents = $sourceDisk->get($sourcePath);
            $sourceHash = hash('sha256', $contents);
            $local->put($targetPath, $contents);
            $targetContents = $local->get($targetPath);

            if (! hash_equals($sourceHash, hash('sha256', $targetContents))) {
                $local->delete($targetPath);
                throw new \RuntimeException('Receipt checksum verification failed.');
            }

            $expense->forceFill(['receipt_path' => $targetPath])->saveQuietly();
            $stats['copied']++;
        } catch (Throwable $exception) {
            $stats['failed']++;
            $this->error("Failed receipt {$expense->id}: {$exception->getMessage()}");
        }
    }

    private function targetPath(Expense $expense): string
    {
        $extension = $this->extensionFor($expense->receipt_mime_type, $expense->receipt_original_name);

        return "finance/shops/{$expense->shop_id}/expenses/{$expense->id}/receipts/receipt.{$extension}";
    }

    private function extensionFor(?string $mime, ?string $originalName): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        ];

        if (isset($map[$mime])) {
            return $map[$mime];
        }

        $extension = strtolower((string) pathinfo((string) $originalName, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'pdf'], true)
            ? ($extension === 'jpeg' ? 'jpg' : $extension)
            : 'bin';
    }
}
