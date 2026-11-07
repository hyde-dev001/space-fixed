<?php

namespace App\Console\Commands;

use App\Models\ShopDocument;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class MigrateSensitiveDocumentsToPrivateStorage extends Command
{
    protected $signature = 'security:migrate-sensitive-documents-private
        {--dry-run : Report without writes}
        {--restore-public : Prepare verified public copies and metadata for application rollback}
        {--chunk=100 : Records per batch}';

    protected $description = 'Migrate sensitive documents between public and private storage with byte verification';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $restorePublic = (bool) $this->option('restore-public');
        $stats = [
            'shop_documents' => $this->emptyStats(),
            'customer_ids' => $this->emptyStats(),
        ];

        $shopDocumentStats = &$stats['shop_documents'];
        ShopDocument::query()
            ->whereNotNull('file_path')
            ->where('file_path', '<>', '')
            ->orderBy('id')
            ->chunkById($chunk, function ($documents) use (&$shopDocumentStats, $dryRun, $restorePublic): void {
                foreach ($documents as $document) {
                    $this->processRecord(
                        $document,
                        'shop_document',
                        'file_path',
                        'disk',
                        $dryRun,
                        $restorePublic,
                        $shopDocumentStats,
                    );
                }
            });
        unset($shopDocumentStats);

        $customerIdStats = &$stats['customer_ids'];
        User::query()
            ->whereNotNull('valid_id_path')
            ->where('valid_id_path', '<>', '')
            ->orderBy('id')
            ->chunkById($chunk, function ($users) use (&$customerIdStats, $dryRun, $restorePublic): void {
                foreach ($users as $user) {
                    $this->processRecord(
                        $user,
                        'customer_id',
                        'valid_id_path',
                        'valid_id_disk',
                        $dryRun,
                        $restorePublic,
                        $customerIdStats,
                    );
                }
            });
        unset($customerIdStats);

        $this->printStats('Shop documents', $stats['shop_documents']);
        $this->printStats('Customer valid IDs', $stats['customer_ids']);
        $this->printStats('Totals', $this->totalStats($stats));

        return $this->hasFailures($stats) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function processRecord(
        Model $record,
        string $category,
        string $pathColumn,
        string $diskColumn,
        bool $dryRun,
        bool $restorePublic,
        array &$stats,
    ): void {
        $id = (int) $record->getKey();
        $path = trim((string) $record->getRawOriginal($pathColumn));
        $disk = trim((string) $record->getRawOriginal($diskColumn));

        if (! $this->isSafePath($path) || ! in_array($disk, ['local', 'public'], true)) {
            $this->recordIssue($stats, $category, $id, 'failed');

            return;
        }

        try {
            $public = Storage::disk('public');
            $private = Storage::disk('local');

            if ($restorePublic) {
                $this->restoreRecord(
                    $record,
                    $category,
                    $pathColumn,
                    $diskColumn,
                    $path,
                    $disk,
                    $dryRun,
                    $public,
                    $private,
                    $stats,
                );

                return;
            }

            $this->migrateRecord(
                $record,
                $category,
                $pathColumn,
                $diskColumn,
                $path,
                $disk,
                $dryRun,
                $public,
                $private,
                $stats,
            );
        } catch (Throwable) {
            $this->recordIssue($stats, $category, $id, 'failed');
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function migrateRecord(
        Model $record,
        string $category,
        string $pathColumn,
        string $diskColumn,
        string $path,
        string $disk,
        bool $dryRun,
        mixed $public,
        mixed $private,
        array &$stats,
    ): void {
        $publicExists = $public->exists($path);
        $privateExists = $private->exists($path);

        if ($disk === 'local') {
            if (! $privateExists) {
                $this->recordIssue($stats, $category, (int) $record->getKey(), 'missing');

                return;
            }

            if (! $publicExists) {
                $stats['already_private']++;

                return;
            }

            if (! $this->contentsMatch($private, $public, $path)) {
                $this->recordIssue($stats, $category, (int) $record->getKey(), 'conflict');

                return;
            }

            if ($dryRun) {
                $stats['would_remove_duplicates']++;

                return;
            }

            if ($this->removePublicDuplicate($public, $path)) {
                $stats['duplicates_removed']++;
            } else {
                $this->recordPublicDuplicateFailure($stats, $category, (int) $record->getKey());
            }

            return;
        }

        if (! $publicExists) {
            $this->recordIssue($stats, $category, (int) $record->getKey(), 'missing');

            return;
        }

        if ($privateExists && ! $this->contentsMatch($public, $private, $path)) {
            $this->recordIssue($stats, $category, (int) $record->getKey(), 'conflict');

            return;
        }

        if ($dryRun) {
            $stats['would_migrate']++;

            return;
        }

        $privateWasCreated = false;
        try {
            if (! $privateExists) {
                $privateWasCreated = true;
                $this->copyAndVerify($public, $private, $path);
            }

            $this->switchDisk(
                $record,
                $pathColumn,
                $diskColumn,
                $path,
                'public',
                'local',
            );
        } catch (Throwable) {
            if ($privateWasCreated) {
                $private->delete($path);
            }
            $this->recordIssue($stats, $category, (int) $record->getKey(), 'failed');

            return;
        }

        if ($this->removePublicDuplicate($public, $path)) {
            $stats['migrated']++;
        } else {
            $this->recordPublicDuplicateFailure($stats, $category, (int) $record->getKey());
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function restoreRecord(
        Model $record,
        string $category,
        string $pathColumn,
        string $diskColumn,
        string $path,
        string $disk,
        bool $dryRun,
        mixed $public,
        mixed $private,
        array &$stats,
    ): void {
        $publicExists = $public->exists($path);
        $privateExists = $private->exists($path);

        if ($disk === 'local') {
            if (! $privateExists) {
                $this->recordIssue($stats, $category, (int) $record->getKey(), 'missing');

                return;
            }

            if ($publicExists && ! $this->contentsMatch($private, $public, $path)) {
                $this->recordIssue($stats, $category, (int) $record->getKey(), 'conflict');

                return;
            }

            if ($dryRun) {
                $stats['would_restore']++;

                return;
            }

            $publicWasCreated = false;
            try {
                if (! $publicExists) {
                    $publicWasCreated = true;
                    $this->copyAndVerify($private, $public, $path);
                }

                $this->switchDisk(
                    $record,
                    $pathColumn,
                    $diskColumn,
                    $path,
                    'local',
                    'public',
                );
            } catch (Throwable) {
                if ($publicWasCreated) {
                    $public->delete($path);
                }
                $this->recordIssue($stats, $category, (int) $record->getKey(), 'failed');

                return;
            }

            $stats['restored']++;

            return;
        }

        if ($publicExists && $privateExists && ! $this->contentsMatch($public, $private, $path)) {
            $this->recordIssue($stats, $category, (int) $record->getKey(), 'conflict');

            return;
        }

        if ($publicExists) {
            $stats['already_public']++;

            return;
        }

        if (! $privateExists) {
            $this->recordIssue($stats, $category, (int) $record->getKey(), 'missing');

            return;
        }

        if ($dryRun) {
            $stats['would_restore']++;

            return;
        }

        try {
            $this->copyAndVerify($private, $public, $path);
            $stats['restored']++;
        } catch (Throwable) {
            $public->delete($path);
            $this->recordIssue($stats, $category, (int) $record->getKey(), 'failed');
        }
    }

    private function copyAndVerify(mixed $source, mixed $target, string $path): void
    {
        $sourceBytes = $source->get($path);
        $sourceSize = strlen($sourceBytes);
        $sourceHash = hash('sha256', $sourceBytes);

        if (! $target->put($path, $sourceBytes)) {
            throw new \RuntimeException('Private copy failed.');
        }

        $targetBytes = $target->get($path);
        if (strlen($targetBytes) !== $sourceSize
            || ! hash_equals($sourceHash, hash('sha256', $targetBytes))) {
            throw new \RuntimeException('Private copy verification failed.');
        }
    }

    private function contentsMatch(mixed $first, mixed $second, string $path): bool
    {
        $firstBytes = $first->get($path);
        $secondBytes = $second->get($path);

        return strlen($firstBytes) === strlen($secondBytes)
            && hash_equals(hash('sha256', $firstBytes), hash('sha256', $secondBytes));
    }

    private function removePublicDuplicate(mixed $public, string $path): bool
    {
        try {
            $public->delete($path);

            return ! $public->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    private function switchDisk(
        Model $record,
        string $pathColumn,
        string $diskColumn,
        string $path,
        string $expectedDisk,
        string $targetDisk,
    ): void {
        DB::transaction(function () use (
            $record,
            $pathColumn,
            $diskColumn,
            $path,
            $expectedDisk,
            $targetDisk,
        ): void {
            $locked = $record->newQuery()->lockForUpdate()->find($record->getKey());
            if (! $locked instanceof Model
                || (string) $locked->getRawOriginal($pathColumn) !== $path
                || (string) $locked->getRawOriginal($diskColumn) !== $expectedDisk) {
                throw new \RuntimeException('Sensitive document metadata changed during migration.');
            }

            $locked->forceFill([$diskColumn => $targetDisk])->saveQuietly();

            $fresh = $record->newQuery()->find($record->getKey());
            if (! $fresh instanceof Model
                || (string) $fresh->getRawOriginal($pathColumn) !== $path
                || (string) $fresh->getRawOriginal($diskColumn) !== $targetDisk) {
                throw new \RuntimeException('Sensitive document metadata verification failed.');
            }
        });
    }

    private function isSafePath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/');
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'migrated' => 0,
            'restored' => 0,
            'already_private' => 0,
            'already_public' => 0,
            'duplicates_removed' => 0,
            'would_migrate' => 0,
            'would_restore' => 0,
            'would_remove_duplicates' => 0,
            'conflicts' => 0,
            'missing' => 0,
            'failed' => 0,
            'public_duplicates_remaining' => 0,
        ];
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function recordIssue(array &$stats, string $category, int $id, string $issue): void
    {
        $statKey = $issue === 'conflict' ? 'conflicts' : $issue;

        if (isset($stats[$statKey])) {
            $stats[$statKey]++;
        }

        if (in_array($issue, ['conflict', 'missing', 'failed'], true)) {
            $this->warn("{$category} id={$id} category={$issue}");
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function recordPublicDuplicateFailure(array &$stats, string $category, int $id): void
    {
        $stats['failed']++;
        $stats['public_duplicates_remaining']++;
        $this->warn("{$category} id={$id} category=public_duplicates_remaining");
    }

    /**
     * @param  array<string, array<string, int>>  $stats
     * @return array<string, int>
     */
    private function totalStats(array $stats): array
    {
        $totals = $this->emptyStats();

        foreach ($stats as $recordStats) {
            foreach ($totals as $key => $value) {
                $totals[$key] += $recordStats[$key];
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function printStats(string $label, array $stats): void
    {
        $values = [];
        foreach ($stats as $key => $value) {
            $values[] = "{$key}={$value}";
        }

        $this->line($label.':');
        foreach ($values as $value) {
            $this->line('  '.$value);
        }
    }

    /**
     * @param  array<string, array<string, int>>  $stats
     */
    private function hasFailures(array $stats): bool
    {
        foreach ($stats as $recordStats) {
            if ($recordStats['conflicts'] > 0
                || $recordStats['missing'] > 0
                || $recordStats['failed'] > 0
                || $recordStats['public_duplicates_remaining'] > 0) {
                return true;
            }
        }

        return false;
    }
}
