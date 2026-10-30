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
            $table->dropUnique('orders_order_number_unique');
            $table->unique(
                ['shop_owner_id', 'order_number'],
                'orders_shop_owner_order_number_unique'
            );
        });

        Schema::table('handoff_proofs', function (Blueprint $table): void {
            $table->foreignId('shop_owner_id')
                ->nullable()
                ->after('shipment_leg_id')
                ->constrained('shop_owners')
                ->nullOnDelete();
            $table->unsignedBigInteger('proof_number')->nullable()->after('shop_owner_id');
        });

        $nextByShop = [];
        DB::table('handoff_proofs as proofs')
            ->join('shipment_legs', 'shipment_legs.id', '=', 'proofs.shipment_leg_id')
            ->join('shipments', 'shipments.id', '=', 'shipment_legs.shipment_id')
            ->orderBy('shipments.shop_owner_id')
            ->orderBy('proofs.id')
            ->get(['proofs.id', 'shipments.shop_owner_id'])
            ->each(function (object $proof) use (&$nextByShop): void {
                $shopId = (int) $proof->shop_owner_id;
                $nextByShop[$shopId] = ($nextByShop[$shopId] ?? 0) + 1;

                DB::table('handoff_proofs')
                    ->where('id', $proof->id)
                    ->update([
                        'shop_owner_id' => $shopId,
                        'proof_number' => $nextByShop[$shopId],
                    ]);
            });

        Schema::table('handoff_proofs', function (Blueprint $table): void {
            $table->unique(
                ['shop_owner_id', 'proof_number'],
                'handoff_proofs_shop_owner_proof_number_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('handoff_proofs', function (Blueprint $table): void {
            $table->dropUnique('handoff_proofs_shop_owner_proof_number_unique');
            $table->dropForeign(['shop_owner_id']);
            $table->dropColumn(['shop_owner_id', 'proof_number']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_shop_owner_order_number_unique');
            $table->unique('order_number', 'orders_order_number_unique');
        });
    }
};
