<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\ShopDocument;
use App\Models\ShopDocumentReminderDelivery;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SendShopDocumentExpiryRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_only_thirty_seven_and_zero_day_thresholds_are_sent_using_the_local_business_date(): void
    {
        $reviewer = SuperAdmin::factory()->admin()->create();
        $date = CarbonImmutable::parse('2026-08-13', 'Asia/Manila');

        foreach ([31, 30, 29, 8, 7, 6, 1, 0, -1] as $days) {
            $this->documentForDate($date->addDays($days), $reviewer);
        }

        $this->artisan('shop-documents:send-expiry-reminders', ['--date' => $date->toDateString()])
            ->assertExitCode(0);

        $this->assertSame(3, ShopDocumentReminderDelivery::query()->count());
        $this->assertSame(3, Notification::query()
            ->where('type', NotificationType::SHOP_DOCUMENT_EXPIRING->value)
            ->count());
        $this->assertSame([0, 7, 30], ShopDocumentReminderDelivery::query()
            ->orderBy('threshold_days')
            ->pluck('threshold_days')
            ->all());
        $this->assertSame(9, ShopDocument::query()->where('status', 'approved')->where('is_current', true)->count());
    }

    public function test_local_timezone_is_used_when_date_is_omitted(): void
    {
        $reviewer = SuperAdmin::factory()->admin()->create();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-13 16:00:00', 'UTC'));
        $localToday = CarbonImmutable::now('Asia/Manila')->toDateString();
        $owner = ShopOwner::factory()->approved()->create();
        $document = $this->documentForDate(CarbonImmutable::parse($localToday, 'Asia/Manila')->addDays(30), $reviewer, $owner);

        $this->artisan('shop-documents:send-expiry-reminders')->assertExitCode(0);

        $this->assertDatabaseHas('shop_document_reminder_deliveries', [
            'shop_document_id' => $document->id,
            'threshold_days' => 30,
        ]);
    }

    public function test_replay_is_idempotent_and_changed_expiration_identity_can_notify_again(): void
    {
        $reviewer = SuperAdmin::factory()->admin()->create();
        $owner = ShopOwner::factory()->approved()->create();
        $document = $this->documentForDate('2026-08-31', $reviewer, $owner);

        $this->artisan('shop-documents:send-expiry-reminders', ['--date' => '2026-08-01'])
            ->assertExitCode(0);
        $this->artisan('shop-documents:send-expiry-reminders', ['--date' => '2026-08-01'])
            ->assertExitCode(0);
        $this->assertSame(1, ShopDocumentReminderDelivery::query()->count());

        $document->update(['expires_on' => '2026-09-01']);
        $this->artisan('shop-documents:send-expiry-reminders', ['--date' => '2026-08-02'])
            ->assertExitCode(0);

        $this->assertSame(2, ShopDocumentReminderDelivery::query()->count());
        $this->assertSame(2, Notification::query()->where('shop_owner_id', $owner->id)->count());
    }

    public function test_candidate_query_excludes_no_expiration_unknown_unverified_historical_pending_rejected_and_archived_rows(): void
    {
        $reviewer = SuperAdmin::factory()->admin()->create();
        $date = CarbonImmutable::parse('2026-08-13', 'Asia/Manila');
        $this->documentForDate($date->addDays(30), $reviewer, null, 'approved', true, 'none');
        $this->documentForDate($date->addDays(30), null, null, 'approved', true, 'dated');
        $this->documentForDate($date->addDays(30), $reviewer, null, 'approved', false, 'dated');
        $this->documentForDate($date->addDays(30), $reviewer, null, 'pending', false, 'dated');
        $this->documentForDate($date->addDays(30), $reviewer, null, 'rejected', false, 'dated');
        $archivedOwner = ShopOwner::factory()->approved()->create();
        $this->documentForDate($date->addDays(30), $reviewer, $archivedOwner);
        $archivedOwner->delete();

        $this->artisan('shop-documents:send-expiry-reminders', ['--date' => $date->toDateString()])
            ->assertExitCode(0);

        $this->assertSame(0, ShopDocumentReminderDelivery::query()->count());
        $this->assertSame(0, Notification::query()->where('type', NotificationType::SHOP_DOCUMENT_EXPIRING->value)->count());
    }

    public function test_owner_filter_and_chunk_are_bounded_and_invalid_date_is_rejected(): void
    {
        $reviewer = SuperAdmin::factory()->admin()->create();
        $date = CarbonImmutable::parse('2026-08-13', 'Asia/Manila');
        $owner = ShopOwner::factory()->approved()->create();
        $this->documentForDate($date->addDays(30), $reviewer, $owner);
        $this->documentForDate($date->addDays(30), $reviewer);

        $this->artisan('shop-documents:send-expiry-reminders', [
            '--date' => $date->toDateString(),
            '--shop-owner-id' => (string) $owner->id,
            '--chunk' => '100000',
        ])->assertExitCode(0);

        $this->assertSame(1, ShopDocumentReminderDelivery::query()->count());
        $this->artisan('shop-documents:send-expiry-reminders', ['--date' => 'not-a-date'])
            ->assertExitCode(1);
    }

    private function documentForDate(
        CarbonImmutable|string $expiresOn,
        ?SuperAdmin $reviewer,
        ?ShopOwner $owner = null,
        string $status = 'approved',
        bool $isCurrent = true,
        string $expirationMode = 'dated',
    ): ShopDocument {
        $owner ??= ShopOwner::factory()->approved()->create();
        $path = 'shop_documents/'.$owner->id.'/permit-'.uniqid('', true).'.png';
        Storage::disk('local')->put($path, 'permit');

        return ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => 1,
            'file_path' => $path,
            'disk' => 'local',
            'status' => $status,
            'is_current' => $isCurrent,
            'expiration_mode' => $expirationMode,
            'expires_on' => $expirationMode === 'dated' ? (string) $expiresOn : null,
            'reviewed_by_super_admin_id' => $reviewer?->id,
            'reviewed_at' => $reviewer ? '2026-01-02 00:00:00' : null,
            'checksum_sha256' => hash('sha256', 'permit'),
        ]);
    }
}
