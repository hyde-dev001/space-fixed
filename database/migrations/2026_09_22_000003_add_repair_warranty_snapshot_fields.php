<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shop_owners', 'repair_warranty_duration_unit')) {
            Schema::table('shop_owners', function (Blueprint $table): void {
                $table->string('repair_warranty_duration_unit', 16)
                    ->default('days')
                    ->after('repair_warranty_days');
            });
        }

        $repairWarrantyColumns = [
            'repair_warranty_issued',
            'repair_warranty_started_at',
            'repair_warranty_expires_at',
            'repair_warranty_duration',
            'repair_warranty_duration_unit',
        ];
        $missingColumns = array_values(array_filter(
            $repairWarrantyColumns,
            fn (string $column): bool => ! Schema::hasColumn('repair_requests', $column),
        ));

        if ($missingColumns === []) {
            return;
        }

        Schema::table('repair_requests', function (Blueprint $table) use ($missingColumns): void {
            if (in_array('repair_warranty_issued', $missingColumns, true)) {
                $table->boolean('repair_warranty_issued')->default(false)->after('picked_up_at');
            }
            if (in_array('repair_warranty_started_at', $missingColumns, true)) {
                $table->timestamp('repair_warranty_started_at')->nullable()->after('repair_warranty_issued');
            }
            if (in_array('repair_warranty_expires_at', $missingColumns, true)) {
                $table->timestamp('repair_warranty_expires_at')->nullable()->after('repair_warranty_started_at');
            }
            if (in_array('repair_warranty_duration', $missingColumns, true)) {
                $table->unsignedSmallInteger('repair_warranty_duration')->nullable()->after('repair_warranty_expires_at');
            }
            if (in_array('repair_warranty_duration_unit', $missingColumns, true)) {
                $table->string('repair_warranty_duration_unit', 16)->nullable()->after('repair_warranty_duration');
            }
        });
    }

    public function down(): void
    {
        $repairWarrantyColumns = [
            'repair_warranty_issued',
            'repair_warranty_started_at',
            'repair_warranty_expires_at',
            'repair_warranty_duration',
            'repair_warranty_duration_unit',
        ];
        $existingColumns = array_values(array_filter(
            $repairWarrantyColumns,
            fn (string $column): bool => Schema::hasColumn('repair_requests', $column),
        ));

        if ($existingColumns !== []) {
            Schema::table('repair_requests', function (Blueprint $table) use ($existingColumns): void {
                $table->dropColumn($existingColumns);
            });
        }

        if (Schema::hasColumn('shop_owners', 'repair_warranty_duration_unit')) {
            Schema::table('shop_owners', function (Blueprint $table): void {
                $table->dropColumn('repair_warranty_duration_unit');
            });
        }
    }
};
