<?php

namespace App\Console\Commands;

use App\Models\Logistics\HandoffProof;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MoveHandoffProofsToPrivate extends Command
{
    protected $signature = 'logistics:move-handoff-proofs-private
        {--restore-public : Restore verified public copies before rolling application code back}';

    protected $description = 'Move handoff proof originals between public and private storage with byte verification';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        $restore = (bool) $this->option('restore-public');
        $counts = [
            'moved' => 0,
            'restored' => 0,
            'already_private' => 0,
            'already_public' => 0,
            'duplicates_removed' => 0,
            'conflicts' => 0,
            'missing' => 0,
            'failed' => 0,
        ];

        HandoffProof::query()
            ->whereNotNull('file_path')
            ->chunkById(100, function ($proofs) use ($public, $private, $restore, &$counts) {
                foreach ($proofs as $proof) {
                    if ($restore) {
                        $this->restore($proof->file_path, $public, $private, $counts);
                    } else {
                        $this->move($proof->file_path, $public, $private, $counts);
                    }
                }
            });

        $this->line("Moved: {$counts['moved']}");
        $this->line("Restored: {$counts['restored']}");
        $this->line("Already private: {$counts['already_private']}");
        $this->line("Already public: {$counts['already_public']}");
        $this->line("Duplicates removed: {$counts['duplicates_removed']}");
        $this->line("Conflicts: {$counts['conflicts']}");
        $this->line("Missing: {$counts['missing']}");
        $this->line("Failed: {$counts['failed']}");

        return $counts['conflicts'] || $counts['missing'] || $counts['failed']
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function move(string $path, $public, $private, array &$counts): void
    {
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
                if (! $public->delete($path)) {
                    $counts['failed']++;

                    return;
                }
                $counts['duplicates_removed']++;

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
                $counts['failed']++;

                return;
            }
            $counts['moved']++;
        } catch (\Throwable) {
            if (! ($privateExists ?? true)) {
                $private->delete($path);
            }
            $counts['failed']++;
        }
    }

    private function restore(string $path, $public, $private, array &$counts): void
    {
        try {
            $publicExists = $public->exists($path);
            $privateExists = $private->exists($path);

            if (! $privateExists) {
                $counts['missing']++;

                return;
            }
            if ($publicExists) {
                if ($this->hash($private->get($path)) !== $this->hash($public->get($path))) {
                    $counts['conflicts']++;

                    return;
                }
                $counts['already_public']++;

                return;
            }

            $bytes = $private->get($path);
            if (! $public->put($path, $bytes)
                || $this->hash($public->get($path)) !== $this->hash($bytes)) {
                $public->delete($path);
                $counts['failed']++;

                return;
            }
            $counts['restored']++;
        } catch (\Throwable) {
            if (! ($publicExists ?? true)) {
                $public->delete($path);
            }
            $counts['failed']++;
        }
    }

    private function hash(string $bytes): string
    {
        return hash('sha256', $bytes);
    }
}
