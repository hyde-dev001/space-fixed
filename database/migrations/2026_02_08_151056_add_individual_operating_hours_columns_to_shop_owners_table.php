<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            // Add individual time columns for each day of the week
            $table->time('monday_open')->nullable()->after('operating_hours');
            $table->time('monday_close')->nullable()->after('monday_open');
            $table->time('tuesday_open')->nullable()->after('monday_close');
            $table->time('tuesday_close')->nullable()->after('tuesday_open');
            $table->time('wednesday_open')->nullable()->after('tuesday_close');
            $table->time('wednesday_close')->nullable()->after('wednesday_open');
            $table->time('thursday_open')->nullable()->after('wednesday_close');
            $table->time('thursday_close')->nullable()->after('thursday_open');
            $table->time('friday_open')->nullable()->after('thursday_close');
            $table->time('friday_close')->nullable()->after('friday_open');
            $table->time('saturday_open')->nullable()->after('friday_close');
            $table->time('saturday_close')->nullable()->after('saturday_open');
            $table->time('sunday_open')->nullable()->after('saturday_close');
            $table->time('sunday_close')->nullable()->after('sunday_open');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn([
                'monday_open', 'monday_close',
                'tuesday_open', 'tuesday_close',
                'wednesday_open', 'wednesday_close',
                'thursday_open', 'thursday_close',
                'friday_open', 'friday_close',
                'saturday_open', 'saturday_close',
                'sunday_open', 'sunday_close',
            ]);
        });
    }
};
