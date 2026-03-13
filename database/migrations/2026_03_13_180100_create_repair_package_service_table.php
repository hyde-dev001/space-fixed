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
        Schema::create('repair_package_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('repair_package_id');
            $table->unsignedBigInteger('repair_service_id');
            $table->timestamps();

            $table->foreign('repair_package_id')->references('id')->on('repair_packages')->onDelete('cascade');
            $table->foreign('repair_service_id')->references('id')->on('repair_services')->onDelete('cascade');

            $table->unique(['repair_package_id', 'repair_service_id']);
            $table->index('repair_service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repair_package_service');
    }
};