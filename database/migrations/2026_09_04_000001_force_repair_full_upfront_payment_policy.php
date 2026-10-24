<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Cut over the shop default without rewriting existing repair agreements.
        DB::table('shop_owners')->update([
            'repair_payment_policy' => 'full_upfront',
        ]);

        // Keep the database default aligned for new records on MySQL deployments.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE shop_owners MODIFY COLUMN repair_payment_policy VARCHAR(20) NOT NULL DEFAULT 'full_upfront' COMMENT 'full_upfront'"
            );
            DB::statement(
                "ALTER TABLE repair_requests MODIFY COLUMN payment_policy VARCHAR(20) NOT NULL DEFAULT 'full_upfront' COMMENT 'deposit_50 | full_upfront (legacy deposit records retained)'"
            );
        }
    }

    public function down(): void
    {
        // Existing shop defaults cannot be safely restored without knowing their prior value.
    }
};
