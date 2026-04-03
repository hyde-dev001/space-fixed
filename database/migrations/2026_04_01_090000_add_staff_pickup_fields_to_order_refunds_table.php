<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('order_refunds', 'staff_return_tracking_number')) {
                $table->string('staff_return_tracking_number')->nullable()->after('customer_return_shipped_at');
            }

            if (!Schema::hasColumn('order_refunds', 'staff_return_carrier')) {
                $table->string('staff_return_carrier')->nullable()->after('staff_return_tracking_number');
            }

            if (!Schema::hasColumn('order_refunds', 'staff_return_rider_name')) {
                $table->string('staff_return_rider_name')->nullable()->after('staff_return_carrier');
            }

            if (!Schema::hasColumn('order_refunds', 'staff_return_rider_phone')) {
                $table->string('staff_return_rider_phone')->nullable()->after('staff_return_rider_name');
            }

            if (!Schema::hasColumn('order_refunds', 'staff_return_tracking_link')) {
                $table->string('staff_return_tracking_link')->nullable()->after('staff_return_rider_phone');
            }

            if (!Schema::hasColumn('order_refunds', 'staff_return_shipped_at')) {
                $table->timestamp('staff_return_shipped_at')->nullable()->after('staff_return_tracking_link');
            }

            if (!Schema::hasColumn('order_refunds', 'return_arranged_by_staff_id')) {
                $table->foreignId('return_arranged_by_staff_id')->nullable()->after('staff_return_shipped_at')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('order_refunds', 'return_arranged_by_staff_at')) {
                $table->timestamp('return_arranged_by_staff_at')->nullable()->after('return_arranged_by_staff_id');
            }

            if (!Schema::hasColumn('order_refunds', 'return_source')) {
                $table->enum('return_source', ['customer', 'staff', 'system'])->default('customer')->after('return_arranged_by_staff_at');
            }
        });

        if (Schema::hasColumn('order_refunds', 'return_status') && DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE order_refunds MODIFY return_status ENUM('not_required','awaiting_approval','pending_customer_shipment','pending_staff_pickup','in_transit','received') NOT NULL DEFAULT 'awaiting_approval'");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_refunds', 'return_status') && DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE order_refunds MODIFY return_status ENUM('not_required','awaiting_approval','pending_customer_shipment','in_transit','received') NOT NULL DEFAULT 'awaiting_approval'");
        }

        Schema::table('order_refunds', function (Blueprint $table) {
            if (Schema::hasColumn('order_refunds', 'return_arranged_by_staff_id')) {
                $table->dropConstrainedForeignId('return_arranged_by_staff_id');
            }

            $columns = [
                'staff_return_tracking_number',
                'staff_return_carrier',
                'staff_return_rider_name',
                'staff_return_rider_phone',
                'staff_return_tracking_link',
                'staff_return_shipped_at',
                'return_arranged_by_staff_at',
                'return_source',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('order_refunds', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
