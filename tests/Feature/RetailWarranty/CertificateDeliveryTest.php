<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\RetailWarranty;
use App\Models\RetailWarrantyIssuance;
use App\Services\RetailWarrantyCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_real_combined_private_pdf_is_stable_after_retry_and_partial_refund(): void
    {
        Storage::fake('local');
        $issuance = RetailWarrantyIssuance::factory()->create(['order_snapshot' => ['number' => 'SS-1001', 'created_at' => now()->toIso8601String()],
            'customer_snapshot' => ['name' => 'José García', 'email' => 'buyer@example.test']]);
        foreach (['Product A' => 2, 'Product B' => 1, 'Product C' => 1] as $name => $qty) {
            RetailWarranty::factory()->create(['retail_warranty_issuance_id' => $issuance->id,
                'original_covered_quantity' => $qty, 'item_snapshot' => ['name' => $name, 'size' => '9', 'color' => 'Black', 'quantity' => $qty],
                'policy_snapshot' => ['title' => 'Product Warranty', 'duration_value' => 1, 'duration_unit' => 'years', 'terms' => str_repeat('Shop-defined coverage terms. ', 200), 'exclusions' => 'Wear', 'instructions' => 'Visit the shop']]);
        }
        $service = app(RetailWarrantyCertificateService::class);
        $path = $service->generate($issuance);
        $bytes = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(1, count(Storage::disk('local')->allFiles('retail-warranties')));
        $this->assertSame(hash('sha256', $bytes), $issuance->fresh()->certificate_hash);
        $html = view('warranties.retail-certificate', ['issuance' => $issuance->fresh()->load('warranties')])->render();
        foreach (['Product A', 'Product B', 'Product C', 'José García', 'SS-1001'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $issuance->warranties->first()->forceFill(['refunded_quantity' => 1])->save();
        $this->travel(2)->days();
        $this->assertSame($path, $service->generate($issuance->fresh()));
        $this->assertSame($bytes, Storage::disk('local')->get($path));
    }
}
