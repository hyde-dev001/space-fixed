<?php

namespace Tests\Feature\Cod;

use App\Models\OrderRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CodSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cod_collection_and_remittance_tables_have_the_dedicated_contract(): void
    {
        $this->assertTrue(Schema::hasTable('cod_collections'));
        $this->assertTrue(Schema::hasTable('cod_remittances'));
        $this->assertTrue(Schema::hasTable('cod_remittance_items'));

        $this->assertTrue(Schema::hasColumns('cod_collections', [
            'shop_owner_id',
            'order_id',
            'expected_amount',
            'collected_amount',
            'status',
            'collected_at',
            'settled_at',
        ]));

        $this->assertTrue(Schema::hasColumns('cod_remittances', [
            'shop_owner_id',
            'rider_user_id',
            'reference',
            'expected_amount',
            'submitted_amount',
            'received_amount',
            'variance_amount',
            'status',
        ]));

        $this->assertTrue(Schema::hasColumns('cod_remittance_items', [
            'cod_remittance_id',
            'cod_collection_id',
            'expected_amount',
        ]));

        $this->assertTrue(Schema::hasColumns('order_refunds', [
            'refund_destination_type',
            'refund_destination',
            'refund_provider',
            'payout_status',
            'payout_idempotency_key',
            'provider_payout_id',
            'provider_reference',
            'payout_initiated_at',
            'payout_succeeded_at',
            'payout_failed_at',
            'payout_reversed_at',
        ]));
    }

    #[Test]
    public function refund_destination_is_encrypted_and_only_exposes_a_masked_projection(): void
    {
        $refund = OrderRefund::factory()->create([
            'refund_destination_type' => 'gcash',
            'refund_destination' => [
                'account_name' => 'COD Customer',
                'account_number' => '09171234567',
            ],
        ]);

        $storedDestination = (string) DB::table('order_refunds')
            ->where('id', $refund->id)
            ->value('refund_destination');

        $this->assertStringNotContainsString('09171234567', $storedDestination);
        $this->assertSame([
            'account_name' => 'COD Customer',
            'account_number' => '*******4567',
        ], $refund->fresh()->maskedRefundDestination());
    }
}
