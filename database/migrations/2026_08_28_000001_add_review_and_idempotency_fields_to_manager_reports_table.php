<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('manager_reports')) {
            return;
        }

        Schema::table('manager_reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('manager_reports', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->after('generated_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('manager_reports', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            }
            if (! Schema::hasColumn('manager_reports', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->after('report_data');
            }
        });

        if (! Schema::hasIndex('manager_reports', 'manager_reports_shop_idempotency_unique')) {
            Schema::table('manager_reports', function (Blueprint $table): void {
                $table->unique(
                    ['shop_owner_id', 'idempotency_key'],
                    'manager_reports_shop_idempotency_unique',
                );
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE manager_reports MODIFY status ENUM('generated', 'reviewed', 'failed', 'sent') NOT NULL DEFAULT 'generated'");
        }

        // Reports created by the old endpoint were labelled `sent` even
        // though no delivery workflow existed. Preserve their actor/time as
        // review metadata and make the persisted state truthful.
        DB::table('manager_reports')
            ->where('status', 'sent')
            ->update([
                'status' => 'reviewed',
                'reviewed_by' => DB::raw('sent_by'),
                'reviewed_at' => DB::raw('sent_at'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('manager_reports')) {
            return;
        }

        if (Schema::hasIndex('manager_reports', 'manager_reports_shop_idempotency_unique')) {
            Schema::table('manager_reports', function (Blueprint $table): void {
                $table->dropUnique('manager_reports_shop_idempotency_unique');
            });
        }

        Schema::table('manager_reports', function (Blueprint $table): void {
            if (Schema::hasColumn('manager_reports', 'reviewed_by')) {
                $table->dropForeign(['reviewed_by']);
            }
            $columns = [];
            foreach (['reviewed_by', 'reviewed_at', 'idempotency_key'] as $column) {
                if (Schema::hasColumn('manager_reports', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
