<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('order_refunds', 'requested_refund_method')) {
                $table->string('requested_refund_method')->nullable()->after('currency');
            }

            if (!Schema::hasColumn('order_refunds', 'evidence_media')) {
                $table->json('evidence_media')->nullable()->after('reason_note');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            $columns = ['requested_refund_method', 'evidence_media'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('order_refunds', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
