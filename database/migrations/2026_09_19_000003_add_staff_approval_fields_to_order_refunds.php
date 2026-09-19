<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_refunds', 'staff_approved_at')) {
                $table->timestamp('staff_approved_at')
                    ->nullable()
                    ->after('shop_owner_status');
            }

            if (! Schema::hasColumn('order_refunds', 'staff_approved_by')) {
                $table->foreignId('staff_approved_by')
                    ->nullable()
                    ->after('staff_approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_refunds', function (Blueprint $table): void {
            if (Schema::hasColumn('order_refunds', 'staff_approved_by')) {
                $table->dropConstrainedForeignId('staff_approved_by');
            }

            if (Schema::hasColumn('order_refunds', 'staff_approved_at')) {
                $table->dropColumn('staff_approved_at');
            }
        });
    }
};
