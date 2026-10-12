<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $expenseIds = DB::table('finance_expenses')
                ->whereNotNull('procurement_receipt_id')
                ->pluck('id');

            if ($expenseIds->isEmpty()) {
                return;
            }

            DB::table('approvals')
                ->where('approvable_type', 'App\\Models\\Finance\\Expense')
                ->whereIn('approvable_id', $expenseIds)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'comments' => 'Cancelled because procurement receipt expenses are review-only.',
                    'updated_at' => now(),
                ]);

            DB::table('finance_expenses')
                ->whereIn('id', $expenseIds)
                ->update([
                    'approval_id' => null,
                    'current_approval_level' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    public function down(): void
    {
        // Approval links cannot be safely restored without knowing their prior state.
    }
};
