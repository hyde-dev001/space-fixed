<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->foreignId('shop_owner_id')
                ->nullable()
                ->after('pos_transaction_id')
                ->constrained('shop_owners')
                ->restrictOnDelete();
        });

        $nextByShop = [];
        DB::table('pos_receipts')
            ->select(['id', 'pos_transaction_id', 'receipt_no'])
            ->orderBy('id')
            ->each(function (object $receipt) use (&$nextByShop): void {
                $shopOwnerId = DB::table('pos_transactions')
                    ->where('id', $receipt->pos_transaction_id)
                    ->value('shop_owner_id');

                if ($shopOwnerId === null) {
                    throw new RuntimeException('Cannot scope receipt '.$receipt->id.' to a shop.');
                }

                DB::table('pos_receipts')
                    ->where('id', $receipt->id)
                    ->update(['shop_owner_id' => $shopOwnerId]);

                if (preg_match('/^RCPT-(\d{6})$/', (string) $receipt->receipt_no, $matches) === 1) {
                    $nextByShop[$shopOwnerId] = max(
                        $nextByShop[$shopOwnerId] ?? 1,
                        ((int) $matches[1]) + 1,
                    );
                }
            });

        foreach ($nextByShop as $shopOwnerId => $nextNumber) {
            DB::table('shop_receipt_sequences')
                ->where('shop_owner_id', $shopOwnerId)
                ->update([
                    'next_number' => $nextNumber,
                    'updated_at' => now(),
                ]);
        }

        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropUnique('pos_receipts_receipt_no_unique');
            $table->unique(
                ['shop_owner_id', 'receipt_no'],
                'pos_receipts_shop_owner_receipt_no_unique',
            );
            $table->index('shop_owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropUnique('pos_receipts_shop_owner_receipt_no_unique');
            $table->dropIndex(['shop_owner_id']);
            $table->dropForeign(['shop_owner_id']);
            $table->dropColumn('shop_owner_id');
            $table->unique('receipt_no');
        });
    }
};
