<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (!Schema::hasTable('review_reports')) {
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            Schema::create('review_reports_tmp', function (Blueprint $table) {
                $table->id();
                $table->enum('review_type', ['product', 'repair', 'shop']);
                $table->unsignedBigInteger('review_id');
                $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('reason', [
                    'fake_review',
                    'harassment',
                    'spam',
                    'inappropriate_content',
                    'other',
                ]);
                $table->text('notes')->nullable();
                $table->json('review_snapshot');
                $table->enum('status', [
                    'pending_review',
                    'under_investigation',
                    'dismissed',
                    'banned',
                ])->default('pending_review');
                $table->text('admin_notes')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['review_type', 'review_id']);
                $table->index('status');
                $table->index('shop_owner_id');
                $table->index('user_id');
            });

            DB::statement('INSERT INTO review_reports_tmp (id, review_type, review_id, shop_owner_id, user_id, reason, notes, review_snapshot, status, admin_notes, resolved_at, created_at, updated_at) SELECT id, review_type, review_id, shop_owner_id, user_id, reason, notes, review_snapshot, status, admin_notes, resolved_at, created_at, updated_at FROM review_reports');

            Schema::drop('review_reports');
            Schema::rename('review_reports_tmp', 'review_reports');

            DB::statement('PRAGMA foreign_keys=ON');
            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE review_reports MODIFY review_type ENUM('product','repair','shop') NOT NULL");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TYPE review_reports_review_type_enum ADD VALUE IF NOT EXISTS 'shop'");
        }
    }

    public function down(): void
    {
        // No-op rollback: removing enum values can break existing report records.
    }
};
