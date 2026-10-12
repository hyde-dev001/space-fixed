<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table): void {
            $table->timestamp('recovery_assigned_at')
                ->nullable()
                ->after('recovery_responsible_party');
            $table->index('recovery_assigned_at', 'order_refunds_recovery_assigned_at_index');
        });

        Schema::table('pos_refunds', function (Blueprint $table): void {
            $table->timestamp('recovery_assigned_at')
                ->nullable()
                ->after('recovery_responsible_party');
            $table->index('recovery_assigned_at', 'pos_refunds_recovery_assigned_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_refunds', function (Blueprint $table): void {
            $table->dropIndex('order_refunds_recovery_assigned_at_index');
            $table->dropColumn('recovery_assigned_at');
        });

        Schema::table('pos_refunds', function (Blueprint $table): void {
            $table->dropIndex('pos_refunds_recovery_assigned_at_index');
            $table->dropColumn('recovery_assigned_at');
        });
    }
};
