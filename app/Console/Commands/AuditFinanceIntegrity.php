<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AuditFinanceIntegrity extends Command
{
    protected $signature = 'finance:audit-integrity
        {--section=legacy-disbursers : Read-only audit section (legacy-disbursers or job-invoices)}';

    protected $description = 'Report Finance integrity exceptions without mutating data';

    public function handle(): int
    {
        return match ((string) $this->option('section')) {
            'legacy-disbursers' => $this->auditLegacyDisbursers(),
            'job-invoices' => $this->auditJobInvoices(),
            default => $this->invalidSection(),
        };
    }

    private function auditLegacyDisbursers(): int
    {
        $rows = User::query()->orderBy('id')->get(['id', 'shop_owner_id']);
        $legacy = $rows->filter(function (User $user): bool {
            try {
                $isShopOwner = $user->hasRole('Shop Owner');
                $hasExplicitDisburser = $user->can('disburse-payroll');
                $hasLegacyAccess = $user->can('access-payslip-approval') || $user->can('access-approval-workflow');

                return ! $isShopOwner && ! $hasExplicitDisburser && $hasLegacyAccess;
            } catch (\Throwable) {
                return false;
            }
        })->values();

        $this->line('section=legacy-disbursers count='.$legacy->count());
        foreach ($legacy as $user) {
            $this->line(sprintf('user_id=%d shop_owner_id=%s', (int) $user->id, (string) ($user->shop_owner_id ?? 'null')));
        }

        return self::SUCCESS;
    }

    private function invalidSection(): int
    {
        $this->error('Unknown audit section. Supported sections: legacy-disbursers, job-invoices.');

        return self::INVALID;
    }

    private function auditJobInvoices(): int
    {
        $duplicates = DB::table('finance_invoices')
            ->select('shop_id', 'job_order_id', DB::raw('COUNT(*) as duplicate_count'))
            ->whereNotNull('job_order_id')
            ->groupBy('shop_id', 'job_order_id')
            ->having('duplicate_count', '>', 1)
            ->orderBy('shop_id')
            ->orderBy('job_order_id')
            ->get();

        $this->line('section=job-invoices groups='.$duplicates->count());
        foreach ($duplicates as $duplicate) {
            $this->line(sprintf(
                'shop_id=%s job_order_id=%d count=%d',
                (string) ($duplicate->shop_id ?? 'null'),
                (int) $duplicate->job_order_id,
                (int) $duplicate->duplicate_count,
            ));
        }

        return $duplicates->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
