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
        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->date('customer_appointment_date')->nullable();
            $table->string('customer_appointment_window', 16)->nullable();
            $table->text('appointment_agreement_note')->nullable();
            $table->foreignId('appointment_agreed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('appointment_agreed_at')->nullable();
        });
        Schema::table('delivery_assignments', function (Blueprint $table) {
            $table->uuid('recovery_key')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_assignments', fn (Blueprint $table) => $table->dropColumn('recovery_key'));
        Schema::table('shipment_legs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_agreed_by_user_id');
            $table->dropColumn(['customer_appointment_date', 'customer_appointment_window', 'appointment_agreement_note', 'appointment_agreed_at']);
        });
    }
};
