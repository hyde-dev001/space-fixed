<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('replenishment_requests') || !Schema::hasTable('stock_request_approvals')) {
            return;
        }

        $migratedCount = 0;
        $skippedCount = 0;

        DB::table('replenishment_requests')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$migratedCount, &$skippedCount): void {
                foreach ($rows as $row) {
                    $alreadyExists = DB::table('stock_request_approvals')
                        ->where('request_number', $row->request_number)
                        ->exists();

                    if ($alreadyExists) {
                        $skippedCount++;
                        continue;
                    }

                    $approvalNotes = null;
                    $rejectionReason = null;

                    if ($row->status === 'rejected') {
                        $rejectionReason = $row->response_notes;
                    } elseif (in_array($row->status, ['accepted', 'needs_details'], true)) {
                        $approvalNotes = $row->response_notes;
                    }

                    DB::table('stock_request_approvals')->insert([
                        'request_number' => $row->request_number,
                        'shop_owner_id' => $row->shop_owner_id,
                        'inventory_item_id' => $row->inventory_item_id,
                        'repair_request_id' => null,
                        'product_name' => $row->product_name,
                        'sku_code' => $row->sku_code,
                        'quantity_needed' => $row->quantity_needed,
                        'requested_size' => null,
                        'priority' => $row->priority,
                        'request_source' => 'manual',
                        'status' => $row->status,
                        'requested_by' => $row->requested_by,
                        'requested_date' => $row->requested_date,
                        'approved_by' => $row->reviewed_by,
                        'approved_date' => $row->reviewed_date,
                        'notes' => $row->notes,
                        'approval_notes' => $approvalNotes,
                        'rejection_reason' => $rejectionReason,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                        'deleted_at' => $row->deleted_at,
                    ]);

                    $migratedCount++;
                }
            });

        Log::info('Replenishment backfill into stock_request_approvals completed.', [
            'migrated_rows' => $migratedCount,
            'skipped_existing_rows' => $skippedCount,
        ]);
    }

    public function down(): void
    {
        // Intentionally no destructive rollback.
        // Backfilled records should remain for audit consistency once copied.
    }
};
