<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addSnapshotColumn('order_refunds', 'shop_owner_status');
        $this->addSnapshotColumn('pos_refunds', 'shop_owner_status');
        $this->addSnapshotColumn('purchase_requests', 'status');
        $this->addSnapshotColumn('salary_changes', 'status');

        // Existing request refunds are owner-required. Automatic cancellation
        // refunds never entered the approval workflow and remain off.
        DB::table('order_refunds')
            ->where('flow_type', 'cancel_auto')
            ->whereNull('requires_owner_approval')
            ->update(['requires_owner_approval' => false]);
        DB::table('order_refunds')
            ->whereNull('requires_owner_approval')
            ->update(['requires_owner_approval' => true]);

        // An explicit skipped owner stage is the only legacy POS evidence that
        // the owner stage was not part of that refund's workflow.
        DB::table('pos_refunds')
            ->where('shop_owner_status', 'skipped')
            ->whereNull('requires_owner_approval')
            ->update(['requires_owner_approval' => false]);
        DB::table('pos_refunds')
            ->whereNull('requires_owner_approval')
            ->update(['requires_owner_approval' => true]);

        // Draft purchase requests and cancelled salary proposals never entered
        // approval. Every submitted or otherwise active legacy record defaults
        // to owner-required because its original setting is unknowable.
        DB::table('purchase_requests')
            ->where('status', '!=', 'draft')
            ->whereNull('requires_owner_approval')
            ->update(['requires_owner_approval' => true]);
        DB::table('salary_changes')
            ->where('status', '!=', 'cancelled')
            ->whereNull('requires_owner_approval')
            ->update(['requires_owner_approval' => true]);
    }

    public function down(): void
    {
        foreach (['order_refunds', 'pos_refunds', 'purchase_requests', 'salary_changes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'requires_owner_approval')) {
                    $table->dropColumn('requires_owner_approval');
                }
            });
        }
    }

    private function addSnapshotColumn(string $tableName, string $after): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName, $after): void {
            if (! Schema::hasColumn($tableName, 'requires_owner_approval')) {
                $table->boolean('requires_owner_approval')->nullable()->after($after);
            }
        });
    }
};
