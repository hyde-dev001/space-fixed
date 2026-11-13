<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_alerts', function (Blueprint $table): void {
            $table->foreignId('inventory_color_variant_id')
                ->nullable()
                ->after('inventory_item_id')
                ->constrained('inventory_color_variants')
                ->nullOnDelete();
            $table->foreignId('inventory_size_id')
                ->nullable()
                ->after('inventory_color_variant_id')
                ->constrained('inventory_sizes')
                ->nullOnDelete();
            $table->index(
                ['inventory_item_id', 'inventory_color_variant_id', 'inventory_size_id', 'alert_type', 'is_resolved'],
                'inventory_alerts_target_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_alerts', function (Blueprint $table): void {
            $table->dropIndex('inventory_alerts_target_lookup_index');
            $table->dropForeign(['inventory_color_variant_id']);
            $table->dropForeign(['inventory_size_id']);
            $table->dropColumn(['inventory_color_variant_id', 'inventory_size_id']);
        });
    }
};
