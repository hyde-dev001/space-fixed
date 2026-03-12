<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repairer_unavailability', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('repairer_id');
            $table->unsignedBigInteger('shop_owner_id');
            $table->string('month_key', 7)->comment('Format: YYYY-MM, e.g. 2026-03');
            $table->json('unavailable_dates')->comment('Array of YYYY-MM-DD strings');
            $table->timestamps();

            // One record per repairer per month
            $table->unique(['repairer_id', 'month_key']);

            $table->foreign('repairer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('shop_owner_id')->references('id')->on('shop_owners')->onDelete('cascade');

            $table->index('shop_owner_id');
            $table->index('month_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repairer_unavailability');
    }
};
