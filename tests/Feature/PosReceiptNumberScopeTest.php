<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PosReceipt;
use App\Models\PosTransaction;
use App\Models\ShopOwner;
use App\Services\RepairPosReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PosReceiptNumberScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function retail_and_repair_share_a_concurrency_safe_sequence_per_shop(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00'));

        try {
            $shopA = ShopOwner::factory()->approved()->create();
            $shopB = ShopOwner::factory()->approved()->create();

            $historical = $this->transaction($shopA, 'repair');
            PosReceipt::create([
                'pos_transaction_id' => $historical->id,
                'receipt_no' => 'RCPT-20250101-000001',
                'issued_at' => Carbon::parse('2025-01-01'),
                'print_payload' => ['legacy' => true],
                'digital_payload' => ['legacy' => true],
            ]);

            $retailTransaction = $this->transaction($shopA, 'retail');
            $retailReceipt = app(RepairPosReceiptService::class)->issue($retailTransaction);
            $retriedReceipt = app(RepairPosReceiptService::class)->issue($retailTransaction);
            $repairReceipt = app(RepairPosReceiptService::class)->issue($this->transaction($shopA, 'repair'));
            $otherShopReceipt = app(RepairPosReceiptService::class)->issue($this->transaction($shopB, 'retail'));

            $this->assertSame('RCPT-000001', $retailReceipt->receipt_no);
            $this->assertSame($retailReceipt->id, $retriedReceipt->id);
            $this->assertSame($retailReceipt->receipt_no, $retriedReceipt->receipt_no);
            $this->assertSame('RCPT-000002', $repairReceipt->receipt_no);
            $this->assertSame('RCPT-000001', $otherShopReceipt->receipt_no);
            $this->assertNotSame($retailReceipt->shop_owner_id, $otherShopReceipt->shop_owner_id);
            $this->assertSame(
                'RCPT-20250101-000001',
                PosReceipt::query()->where('pos_transaction_id', $historical->id)->value('receipt_no'),
            );
            $this->assertSame(
                2,
                PosReceipt::query()->whereIn('id', [
                    $retailReceipt->id,
                    $repairReceipt->id,
                    $otherShopReceipt->id,
                ])->distinct('receipt_no')->count('receipt_no'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function transaction(ShopOwner $shop, string $module): PosTransaction
    {
        return PosTransaction::create([
            'transaction_no' => strtoupper($module) . '-' . Str::uuid(),
            'shop_owner_id' => $shop->id,
            'module_type' => $module,
            'module_reference_id' => 1,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Test Customer',
            'due_type' => 'full',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'status' => 'paid',
            'paid_at' => now(),
            'metadata' => [],
        ]);
    }
}
