<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\LegacyPrivilegedAuditMapper;
use App\Services\PrivilegedAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Throwable;

final class ImportLegacyPrivilegedAudit extends Command
{
    protected $signature = 'privileged-audit:import-legacy
        {--apply : Persist importable legacy events; without this option the command is read-only}
        {--chunk=200 : Maximum source rows loaded per bounded batch}
        {--limit= : Maximum number of source rows to inspect}';

    protected $description = 'Import reliable historical privileged audit events from audit_logs';

    /**
     * @var array{imported: int, would_import: int, already_imported: int, skipped: int, reasons: array<string, int>}
     */
    private array $counts = [
        'imported' => 0,
        'would_import' => 0,
        'already_imported' => 0,
        'skipped' => 0,
        'reasons' => [],
    ];

    public function __construct(
        private readonly LegacyPrivilegedAuditMapper $mapper,
        private readonly PrivilegedAudit $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->counts = [
            'imported' => 0,
            'would_import' => 0,
            'already_imported' => 0,
            'skipped' => 0,
            'reasons' => [],
        ];

        $chunkSize = (int) $this->option('chunk');
        if ($chunkSize < 1 || $chunkSize > 1000) {
            $this->error('The chunk must be between 1 and 1000.');

            return self::INVALID;
        }

        $limitOption = $this->option('limit');
        $limit = $limitOption === null ? null : (int) $limitOption;
        if ($limit !== null && $limit < 1) {
            $this->error('The limit must be a positive integer.');

            return self::INVALID;
        }

        $this->process($chunkSize, $limit, (bool) $this->option('apply'));
        $this->printSummary((bool) $this->option('apply'));

        return self::SUCCESS;
    }

    private function process(int $chunkSize, ?int $limit, bool $apply): void
    {
        $lastId = 0;
        $processed = 0;

        while (true) {
            $remaining = $limit === null ? $chunkSize : $limit - $processed;
            if ($remaining < 1) {
                break;
            }

            $take = min($chunkSize, $remaining);
            $audits = AuditLog::query()
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($take)
                ->get();

            if ($audits->isEmpty()) {
                break;
            }

            foreach ($audits as $audit) {
                $lastId = (int) $audit->getKey();
                $processed++;
                $this->processAudit($audit, $apply);
            }

            if ($audits->count() < $take) {
                break;
            }
        }
    }

    private function processAudit(AuditLog $audit, bool $apply): void
    {
        if ($this->alreadyImported((int) $audit->getKey())) {
            $this->counts['already_imported']++;

            return;
        }

        $mapped = $this->mapper->map($audit);
        if ($mapped['status'] === 'skipped') {
            $this->counts['skipped']++;
            $reason = $mapped['reason'];
            $this->counts['reasons'][$reason] = ($this->counts['reasons'][$reason] ?? 0) + 1;

            return;
        }

        if (! $apply) {
            $this->counts['would_import']++;

            return;
        }

        try {
            DB::transaction(function () use ($mapped): void {
                $legacyId = (int) $mapped['record']['legacy_id'];
                if ($this->alreadyImported($legacyId)) {
                    $this->counts['already_imported']++;

                    return;
                }

                $this->audit->importLegacy($mapped['record']);
                $this->counts['imported']++;
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->counts['skipped']++;
            $this->counts['reasons']['import_failed'] = ($this->counts['reasons']['import_failed'] ?? 0) + 1;
        }
    }

    private function alreadyImported(int $legacyId): bool
    {
        $table = config('activitylog.table_name', 'activity_log');
        $jsonPrefix = '%"legacy_audit_log_id":'.$legacyId;

        return Activity::query()
            ->where(function ($query) use ($legacyId, $jsonPrefix): void {
                $query
                    ->where(function ($provenance) use ($legacyId): void {
                        $provenance
                            ->where('log_name', 'privileged')
                            ->where('legacy_source', 'audit_logs')
                            ->where('legacy_id', $legacyId);
                    })
                    ->orWhere(function ($reconciled) use ($jsonPrefix): void {
                        $reconciled
                            ->where('log_name', 'privileged')
                            ->where(function ($properties) use ($jsonPrefix): void {
                                $properties
                                    ->where('properties', 'like', $jsonPrefix.',%')
                                    ->orWhere('properties', 'like', $jsonPrefix.'}%');
                            });
                    });
            })
            ->exists();
    }

    private function printSummary(bool $apply): void
    {
        $this->line('mode='.($apply ? 'apply' : 'dry_run'));
        $this->line('imported='.$this->counts['imported']);
        $this->line('would_import='.$this->counts['would_import']);
        $this->line('already_imported='.$this->counts['already_imported']);
        $this->line('skipped='.$this->counts['skipped']);

        ksort($this->counts['reasons']);
        foreach ($this->counts['reasons'] as $reason => $count) {
            $this->line('skipped_reason['.$reason.']='.$count);
        }
    }
}
