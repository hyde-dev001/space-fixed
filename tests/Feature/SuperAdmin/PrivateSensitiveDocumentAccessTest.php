<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivateSensitiveDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_disk_metadata_defaults_to_public_for_legacy_and_new_rows(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $document = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'mayors_permit',
            'file_path' => 'shop_documents/legacy-permit.pdf',
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'valid_id_path' => 'valid-ids/legacy-id.jpg',
        ]);

        $this->assertSame('public', $document->fresh()->disk);
        $this->assertSame('public', $user->fresh()->valid_id_disk);
        $this->assertDatabaseHas('shop_documents', [
            'id' => $document->id,
            'disk' => 'public',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'valid_id_disk' => 'public',
        ]);
    }

    public function test_trusted_application_code_can_persist_private_disk_metadata(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $document = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'bir_certificate',
            'file_path' => 'shop_documents/private-bir.pdf',
            'status' => 'pending',
        ]);
        $document->disk = 'local';
        $document->save();

        $user = User::factory()->create([
            'valid_id_path' => 'valid-ids/private-id.jpg',
        ]);
        $user->valid_id_disk = 'local';
        $user->save();

        $this->assertSame('local', $document->fresh()->disk);
        $this->assertSame('local', $user->fresh()->valid_id_disk);
    }

    public function test_disk_metadata_hides_sensitive_storage_paths_from_model_serialization(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $document = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'valid_id',
            'file_path' => 'shop_documents/private-id.jpg',
            'status' => 'pending',
        ]);
        $user = User::factory()->create([
            'valid_id_path' => 'valid-ids/private-id.jpg',
        ]);

        $this->assertArrayNotHasKey('file_path', $document->toArray());
        $this->assertArrayNotHasKey('valid_id_path', $user->toArray());
    }
}
