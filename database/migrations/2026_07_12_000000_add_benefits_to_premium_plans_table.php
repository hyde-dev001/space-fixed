<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('premium_plans', function (Blueprint $table) {
            $table->json('benefits')->nullable()->after('showroom_slot_limit');
        });

        DB::table('premium_plans')->whereNull('benefits')->update([
            'benefits' => json_encode([
                'View shoes in horizontal detail inside the showroom',
                'Enable image-sequence uploads for showroom presentation',
            ]),
        ]);
    }

    public function down(): void
    {
        Schema::table('premium_plans', function (Blueprint $table) {
            $table->dropColumn('benefits');
        });
    }
};
