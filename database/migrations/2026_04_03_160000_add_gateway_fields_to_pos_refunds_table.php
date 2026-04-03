<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_refunds', 'execution_mode')) {
                $table->string('execution_mode', 20)->nullable()->after('status');
            }

            if (!Schema::hasColumn('pos_refunds', 'execution_notes')) {
                $table->text('execution_notes')->nullable()->after('execution_mode');
            }

            if (!Schema::hasColumn('pos_refunds', 'paymongo_payment_id')) {
                $table->string('paymongo_payment_id')->nullable()->after('execution_notes');
            }

            if (!Schema::hasColumn('pos_refunds', 'paymongo_refund_id')) {
                $table->string('paymongo_refund_id')->nullable()->after('paymongo_payment_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            $columns = [
                'execution_mode',
                'execution_notes',
                'paymongo_payment_id',
                'paymongo_refund_id',
            ];

            $existing = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn('pos_refunds', $column)));
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
