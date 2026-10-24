<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempt_number')->nullable()->after('status');
            $table->foreignId('delivery_assignment_id')->nullable()->after('shipment_leg_id')->constrained('delivery_assignments')->nullOnDelete();
            $table->foreignId('delivery_batch_id')->nullable()->after('delivery_assignment_id')->constrained('delivery_batches')->nullOnDelete();
            $table->uuid('idempotency_key')->nullable()->after('delivery_batch_id')->unique();
            $table->unique(['shipment_leg_id', 'attempt_type', 'delivery_assignment_id'], 'delivery_attempt_assignment_unique');
        });

        DB::table('delivery_attempts')
            ->orderBy('shipment_leg_id')->orderBy('attempt_type')->orderBy('attempted_at')->orderBy('id')
            ->get(['id', 'shipment_leg_id', 'attempt_type'])
            ->groupBy(fn ($attempt) => "{$attempt->shipment_leg_id}:{$attempt->attempt_type}")
            ->each(fn ($attempts) => $attempts->values()->each(
                fn ($attempt, $index) => DB::table('delivery_attempts')->where('id', $attempt->id)->update(['attempt_number' => $index + 1])
            ));

        Schema::table('shipment_legs', fn (Blueprint $table) => $table->unique('return_for_leg_id', 'shipment_leg_return_source_unique')
        );

        Schema::table('order_items', fn (Blueprint $table) => $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete()
        );

        $variants = DB::table('product_variants')->get(['id', 'product_id', 'size', 'color'])
            ->groupBy(fn ($variant) => $this->variantKey($variant->product_id, $variant->size, $variant->color))
            ->filter(fn ($matches) => $matches->count() === 1)
            ->map(fn ($matches) => $matches->first()->id);

        DB::table('order_items')->whereNull('product_variant_id')->orderBy('id')->eachById(function ($item) use ($variants) {
            $variantId = $variants->get($this->variantKey($item->product_id, $item->size, $item->color));
            if ($variantId) {
                DB::table('order_items')->where('id', $item->id)->update(['product_variant_id' => $variantId]);
            }
        });

        Schema::table('order_refund_items', fn (Blueprint $table) => $table->unique(['order_refund_id', 'order_item_id'], 'order_refund_item_unique')
        );
    }

    public function down(): void
    {
        Schema::table('order_refund_items', fn (Blueprint $table) => $table->dropUnique('order_refund_item_unique'));
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn('product_variant_id');
        });
        Schema::table('shipment_legs', fn (Blueprint $table) => $table->dropUnique('shipment_leg_return_source_unique'));
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropUnique('delivery_attempt_assignment_unique');
            $table->dropUnique(['idempotency_key']);
            $table->dropForeign(['delivery_assignment_id']);
            $table->dropForeign(['delivery_batch_id']);
            $table->dropColumn(['attempt_number', 'delivery_assignment_id', 'delivery_batch_id', 'idempotency_key']);
        });
    }

    private function variantKey(mixed $productId, mixed $size, mixed $color): string
    {
        return implode('|', [(int) $productId, mb_strtolower(trim((string) $size)), mb_strtolower(trim((string) $color))]);
    }
};
