<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('shipments')
            ->select('source_type', 'source_id', 'purpose')
            ->groupBy('source_type', 'source_id', 'purpose')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(sprintf(
                'Cannot add shipment source uniqueness: duplicate %s/%s/%s exists.',
                $duplicate->source_type,
                $duplicate->source_id,
                $duplicate->purpose,
            ));
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id', 'purpose'], 'shipments_source_purpose_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique('shipments_source_purpose_unique');
        });
    }
};
