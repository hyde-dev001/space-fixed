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
        Schema::table('orders', function (Blueprint $table) {
            // Staff assignment tracking (similar to repair_requests)
            $table->foreignId('assigned_staff_id')->nullable()->after('customer_id')->constrained('users')->onDelete('set null');
            $table->timestamp('assigned_at')->nullable()->after('assigned_staff_id');
            $table->enum('assignment_method', ['auto', 'manual'])->nullable()->after('assigned_at');
            $table->foreignId('assigned_by')->nullable()->after('assignment_method')->constrained('users')->onDelete('set null');
            
            // Index for performance
            $table->index('assigned_staff_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['assigned_staff_id']);
            $table->dropForeign(['assigned_by']);
            $table->dropIndex(['assigned_staff_id']);
            $table->dropColumn(['assigned_staff_id', 'assigned_at', 'assignment_method', 'assigned_by']);
        });
    }
};
