<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_batches', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('rejection_reason');
            $table->json('cancelled_stops')->nullable()->after('cancellation_reason');
        });

        DB::table('delivery_batches')->where('status', 'cancelled')->whereNull('cancellation_reason')
            ->update(['cancellation_reason' => DB::raw('dispatcher_override_reason')]);
    }

    public function down(): void
    {
        Schema::table('delivery_batches', fn (Blueprint $table) => $table->dropColumn(['cancellation_reason', 'cancelled_stops']));
    }
};
