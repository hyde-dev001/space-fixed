<?php

declare(strict_types=1);

namespace Tests\Feature\ShopDocuments;

use App\Models\ShopDocument;
use App\Services\ShopOwnerDocumentRequirementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ShopDocumentLifecycleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_documents_has_the_additive_lifecycle_schema_and_reminder_identity_table(): void
    {
        $this->assertTrue(Schema::hasColumns('shop_documents', [
            'logical_slot',
            'version_number',
            'predecessor_document_id',
            'is_current',
            'superseded_at',
            'issued_on',
            'expiration_mode',
            'expires_on',
            'reviewed_by_super_admin_id',
            'reviewed_at',
            'rejection_reason',
            'submission_key',
            'checksum_sha256',
        ]));

        $this->assertTrue(Schema::hasTable('shop_document_reminder_deliveries'));

        $recipientIndex = collect(Schema::getIndexes('shop_document_reminder_deliveries'))
            ->firstWhere('name', 'shop_doc_reminder_recipient_index');
        $this->assertNotNull($recipientIndex);
    }

    public function test_reminder_delivery_migration_repairs_a_partial_table(): void
    {
        $existingRecipientIndex = collect(Schema::getIndexes('shop_document_reminder_deliveries'))
            ->first(static fn (array $index): bool => ($index['columns'] ?? []) === ['recipient_type', 'recipient_id']);
        $this->assertNotNull($existingRecipientIndex);

        Schema::table('shop_document_reminder_deliveries', function (Blueprint $table) use ($existingRecipientIndex): void {
            $table->dropIndex($existingRecipientIndex['name']);
        });

        $migration = require base_path(
            'database/migrations/2026_08_12_195256_create_shop_document_reminder_deliveries_table.php',
        );
        $migration->up();

        $recipientIndex = collect(Schema::getIndexes('shop_document_reminder_deliveries'))
            ->firstWhere('name', 'shop_doc_reminder_recipient_index');

        $this->assertNotNull($recipientIndex);
        $this->assertTrue(Schema::hasColumns('shop_document_reminder_deliveries', [
            'shop_document_id',
            'expiration_identity',
            'notification_id',
        ]));
    }

    public function test_shop_document_exposes_private_lifecycle_fields_safely(): void
    {
        $document = new ShopDocument();
        $casts = $document->getCasts();

        $this->assertSame('integer', $casts['version_number'] ?? null);
        $this->assertSame('boolean', $casts['is_current'] ?? null);
        $this->assertSame('date', $casts['issued_on'] ?? null);
        $this->assertSame('date', $casts['expires_on'] ?? null);
        $this->assertSame('datetime', $casts['reviewed_at'] ?? null);
        $this->assertSame('datetime', $casts['superseded_at'] ?? null);

        $this->assertContains('checksum_sha256', $document->getHidden());
        $this->assertContains('file_path', $document->getHidden());
        $this->assertContains('predecessor_document_id', $document->getFillable());
        $this->assertTrue(method_exists($document, 'predecessor'));
        $this->assertTrue(method_exists($document, 'successors'));
        $this->assertTrue(method_exists($document, 'reviewer'));
        $this->assertTrue(method_exists($document, 'reminderDeliveries'));
    }

    public function test_fixed_document_types_resolve_to_logical_slots_without_legacy_input_support(): void
    {
        $requirements = app(ShopOwnerDocumentRequirementService::class);

        $this->assertSame('business_registration', $requirements->slotForType('dti_registration'));
        $this->assertSame('business_registration', $requirements->slotForType('sec_registration'));
        $this->assertSame('mayors_permit', $requirements->slotForType('mayors_permit'));
        $this->assertSame('bir_certificate', $requirements->slotForType('bir_certificate'));
        $this->assertSame('valid_id', $requirements->slotForType('valid_id'));
        $this->assertStringStartsWith('supporting_document:', $requirements->slotForType('supporting_document:4d6f2e2c-4c8e-4f50-9a0e-9a02fdad7d6d'));
        $this->assertNull($requirements->slotForType('legacy_dti_sec_registration'));
    }
}
