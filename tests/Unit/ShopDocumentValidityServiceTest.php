<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ShopDocument;
use App\Services\ShopDocumentValidityService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ShopDocumentValidityServiceTest extends TestCase
{
    #[DataProvider('expiryWindowProvider')]
    public function test_expiry_window_uses_authoritative_business_day_boundaries(
        int $daysRemaining,
        string $expectedWindow,
    ): void {
        config(['app.shop_timezone' => 'Asia/Manila']);
        $today = CarbonImmutable::parse('2026-08-13 00:00:00', 'Asia/Manila');
        $document = $this->verifiedDocument($today->addDays($daysRemaining)->toDateString(), $today);

        $service = new ShopDocumentValidityService();

        $this->assertSame($expectedWindow, $service->expiryWindow($document, $today));
    }

    /** @return array<string, array{int, string}> */
    public static function expiryWindowProvider(): array
    {
        return [
            'outside at 31 days' => [31, 'outside_window'],
            'renewal starts at 30 days' => [30, 'renewal_window'],
            'renewal continues at 8 days' => [8, 'renewal_window'],
            'urgent starts at 7 days' => [7, 'urgent_window'],
            'urgent continues at 1 day' => [1, 'urgent_window'],
            'expires today' => [0, 'expires_today'],
            'expired' => [-1, 'expired'],
        ];
    }

    public function test_expiry_window_distinguishes_non_expiring_and_unverified_metadata(): void
    {
        $today = CarbonImmutable::parse('2026-08-13 00:00:00', 'Asia/Manila');
        $service = new ShopDocumentValidityService();
        $nonExpiring = $this->verifiedDocument(null, $today, 'none');
        $unverified = $this->verifiedDocument('2026-08-20', $today);
        $unverified->reviewed_by_super_admin_id = null;

        $this->assertSame('non_expiring', $service->expiryWindow($nonExpiring, $today));
        $this->assertSame('metadata_unverified', $service->expiryWindow($unverified, $today));
        $this->assertSame([30, 7, 0], $service->milestoneDays());
    }

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

    private function verifiedDocument(
        ?string $expiresOn,
        CarbonImmutable $reviewedAt,
        string $expirationMode = 'dated',
    ): ShopDocument {
        return new ShopDocument([
            'status' => 'approved',
            'is_current' => true,
            'reviewed_by_super_admin_id' => 7,
            'reviewed_at' => $reviewedAt,
            'expiration_mode' => $expirationMode,
            'expires_on' => $expiresOn,
        ]);
    }
}
