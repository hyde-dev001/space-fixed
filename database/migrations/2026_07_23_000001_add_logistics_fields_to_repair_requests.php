<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->enum('intake_delivery_method', ['walk_in', 'customer_delivery', 'shop_pickup'])
                ->nullable()
                ->change();
            $table->decimal('intake_delivery_fee', 10, 2)->default(0)->after('intake_address');
            $table->decimal('return_delivery_fee', 10, 2)->default(0)->after('intake_delivery_fee');
            $table->boolean('same_as_intake_address')->default(true)->after('return_delivery_fee');
            $table->timestamp('return_address_confirmed_at')->nullable()->after('same_as_intake_address');
            $table->string('return_address_confirmed_version', 64)->nullable()->after('return_address_confirmed_at');
            $table->timestamp('intake_logistics_locked_at')->nullable()->after('return_address_confirmed_version');
            $table->timestamp('return_logistics_locked_at')->nullable()->after('intake_logistics_locked_at');
            $table->json('intake_logistics_quote')->nullable()->after('return_logistics_locked_at');
            $table->json('return_logistics_quote')->nullable()->after('intake_logistics_quote');
            $table->json('logistics_payment_reconciliation')->nullable()->after('return_logistics_quote');
        });
    }

    public function down(): void
    {
        DB::table('repair_requests')->where('intake_delivery_method', 'shop_pickup')->update([
            'intake_delivery_method' => null,
        ]);

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->enum('intake_delivery_method', ['walk_in', 'customer_delivery'])
                ->nullable()
                ->change();
            $table->dropColumn([
                'intake_delivery_fee',
                'return_delivery_fee',
                'same_as_intake_address',
                'return_address_confirmed_at',
                'return_address_confirmed_version',
                'intake_logistics_locked_at',
                'return_logistics_locked_at',
                'intake_logistics_quote',
                'return_logistics_quote',
                'logistics_payment_reconciliation',
            ]);
        });
    }
};
