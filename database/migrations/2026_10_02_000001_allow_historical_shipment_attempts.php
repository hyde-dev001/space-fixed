<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique('shipments_source_purpose_unique');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedInteger('source_attempt')->default(1)->after('purpose');
            $table->unique(
                ['source_type', 'source_id', 'purpose', 'source_attempt'],
                'shipments_source_attempt_unique',
            );
        });
    }

    public function down(): void
    {
        $duplicate = DB::table('shipments')
            ->select('source_type', 'source_id', 'purpose')
            ->groupBy('source_type', 'source_id', 'purpose')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(sprintf(
                'Cannot restore shipment source uniqueness: multiple attempts exist for %s/%s/%s.',
                $duplicate->source_type,
                $duplicate->source_id,
                $duplicate->purpose,
            ));
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique('shipments_source_attempt_unique');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('source_attempt');
            $table->unique(['source_type', 'source_id', 'purpose'], 'shipments_source_purpose_unique');
        });
    }
};
