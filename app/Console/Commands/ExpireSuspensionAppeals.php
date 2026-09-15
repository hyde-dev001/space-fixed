<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SuspensionAppealService;
use Illuminate\Console\Command;
use Throwable;

final class ExpireSuspensionAppeals extends Command
{
    protected $signature = 'suspension-appeals:expire {--limit=100 : Maximum number of appeal rows to expire}';

    protected $description = 'Expire eligible or submitted suspension appeals whose deadlines have passed.';

    public function handle(SuspensionAppealService $appealService): int
    {
        try {
            $count = $appealService->expireDue((int) $this->option('limit'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Suspension appeal expiry could not be completed.');

            return self::FAILURE;
        }

        $this->info("Expired {$count} suspension appeal(s).");

        return self::SUCCESS;
    }
}
