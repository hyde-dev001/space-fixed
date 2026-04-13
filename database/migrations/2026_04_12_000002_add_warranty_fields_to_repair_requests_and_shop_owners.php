<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_owners', 'repair_warranty_days')) {
                $table->unsignedSmallInteger('repair_warranty_days')->default(30)->after('repair_workload_limit');
            }

            if (!Schema::hasColumn('shop_owners', 'warranty_enabled')) {
                $table->boolean('warranty_enabled')->default(true)->after('repair_warranty_days');
            }
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('repair_requests', 'is_warranty_job')) {
                $table->boolean('is_warranty_job')->default(false)->after('manual_pos_queue_enabled');
            }

            if (!Schema::hasColumn('repair_requests', 'parent_repair_request_id')) {
                $table->unsignedBigInteger('parent_repair_request_id')->nullable()->after('is_warranty_job');
                $table->foreign('parent_repair_request_id', 'repair_requests_parent_warranty_fk')
                    ->references('id')
                    ->on('repair_requests')
                    ->nullOnDelete();
                $table->index('parent_repair_request_id', 'repair_requests_parent_warranty_idx');
            }

            if (!Schema::hasColumn('repair_requests', 'warranty_sequence')) {
                $table->unsignedInteger('warranty_sequence')->nullable()->after('parent_repair_request_id');
            }

            if (!Schema::hasColumn('repair_requests', 'warranty_claim_id')) {
                $table->unsignedBigInteger('warranty_claim_id')->nullable()->after('warranty_sequence');
                $table->foreign('warranty_claim_id', 'repair_requests_warranty_claim_fk')
                    ->references('id')
                    ->on('repair_warranty_claims')
                    ->nullOnDelete();
                $table->index('warranty_claim_id', 'repair_requests_warranty_claim_idx');
            }

            if (!Schema::hasColumn('repair_requests', 'billing_mode')) {
                $table->string('billing_mode', 64)->nullable()->after('warranty_claim_id');
            }

            if (!Schema::hasColumn('repair_requests', 'warranty_display_alias')) {
                $table->string('warranty_display_alias')->nullable()->after('billing_mode');
            }

            if (!Schema::hasColumn('repair_requests', 'repair_handler_user_id')) {
                $table->unsignedBigInteger('repair_handler_user_id')->nullable()->after('warranty_display_alias');
                $table->foreign('repair_handler_user_id', 'repair_requests_handler_user_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
                $table->index('repair_handler_user_id', 'repair_requests_handler_user_idx');
            }

            if (!Schema::hasColumn('repair_requests', 'handler_source')) {
                $table->string('handler_source', 32)->nullable()->after('repair_handler_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            if (Schema::hasColumn('repair_requests', 'handler_source')) {
                $table->dropColumn('handler_source');
            }

            if (Schema::hasColumn('repair_requests', 'repair_handler_user_id')) {
                $table->dropForeign('repair_requests_handler_user_fk');
                $table->dropIndex('repair_requests_handler_user_idx');
                $table->dropColumn('repair_handler_user_id');
            }

            if (Schema::hasColumn('repair_requests', 'warranty_display_alias')) {
                $table->dropColumn('warranty_display_alias');
            }

            if (Schema::hasColumn('repair_requests', 'billing_mode')) {
                $table->dropColumn('billing_mode');
            }

            if (Schema::hasColumn('repair_requests', 'warranty_claim_id')) {
                $table->dropForeign('repair_requests_warranty_claim_fk');
                $table->dropIndex('repair_requests_warranty_claim_idx');
                $table->dropColumn('warranty_claim_id');
            }

            if (Schema::hasColumn('repair_requests', 'warranty_sequence')) {
                $table->dropColumn('warranty_sequence');
            }

            if (Schema::hasColumn('repair_requests', 'parent_repair_request_id')) {
                $table->dropForeign('repair_requests_parent_warranty_fk');
                $table->dropIndex('repair_requests_parent_warranty_idx');
                $table->dropColumn('parent_repair_request_id');
            }

            if (Schema::hasColumn('repair_requests', 'is_warranty_job')) {
                $table->dropColumn('is_warranty_job');
            }
        });

        Schema::table('shop_owners', function (Blueprint $table) {
            if (Schema::hasColumn('shop_owners', 'warranty_enabled')) {
                $table->dropColumn('warranty_enabled');
            }

            if (Schema::hasColumn('shop_owners', 'repair_warranty_days')) {
                $table->dropColumn('repair_warranty_days');
            }
        });
    }
};
