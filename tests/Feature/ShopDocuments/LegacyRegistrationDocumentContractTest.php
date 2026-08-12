<?php

declare(strict_types=1);

namespace Tests\Feature\ShopDocuments;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class LegacyRegistrationDocumentContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_api_registration_does_not_create_an_owner_without_the_canonical_document_contract(): void
    {
        $response = $this->postJson('/api/shop/register', [
            'firstName' => 'Juan',
            'lastName' => 'Dela Cruz',
            'email' => 'legacy-register@example.com',
            'phone' => '09171234567',
            'businessName' => 'Legacy Shoes',
            'businessAddress' => 'Dasmarinas, Cavite',
            'businessType' => 'repair',
            'registrationType' => 'individual',
            'shop_latitude' => 14.3294,
            'shop_longitude' => 120.9367,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['documents']);
        $this->assertDatabaseMissing('shop_owners', ['email' => 'legacy-register@example.com']);
    }

    public function test_legacy_full_registration_routes_do_not_write_unversioned_documents(): void
    {
        Storage::fake('public');

        $payload = [
            'firstName' => 'Maria',
            'lastName' => 'Reyes',
            'email' => 'legacy-full@example.com',
            'phone' => '09179876543',
            'businessName' => 'Legacy Footwear',
            'businessAddress' => 'Imus, Cavite',
            'businessType' => 'repair',
            'registrationType' => 'individual',
            'operatingHours' => [['day' => 'Monday', 'open' => '09:00', 'close' => '18:00']],
            'agreesToRequirements' => true,
            'shop_latitude' => 14.3294,
            'shop_longitude' => 120.9367,
            'dtiRegistration' => UploadedFile::fake()->create('dti.png', 120, 'image/png'),
            'mayorsPermit' => UploadedFile::fake()->create('permit.png', 120, 'image/png'),
            'birCertificate' => UploadedFile::fake()->create('bir.png', 120, 'image/png'),
            'validId' => UploadedFile::fake()->create('id.png', 120, 'image/png'),
        ];

        $this->postJson('/api/shop/register-full', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);

        $this->post('/shop/register-full', $payload)
            ->assertSessionHasErrors('documents');

        $this->assertDatabaseMissing('shop_owners', ['email' => 'legacy-full@example.com']);
        $this->assertDatabaseCount('shop_documents', 0);
    }
}
