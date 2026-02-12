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
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delegated_by_id');
            $table->unsignedBigInteger('delegate_to_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Foreign keys
            $table->foreign('delegated_by_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('delegate_to_id')->references('id')->on('users')->onDelete('cascade');

            // Indexes
            $table->index('delegated_by_id');
            $table->index('delegate_to_id');
            $table->index(['is_active', 'start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
    }
};
