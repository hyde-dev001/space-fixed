<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Services\OwnerOperationAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class OwnerOperationAuditTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_records_a_tenant_bound_owner_operation_with_only_safe_properties(): void
    {
        $owner = $this->owner();
        $module = ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->getKey(),
            'module_key' => 'retail_operations',
            'enabled' => false,
        ]);

        $activity = app(OwnerOperationAudit::class)->record(
            actor: $owner,
            tenantOwnerId: (int) $owner->getKey(),
            module: 'settings',
            action: 'module.updated',
            result: 'succeeded',
            subject: $module,
            context: [
                'correlation_id' => 'corr-phase5-001',
                'source' => 'settings.modules-team',
                'before' => [
                    'enabled' => false,
                    'password' => 'never-store-this',
                ],
                'after' => [
                    'enabled' => true,
                    'paymongo_secret_key' => 'never-store-this-either',
                ],
                'payload' => ['secret' => 'drop-me'],
            ],
        );

        $this->assertInstanceOf(Activity::class, $activity);
        $this->assertSame('owner_operation', $activity->log_name);
        $this->assertSame('module.updated', $activity->event);
        $this->assertSame(ShopOwner::class, $activity->causer_type);
        $this->assertSame($owner->getKey(), $activity->causer_id);
        $this->assertSame(ShopOwnerModule::class, $activity->subject_type);
        $this->assertSame($module->getKey(), $activity->subject_id);
        $this->assertSame([
            'shop_owner_id' => $owner->getKey(),
            'module' => 'settings',
            'action' => 'module.updated',
            'result' => 'succeeded',
            'correlation_id' => 'corr-phase5-001',
            'source' => 'settings.modules-team',
            'before' => ['enabled' => false],
            'after' => ['enabled' => true],
        ], $activity->properties->toArray());
    }

    #[Test]
    public function it_rolls_back_with_the_callers_transaction(): void
    {
        $owner = $this->owner();

        try {
            DB::transaction(function () use ($owner): void {
                app(OwnerOperationAudit::class)->record(
                    actor: $owner,
                    tenantOwnerId: (int) $owner->getKey(),
                    module: 'settings',
                    action: 'profile.updated',
                    result: 'succeeded',
                );

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('activity_log', 0);
    }

    #[Test]
    public function it_rejects_an_actor_from_a_different_tenant_before_writing(): void
    {
        $actor = $this->owner();
        $otherOwner = $this->owner();

        $this->expectException(AuthorizationException::class);

        app(OwnerOperationAudit::class)->record(
            actor: $actor,
            tenantOwnerId: (int) $otherOwner->getKey(),
            module: 'settings',
            action: 'profile.updated',
            result: 'succeeded',
        );
    }

    #[Test]
    public function it_rejects_a_subject_from_a_different_tenant_before_writing(): void
    {
        $owner = $this->owner();
        $otherOwner = $this->owner();
        $module = ShopOwnerModule::factory()->create([
            'shop_owner_id' => $otherOwner->getKey(),
            'module_key' => 'retail_operations',
            'enabled' => false,
        ]);

        $this->expectException(AuthorizationException::class);

        app(OwnerOperationAudit::class)->record(
            actor: $owner,
            tenantOwnerId: (int) $owner->getKey(),
            module: 'settings',
            action: 'module.updated',
            result: 'succeeded',
            subject: $module,
        );
    }

    #[Test]
    public function denied_attempts_can_be_recorded_without_sensitive_request_data(): void
    {
        $owner = $this->owner();

        $activity = app(OwnerOperationAudit::class)->record(
            actor: $owner,
            tenantOwnerId: (int) $owner->getKey(),
            module: 'approvals',
            action: 'maker-checker.denied',
            result: 'denied',
            context: [
                'correlation_id' => 'corr-denied-001',
                'reason_code' => 'owner_self_approval_not_allowed',
                'guard' => 'shop_owner',
                'target_type' => 'salary_change',
                'target_id' => 42,
                'request_payload' => ['salary' => 999999],
                'notes' => 'confidential details must not persist',
            ],
        );

        $properties = $activity->properties->toArray();

        $this->assertSame('denied', $properties['result']);
        $this->assertSame('owner_self_approval_not_allowed', $properties['reason_code']);
        $this->assertSame('shop_owner', $properties['guard']);
        $this->assertSame('salary_change', $properties['target_type']);
        $this->assertSame(42, $properties['target_id']);
        $this->assertArrayNotHasKey('request_payload', $properties);
        $this->assertArrayNotHasKey('notes', $properties);
    }

    #[Test]
    public function it_generates_a_correlation_id_when_the_caller_does_not_provide_one(): void
    {
        $owner = $this->owner();

        $activity = app(OwnerOperationAudit::class)->record(
            actor: $owner,
            tenantOwnerId: (int) $owner->getKey(),
            module: 'settings',
            action: 'profile.updated',
            result: 'succeeded',
        );

        $this->assertIsString($activity->properties['correlation_id']);
        $this->assertNotSame('', $activity->properties['correlation_id']);
    }

    private function owner(): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
    }
}
