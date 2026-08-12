<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Actions\superAdmin\ReviewShopOwnerUpgradeRequest;
use App\Enums\PrivilegedDeliveryType;
use App\Exceptions\ShopOwnerUpgradeReviewConflict;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ShopOwnerUpgradeConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
    }

    public function test_stale_review_models_produce_one_terminal_decision_and_one_audit_set(): void
    {
        $firstReviewer = $this->createAdmin('first');
        $secondReviewer = $this->createAdmin('second');
        [$owner, $request] = $this->submitRequest();
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $firstModel = $request->fresh();
        $staleModel = $request->fresh();
        $review = app(ReviewShopOwnerUpgradeRequest::class);

        $review->handle($firstModel, $firstReviewer, ShopOwnerUpgradeRequest::STATUS_APPROVED);
        DB::commit();

        try {
            $review->handle($staleModel, $secondReviewer, ShopOwnerUpgradeRequest::STATUS_REJECTED, 'Too late.');
            $this->fail('A stale review model should not be able to create a second decision.');
        } catch (ShopOwnerUpgradeReviewConflict $exception) {
            $this->assertSame('This upgrade request has already been decided.', $exception->getMessage());
        }

        $this->assertSame(ShopOwnerUpgradeRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame('company', $owner->fresh()->registration_type);
        $this->assertSame(1, DB::table('activity_log')->where('description', 'shop_owner_upgrade_reviewed')->count());
        $this->assertSame(0, DB::table('activity_log')->where('description', 'shop_owner_module_initialized')->count());
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job) use ($owner, $request): bool {
            return $job->deliveryType === PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REVIEWED
                && $job->recipientType === 'shop_owner'
                && $job->recipientId === $owner->id
                && $job->businessEventId === 'shop-owner-upgrade-reviewed:'.$request->id;
        });

        $decisionRows = DB::table('activity_log')->where('description', 'shop_owner_upgrade_reviewed')->get();
        $this->assertNotEmpty($decisionRows);
        $correlationId = json_decode((string) $decisionRows->first()->properties, true)['correlation_id'] ?? null;
        $this->assertNotEmpty($correlationId);
    }

    public function test_mysql_serializes_simultaneous_submissions(): void
    {
        $this->requireMysqlConcurrencySupport();
        $this->markTestSkipped('The project does not expose a safe process-isolated MySQL fixture in this environment.');
    }

    public function test_mysql_serializes_simultaneous_opposing_review_decisions(): void
    {
        $this->requireMysqlConcurrencySupport();
        $this->markTestSkipped('The project does not expose a safe process-isolated MySQL fixture in this environment.');
    }

    /**
     * @return array{0: ShopOwner, 1: ShopOwnerUpgradeRequest}
     */
    private function submitRequest(): array
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), [
                'requested_registration_type' => 'company',
                'requested_business_type' => 'both',
                'documents' => [
                    'dti_registration' => UploadedFile::fake()->createWithContent('dti_registration.pdf', 'dti'),
                    'mayors_permit' => UploadedFile::fake()->createWithContent('mayors_permit.pdf', 'permit'),
                    'bir_certificate' => UploadedFile::fake()->createWithContent('bir_certificate.pdf', 'bir'),
                    'valid_id' => UploadedFile::fake()->createWithContent('valid_id.pdf', 'id'),
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        return [$owner->fresh(), ShopOwnerUpgradeRequest::query()->latest('id')->firstOrFail()];
    }

    private function createAdmin(string $suffix): SuperAdmin
    {
        return SuperAdmin::create([
            'first_name' => 'Concurrency',
            'last_name' => $suffix,
            'email' => "concurrency-{$suffix}@example.test",
            'phone' => '09170000003',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function requireMysqlConcurrencySupport(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL row-lock verification is intentionally skipped on SQLite.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL concurrency verification requires the pcntl extension.');
        }
    }
}
