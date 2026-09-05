<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceReceiptMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_receipt_migration_is_dry_run_safe_and_resumable(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $expense = Expense::create([
            'reference' => 'EXP-MIGRATE-'.uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Supplies',
            'amount' => '10.00',
            'tax_amount' => '0.00',
            'status' => 'submitted',
            'shop_id' => 1,
            'receipt_path' => 'receipts/legacy.pdf',
            'receipt_original_name' => 'legacy.pdf',
            'receipt_mime_type' => 'application/pdf',
        ]);
        Storage::disk('public')->put('receipts/legacy.pdf', 'legacy contents');

        $this->artisan('finance:migrate-receipts-private', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run complete')
            ->assertExitCode(0);
        $this->assertSame('receipts/legacy.pdf', $expense->fresh()->receipt_path);
        Storage::disk('local')->assertMissing('finance/shops/1/expenses/'.$expense->id.'/receipts/receipt.pdf');

        $this->artisan('finance:migrate-receipts-private', ['--chunk' => 1])
            ->expectsOutputToContain('Migration complete')
            ->assertExitCode(0);
        $target = 'finance/shops/1/expenses/'.$expense->id.'/receipts/receipt.pdf';
        $this->assertSame($target, $expense->fresh()->receipt_path);
        Storage::disk('local')->assertExists($target);
        Storage::disk('public')->assertExists('receipts/legacy.pdf');

        $this->artisan('finance:migrate-receipts-private', ['--chunk' => 1])
            ->assertExitCode(0);
        $this->assertSame($target, $expense->fresh()->receipt_path);
    }
}
