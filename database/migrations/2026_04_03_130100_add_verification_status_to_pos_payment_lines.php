<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_payment_lines', function (Blueprint $table) {
            $table->string('verification_status', 40)->nullable()->after('status');
            $table->timestamp('verified_at')->nullable()->after('paid_at');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->boolean('manual_fallback_used')->default(false)->after('verified_by');
            $table->string('verification_mode', 30)->nullable()->after('manual_fallback_used');
            $table->string('verification_note', 255)->nullable()->after('verification_mode');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pos_payment_lines MODIFY status ENUM('pending', 'pending_authorization', 'paid', 'failed', 'reversed') NOT NULL DEFAULT 'paid'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pos_payment_lines MODIFY status ENUM('pending', 'paid', 'failed', 'reversed') NOT NULL DEFAULT 'paid'");
        }

        Schema::table('pos_payment_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'verification_status',
                'verified_at',
                'manual_fallback_used',
                'verification_mode',
                'verification_note',
            ]);
        });
    }
};
