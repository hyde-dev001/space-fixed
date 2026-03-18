<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('inventory_sizes', 'inventory_color_variant_id')) {
            Schema::table('inventory_sizes', function (Blueprint $table) {
                $table->foreignId('inventory_color_variant_id')
                    ->nullable()
                    ->after('inventory_item_id')
                    ->constrained('inventory_color_variants')
                    ->nullOnDelete();
            });
        }

        $indexNames = collect(DB::select('SHOW INDEX FROM inventory_sizes'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        foreach (['unique_inventory_size', 'unique_inventory_size_system'] as $legacyIndexName) {
            if (in_array($legacyIndexName, $indexNames, true)) {
                Schema::table('inventory_sizes', function (Blueprint $table) use ($legacyIndexName) {
                    $table->dropUnique($legacyIndexName);
                });
            }
        }

        $indexNames = collect(DB::select('SHOW INDEX FROM inventory_sizes'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        if (!in_array('inventory_sizes_item_color_size_system_unique', $indexNames, true)) {
            Schema::table('inventory_sizes', function (Blueprint $table) {
                $table->unique(
                    ['inventory_item_id', 'inventory_color_variant_id', 'size', 'size_system'],
                    'inventory_sizes_item_color_size_system_unique'
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $indexNames = collect(DB::select('SHOW INDEX FROM inventory_sizes'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        if (in_array('inventory_sizes_item_color_size_system_unique', $indexNames, true)) {
            Schema::table('inventory_sizes', function (Blueprint $table) {
                $table->dropUnique('inventory_sizes_item_color_size_system_unique');
            });
        }

        if (Schema::hasColumn('inventory_sizes', 'inventory_color_variant_id')) {
            Schema::table('inventory_sizes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('inventory_color_variant_id');
            });
        }

        $indexNames = collect(DB::select('SHOW INDEX FROM inventory_sizes'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        if (!in_array('unique_inventory_size_system', $indexNames, true)) {
            Schema::table('inventory_sizes', function (Blueprint $table) {
                $table->unique(['inventory_item_id', 'size', 'size_system'], 'unique_inventory_size_system');
            });
        }
    }
};
