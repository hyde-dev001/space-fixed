<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'customer_receipt_status')) {
                $table->string('customer_receipt_status')->default('pending')->after('status');
            }
            if (! Schema::hasColumn('orders', 'customer_received_at')) {
                $table->timestamp('customer_received_at')->nullable()->after('customer_receipt_status');
            }
            if (! Schema::hasColumn('orders', 'customer_receipt_disputed_at')) {
                $table->timestamp('customer_receipt_disputed_at')->nullable()->after('customer_received_at');
            }
        });

        if (! Schema::hasIndex('orders', 'orders_status_receipt_status_idx')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->index(['status', 'customer_receipt_status'], 'orders_status_receipt_status_idx');
            });
        }

        if (! Schema::hasColumn('orders', 'customer_receipt_status')) {
            return;
        }

        DB::table('orders')
            ->whereIn('status', ['delivered', 'completed'])
            ->where(function ($query): void {
                $query->whereNull('customer_receipt_status')
                    ->orWhere('customer_receipt_status', 'pending');
            })
            ->update(['customer_receipt_status' => 'confirmed']);
    }

    public function down(): void
    {
        if (Schema::hasIndex('orders', 'orders_status_receipt_status_idx')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropIndex('orders_status_receipt_status_idx');
            });
        }

        Schema::table('orders', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('orders', 'customer_receipt_disputed_at') ? 'customer_receipt_disputed_at' : null,
                Schema::hasColumn('orders', 'customer_received_at') ? 'customer_received_at' : null,
                Schema::hasColumn('orders', 'customer_receipt_status') ? 'customer_receipt_status' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
