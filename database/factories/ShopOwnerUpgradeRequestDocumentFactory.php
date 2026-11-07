<?php

namespace Database\Factories;

use App\Models\ShopOwnerUpgradeRequest;
use App\Models\ShopOwnerUpgradeRequestDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopOwnerUpgradeRequestDocument>
 */
class ShopOwnerUpgradeRequestDocumentFactory extends Factory
{
    protected $model = ShopOwnerUpgradeRequestDocument::class;

    public function definition(): array
    {
        return [
            'shop_owner_upgrade_request_id' => ShopOwnerUpgradeRequest::factory(),
            'source_shop_document_id' => null,
            'document_type' => 'valid_id',
            'disk' => 'local',
            'path' => 'shop-owner-upgrade-evidence/example/document.pdf',
            'checksum_sha256' => hash('sha256', 'business-scaling-evidence'),
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'source_status' => 'uploaded',
        ];
    }
}
