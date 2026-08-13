<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ShopDocument;
use App\Services\ShopDocumentValidityService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class ShopDocumentValidityServiceTest extends TestCase
{
    public function test_classification_uses_calendar_days_and_never_persists_state(): void
    {
        $today = CarbonImmutable::create(2026, 8, 13, 23, 30, 0, 'UTC');
        $document = new ShopDocument([
            'status' => 'approved',
            'is_current' => true,
            'reviewed_by_super_admin_id' => 7,
            'reviewed_at' => $today,
            'expiration_mode' => 'dated',
            'expires_on' => '2026-09-12',
        ]);
        $document->exists = true;
        $before = $document->getAttributes();

        $service = new ShopDocumentValidityService();

        $this->assertSame('expiring_soon', $service->classify($document, $today));
        $this->assertSame($before, $document->getAttributes());
    }

    public function test_unverified_and_unknown_metadata_are_not_validity_states(): void
    {
        $today = CarbonImmutable::create(2026, 8, 13, 0, 0, 0, 'Asia/Manila');
        $service = new ShopDocumentValidityService();

        $unverified = new ShopDocument([
            'status' => 'approved',
            'is_current' => true,
            'expiration_mode' => 'dated',
            'expires_on' => '2027-01-01',
        ]);
        $unknown = new ShopDocument([
            'status' => 'approved',
            'is_current' => true,
            'reviewed_by_super_admin_id' => 7,
            'reviewed_at' => $today,
            'expiration_mode' => 'unknown',
        ]);

        $this->assertSame('metadata_unverified', $service->classify($unverified, $today));
        $this->assertSame('metadata_unverified', $service->classify($unknown, $today));
    }
}
