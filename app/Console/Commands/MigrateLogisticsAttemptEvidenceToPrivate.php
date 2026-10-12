<?php

namespace App\Console\Commands;

use App\Models\Logistics\DeliveryAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateLogisticsAttemptEvidenceToPrivate extends Command
{
    protected $signature = 'logistics:migrate-attempt-evidence {--dry-run : Report changes without copying or deleting files}';

    protected $description = 'Move failed-attempt evidence from public to private storage with byte verification';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        $dryRun = (bool) $this->option('dry-run');
        $counts = [
            'migrated' => 0,
            'would_migrate' => 0,
            'already_private' => 0,
            'duplicates_removed' => 0,
            'would_remove_duplicates' => 0,
            'conflicts' => 0,
            'missing' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        DeliveryAttempt::query()
            ->whereNotNull('file_path')
            ->chunkById(100, function ($attempts) use ($public, $private, $dryRun, &$counts): void {
                foreach ($attempts as $attempt) {
                    $this->migratePath(
                        (string) $attempt->getRawOriginal('file_path'),
                        $public,
                        $private,
                        $dryRun,
                        $counts,
                    );
                }
            });

        $this->line("Migrated: {$counts['migrated']}");
        $this->line("Would migrate: {$counts['would_migrate']}");
        $this->line("Already private: {$counts['already_private']}");
        $this->line("Duplicates removed: {$counts['duplicates_removed']}");
        $this->line("Would remove duplicates: {$counts['would_remove_duplicates']}");
        $this->line("Conflicts: {$counts['conflicts']}");
        $this->line("Missing: {$counts['missing']}");
        $this->line("Skipped: {$counts['skipped']}");
        $this->line("Failed: {$counts['failed']}");

        return $counts['conflicts'] || $counts['missing'] || $counts['failed']
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function migratePath(string $path, $public, $private, bool $dryRun, array &$counts): void
    {
        if (! $this->isSafePath($path)) {
            $counts['skipped']++;

            return;
        }

        try {
            $publicExists = $public->exists($path);
            $privateExists = $private->exists($path);

            if ($privateExists && ! $publicExists) {
                $counts['already_private']++;

                return;
            }
            if (! $privateExists && ! $publicExists) {
                $counts['missing']++;

                return;
            }
            if ($privateExists) {
                if ($this->hash($private->get($path)) !== $this->hash($public->get($path))) {
                    $counts['conflicts']++;

                    return;
                }
                if ($dryRun) {
                    $counts['would_remove_duplicates']++;

                    return;
                }
                if (! $public->delete($path)) {
                    $counts['failed']++;

                    return;
                }
                $counts['duplicates_removed']++;

                return;
            }

            if ($dryRun) {
                $counts['would_migrate']++;

                return;
            }

            $bytes = $public->get($path);
            if (! $private->put($path, $bytes)
                || $this->hash($private->get($path)) !== $this->hash($bytes)) {
                $private->delete($path);
                $counts['failed']++;

                return;
            }
            if (! $public->delete($path)) {
                $private->delete($path);
                $counts['failed']++;

                return;
            }
            $counts['migrated']++;
        } catch (\Throwable) {
            if (! ($privateExists ?? true)) {
                $private->delete($path);
            }
            $counts['failed']++;
        }
    }

    private function isSafePath(string $path): bool
    {
        return str_starts_with($path, 'logistics-attempt/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }

    private function hash(string $bytes): string
    {
        return hash('sha256', $bytes);
    }
}
