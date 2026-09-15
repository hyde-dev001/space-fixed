<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('finance_invoices', 'job_reference')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->string('job_reference')->nullable()->index()->after('job_order_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('finance_invoices', 'job_reference')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->dropIndex(['job_reference']);
                $table->dropColumn('job_reference');
            });
        }
    }
};
