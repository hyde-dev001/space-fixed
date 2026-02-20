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
        // Add new notification preference columns
        Schema::table('notification_preferences', function (Blueprint $table) {
            // Customer preferences
            $table->boolean('email_order_updates')->default(true)->after('browser_delegation_assigned');
            $table->boolean('email_repair_updates')->default(true)->after('email_order_updates');
            $table->boolean('email_payment_updates')->default(true)->after('email_repair_updates');
            $table->boolean('browser_order_updates')->default(true)->after('email_payment_updates');
            $table->boolean('browser_repair_updates')->default(true)->after('browser_order_updates');
            $table->boolean('browser_payment_updates')->default(true)->after('browser_repair_updates');
            
            // Shop Owner preferences
            $table->boolean('email_new_orders')->default(true)->after('browser_payment_updates');
            $table->boolean('email_approvals')->default(true)->after('email_new_orders');
            $table->boolean('email_alerts')->default(true)->after('email_approvals');
            $table->boolean('browser_new_orders')->default(true)->after('email_alerts');
            $table->boolean('browser_approvals')->default(true)->after('browser_new_orders');
            $table->boolean('browser_alerts')->default(true)->after('browser_approvals');
            
            // Staff preferences
            $table->boolean('email_tasks')->default(true)->after('browser_alerts');
            $table->boolean('email_hr_updates')->default(true)->after('email_tasks');
            $table->boolean('browser_tasks')->default(true)->after('email_hr_updates');
            $table->boolean('browser_hr_updates')->default(true)->after('browser_tasks');
        });
        
        // Add shop_owner_id for Shop Owner notifications
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_owner_id')->nullable()->after('shop_id');
            $table->foreign('shop_owner_id')->references('id')->on('shop_owners')->onDelete('cascade');
            $table->index('shop_owner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop shop_owner_id from notifications
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['shop_owner_id']);
            $table->dropIndex(['shop_owner_id']);
            $table->dropColumn('shop_owner_id');
        });
        
        // Drop new preference columns
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'email_order_updates', 
                'email_repair_updates', 
                'email_payment_updates',
                'browser_order_updates', 
                'browser_repair_updates', 
                'browser_payment_updates',
                'email_new_orders', 
                'email_approvals', 
                'email_alerts',
                'browser_new_orders', 
                'browser_approvals', 
                'browser_alerts',
                'email_tasks', 
                'email_hr_updates', 
                'browser_tasks', 
                'browser_hr_updates'
            ]);
        });
    }
};
