<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_invoices')) {
            return;
        }

        $duplicates = DB::table('finance_invoices')
            ->select('shop_id', 'job_order_id', DB::raw('COUNT(*) as duplicate_count'))
            ->whereNotNull('job_order_id')
            ->groupBy('shop_id', 'job_order_id')
            ->having('duplicate_count', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException('Cannot add the Finance job invoice uniqueness constraint while duplicate job invoices exist. Run finance:audit-integrity --section=job-invoices and reconcile the listed rows first.');
        }

        $indexes = collect(Schema::getIndexes('finance_invoices'));
        $alreadyUnique = $indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['shop_id', 'job_order_id']);
        if (! $alreadyUnique) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->unique(['shop_id', 'job_order_id'], 'finance_invoices_shop_job_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_invoices')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('finance_invoices'));
        if ($indexes->contains(fn (array $index): bool => $index['name'] === 'finance_invoices_shop_job_unique')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->dropUnique('finance_invoices_shop_job_unique');
            });
        }
    }
};
