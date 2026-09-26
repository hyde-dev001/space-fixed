<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformFee;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PlatformFeeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_platform_fee_indexes_use_explicit_mysql_safe_names(): void
    {
        $expectedIndexes = [
            'platform_fee_adjustments_shop_adjtype_created_idx' => [
                'table' => 'platform_fee_adjustments',
                'columns' => ['shop_id', 'adjustment_type', 'created_at'],
            ],
            'platform_credit_source_ref_unique' => [
                'table' => 'platform_credit_applications',
                'columns' => ['source_type', 'source_id', 'source_origin'],
            ],
            'platform_fee_alloc_payment_type_idx' => [
                'table' => 'platform_fee_payment_allocations',
                'columns' => ['platform_fee_payment_id', 'allocation_type'],
            ],
            'platform_fee_alloc_credit_type_idx' => [
                'table' => 'platform_fee_payment_allocations',
                'columns' => ['platform_credit_application_id', 'allocation_type'],
            ],
            'platform_fee_recommendations_shop_status_created_idx' => [
                'table' => 'platform_fee_recommendations',
                'columns' => ['shop_owner_id', 'status', 'created_at'],
            ],
        ];

        foreach ($expectedIndexes as $name => $expected) {
            $indexes = Schema::getIndexes($expected['table']);
            $index = collect($indexes)->firstWhere('name', $name);

            $this->assertNotNull($index, $name);
            $this->assertSame($expected['columns'], $index['columns'], $name);
        }
    }
}
