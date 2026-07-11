<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('rider_profile_id')->nullable()->constrained('rider_profiles')->nullOnDelete();
            $table->date('delivery_date');
            $table->string('delivery_window');
            $table->string('status')->default('draft');
            $table->unsignedSmallInteger('capacity')->default(0);
            $table->unsignedSmallInteger('assigned_stop_count')->default(0);
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('dispatcher_override_reason')->nullable();
            $table->timestamps();
            $table->index(['shop_owner_id', 'delivery_date', 'delivery_window', 'status'], 'delivery_batches_dispatch_index');
            $table->index(['rider_profile_id', 'delivery_date', 'status'], 'delivery_batches_rider_index');
        });

        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->foreignId('delivery_batch_id')->nullable()->constrained('delivery_batches')->nullOnDelete();
            $table->unsignedSmallInteger('stop_sequence')->nullable();
            $table->timestamp('urgent_at')->nullable();
            $table->index(['delivery_batch_id', 'stop_sequence']);
        });
        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->json('work_days')->nullable();
            $table->json('leave_dates')->nullable();
            $table->unsignedSmallInteger('daily_capacity')->nullable();
        });
        Schema::table('delivery_assignments', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_assignments', fn (Blueprint $table) => $table->dropColumn(['rejection_reason', 'rejected_at']));
        Schema::table('rider_profiles', fn (Blueprint $table) => $table->dropColumn(['work_days', 'leave_dates', 'daily_capacity']));
        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->dropForeign(['delivery_batch_id']);
            $table->dropColumn(['delivery_batch_id', 'stop_sequence', 'urgent_at']);
        });
        Schema::dropIfExists('delivery_batches');
    }
};
