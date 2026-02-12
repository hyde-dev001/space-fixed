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
        Schema::create('hr_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_owner_id');
            $table->string('name', 50);
            $table->string('code', 20)->nullable(); // e.g., 'DAY', 'NIGHT', 'EVE'
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('break_duration')->default(0)->comment('Break duration in minutes');
            $table->integer('grace_period')->default(15)->comment('Late arrival grace period in minutes');
            $table->boolean('is_overnight')->default(false)->comment('Shift spans midnight');
            $table->boolean('is_active')->default(true);
            $table->decimal('overtime_multiplier', 3, 2)->default(1.50)->comment('Overtime pay multiplier');
            $table->text('description')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('shop_owner_id');
            $table->index(['shop_owner_id', 'is_active']);
            $table->index('code');
            
            // Foreign key
            $table->foreign('shop_owner_id')
                  ->references('id')
                  ->on('shop_owners')
                  ->onDelete('cascade');
            
            // Unique constraint
            $table->unique(['shop_owner_id', 'code'], 'shift_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_shifts');
    }
};
