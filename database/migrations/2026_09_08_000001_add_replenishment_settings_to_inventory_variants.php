<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sizes', function (Blueprint $table): void {
            $table->boolean('auto_stock_request_enabled')->default(false)->after('quantity');
            $table->unsignedInteger('reorder_level')->default(10)->after('auto_stock_request_enabled');
            $table->unsignedInteger('reorder_quantity')->default(50)->after('reorder_level');
        });

        Schema::table('inventory_color_variants', function (Blueprint $table): void {
            $table->boolean('auto_stock_request_enabled')->nullable()->after('quantity');
            $table->unsignedInteger('reorder_level')->nullable()->after('auto_stock_request_enabled');
            $table->unsignedInteger('reorder_quantity')->nullable()->after('reorder_level');
        });

        DB::table('inventory_sizes')
            ->select(['id', 'inventory_item_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $size): void {
                $defaults = DB::table('inventory_items')
                    ->where('id', $size->inventory_item_id)
                    ->first(['auto_stock_request_enabled', 'reorder_level', 'reorder_quantity']);

                if ($defaults) {
                    DB::table('inventory_sizes')
                        ->where('id', $size->id)
                        ->update([
                            'auto_stock_request_enabled' => (bool) $defaults->auto_stock_request_enabled,
                            'reorder_level' => (int) $defaults->reorder_level,
                            'reorder_quantity' => (int) $defaults->reorder_quantity,
                        ]);
                }
            });

        DB::table('inventory_color_variants')
            ->select(['id', 'inventory_item_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $color): void {
                if (DB::table('inventory_sizes')->where('inventory_color_variant_id', $color->id)->exists()) {
                    return;
                }

                $defaults = DB::table('inventory_items')
                    ->where('id', $color->inventory_item_id)
                    ->first(['auto_stock_request_enabled', 'reorder_level', 'reorder_quantity']);

                if ($defaults) {
                    DB::table('inventory_color_variants')
                        ->where('id', $color->id)
                        ->update([
                            'auto_stock_request_enabled' => (bool) $defaults->auto_stock_request_enabled,
                            'reorder_level' => (int) $defaults->reorder_level,
                            'reorder_quantity' => (int) $defaults->reorder_quantity,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('inventory_color_variants', function (Blueprint $table): void {
            $table->dropColumn([
                'auto_stock_request_enabled',
                'reorder_level',
                'reorder_quantity',
            ]);
        });

        Schema::table('inventory_sizes', function (Blueprint $table): void {
            $table->dropColumn([
                'auto_stock_request_enabled',
                'reorder_level',
                'reorder_quantity',
            ]);
        });
    }
};
