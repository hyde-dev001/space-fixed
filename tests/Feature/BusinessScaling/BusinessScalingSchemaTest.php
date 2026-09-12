<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\ShopOwnerUpgradeRequestDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BusinessScalingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_scaling_tables_have_the_required_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('shop_owner_upgrade_requests'));
        $this->assertTrue(Schema::hasTable('shop_owner_upgrade_request_documents'));
        $this->assertTrue(Schema::hasTable('shop_owner_modules'));

        foreach ([
            'shop_owner_id',
            'current_registration_type',
            'current_business_type',
            'requested_registration_type',
            'requested_business_type',
            'status',
            'required_document_set',
            'decision_reason',
            'reviewed_by_super_admin_id',
            'reviewed_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('shop_owner_upgrade_requests', $column), $column);
        }

        foreach ([
            'shop_owner_upgrade_request_id',
            'source_shop_document_id',
            'document_type',
            'disk',
            'path',
            'checksum_sha256',
            'mime_type',
            'size',
            'source_status',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('shop_owner_upgrade_request_documents', $column), $column);
        }

        foreach (['shop_owner_id', 'module_key', 'enabled', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('shop_owner_modules', $column), $column);
        }

        $requestIndexes = Schema::getIndexes('shop_owner_upgrade_requests');
        $moduleIndexes = Schema::getIndexes('shop_owner_modules');

        $this->assertTrue($this->hasIndex($requestIndexes, ['status', 'created_at']));
        $this->assertTrue($this->hasIndex($requestIndexes, ['shop_owner_id']));
        $this->assertTrue($this->hasIndex($moduleIndexes, ['shop_owner_id', 'module_key'], unique: true));
    }

    public function test_models_expose_relationships_casts_and_protect_evidence_paths(): void
    {
        $request = new ShopOwnerUpgradeRequest;
        $document = new ShopOwnerUpgradeRequestDocument;
        $module = new ShopOwnerModule;

        $this->assertSame('array', $request->getCasts()['required_document_set']);
        $this->assertSame('datetime', $request->getCasts()['reviewed_at']);
        $this->assertSame('boolean', $module->getCasts()['enabled']);
        $this->assertSame('integer', $document->getCasts()['size']);
        $this->assertContains('path', $document->getHidden());

        $this->assertInstanceOf(ShopOwner::class, $request->shopOwner()->getRelated());
        $this->assertInstanceOf(ShopOwnerUpgradeRequestDocument::class, $request->documents()->getRelated());
        $this->assertInstanceOf(ShopOwnerUpgradeRequest::class, $document->upgradeRequest()->getRelated());
        $this->assertInstanceOf(ShopOwner::class, $module->shopOwner()->getRelated());
    }

    public function test_module_rows_are_unique_per_owner_and_key(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'inventory',
        ]);

        $this->expectException(QueryException::class);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'inventory',
        ]);
    }

    /**
     * @param array<int, array{name: string, columns: array<int, string>, unique: bool}> $indexes
     * @param array<int, string> $columns
     */
    private function hasIndex(array $indexes, array $columns, bool $unique = false): bool
    {
        foreach ($indexes as $index) {
            if ($index['columns'] === $columns && (! $unique || $index['unique'])) {
                return true;
            }
        }

        return false;
    }
}
