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
        Schema::create('repair_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->string('customer_name');
            $table->string('email');
            $table->string('phone');
            $table->string('shoe_type')->nullable();
            $table->string('brand')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('shop_owner_id')->nullable();
            $table->json('images')->nullable();
            $table->decimal('total', 10, 2);
            $table->enum('status', ['received', 'pending', 'in-progress', 'completed', 'ready-for-pickup'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('shop_owner_id')->references('id')->on('shop_owners')->onDelete('set null');
        });

        Schema::create('repair_request_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('repair_request_id');
            $table->unsignedBigInteger('repair_service_id');
            $table->timestamps();

            $table->foreign('repair_request_id')->references('id')->on('repair_requests')->onDelete('cascade');
            $table->foreign('repair_service_id')->references('id')->on('repair_services')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repair_request_service');
        Schema::dropIfExists('repair_requests');
    }
};
