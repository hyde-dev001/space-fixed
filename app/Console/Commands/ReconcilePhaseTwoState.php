<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PhaseTwoStateReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class ReconcilePhaseTwoState extends Command
{
    protected $signature = 'super-admin:reconcile-phase-two-state
        {--apply : Persist the reconciled state; without this option the command is read-only}';

    protected $description = 'Reconcile legacy suspension, appeal, and warning state into Phase Two records';

    public function __construct(private readonly PhaseTwoStateReconciler $reconciler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $operationId = (string) Str::uuid();
        $apply = (bool) $this->option('apply');

        $this->info('Operation UUID: '.$operationId);
        $this->info('Mode: '.($apply ? 'apply' : 'dry-run'));

        try {
            $result = $this->reconciler->reconcile($operationId, $apply);
        } catch (Throwable) {
            $this->error('Phase Two reconciliation failed before any aggregate result was committed.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Suspensions: proposed=%d created=%d existing=%d',
            $result['suspensions_proposed'],
            $result['suspensions_created'],
            $result['suspensions_existing'],
        ));
        $this->line(sprintf(
            'Appeals: expired=%d linked=%d superseded=%d',
            $result['appeals_expired'],
            $result['appeals_linked'],
            $result['appeals_superseded'],
        ));
        $this->line(sprintf(
            'Warning actions: proposed=%d created=%d existing=%d',
            $result['warning_actions_proposed'],
            $result['warning_actions_created'],
            $result['warning_actions_existing'],
        ));
        $this->line('Operator review required: '.$result['operator_review_required']);

        foreach ($result['operator_review_accounts'] as $account) {
            $this->line('  '.$account);
        }

        foreach ($result['failures'] as $failure) {
            $this->error('Failed aggregate: '.$failure);
        }

        return $result['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
