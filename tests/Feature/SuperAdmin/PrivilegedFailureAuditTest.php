<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\ShopOwner;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PrivilegedFailureAuditTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_capability_denial_is_correlation_aware_and_allowlisted(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson('/admin/premium-plans', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'privileged_capability_denied');

        $correlationId = $response->json('correlation_id');
        $this->assertTrue(Str::isUuid($correlationId));
        $this->assertSame($correlationId, $response->headers->get('X-Correlation-ID'));

        $activity = DB::table('activity_log')
            ->where('description', 'privileged_capability_denied')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);

        $properties = json_decode((string) $activity->properties, true);
        $this->assertSame(SuperAdmin::CAP_MANAGE_PLANS, $properties['capability']);
        $this->assertSame('admin.premium-plans.store', $properties['route']);
        $this->assertSame($correlationId, $properties['correlation_id']);
        $this->assertArrayNotHasKey('input', $properties);
        $this->assertArrayNotHasKey('exception', $properties);
    }

    public function test_conflicting_review_is_generic_and_audited_without_exception_text(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $upgradeRequest = ShopOwnerUpgradeRequest::factory()->approved()->create([
            'shop_owner_id' => $owner->id,
        ]);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->patchJson(route('admin.business-upgrade-requests.update', $upgradeRequest), [
                'decision' => 'approved',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'shop_owner_upgrade_conflict');

        $this->assertStringNotContainsString('already been decided', (string) $response->getContent());
        $this->assertFailureEvent('privileged_workflow_conflict', 'shop_owner_upgrade', $response->json('correlation_id'));
    }

    public function test_withdrawn_subscription_plan_swap_has_no_failure_audit_path(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $before = DB::table('activity_log')->count();

        foreach (['upgrade', 'downgrade'] as $action) {
            $this->actingAsCompletedPrivileged($admin)
                ->postJson("/admin/subscriptions/999999/{$action}", [
                    'target_plan_id' => 1,
                ])
                ->assertNotFound();
        }

        $this->assertSame($before, DB::table('activity_log')->count());
    }

    public function test_validation_failure_does_not_create_failure_audit_noise(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $before = DB::table('activity_log')->where('log_name', 'privileged')->count();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson('/admin/premium-plans', [])
            ->assertUnprocessable();

        $this->assertSame($before, DB::table('activity_log')->where('log_name', 'privileged')->count());
    }

    public function test_failure_audit_write_failure_preserves_original_safe_response(): void
    {
        $correlationId = (string) Str::uuid();
        $audit = Mockery::mock(PrivilegedAudit::class);
        $audit->shouldReceive('correlationId')->once()->andReturn($correlationId);
        $audit->shouldReceive('privilegedWorkflowFailed')
            ->once()
            ->andThrow(new \RuntimeException('audit sink secret'));
        $this->instance(PrivilegedAudit::class, $audit);

        $request = Request::create('/admin/test-failure', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $response = app(PrivilegedFailureResponse::class)->unexpected(
            request: $request,
            operation: 'test_failure',
            exception: new \RuntimeException('SQLSTATE private token secret'),
            message: 'The operation could not be completed.',
            code: 'test_failure_error',
            forceJson: true,
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame($correlationId, $response->getData(true)['correlation_id']);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        $this->assertStringNotContainsString('audit sink secret', (string) $response->getContent());
    }

    private function assertFailureEvent(string $event, string $operation, ?string $correlationId): void
    {
        $this->assertTrue(Str::isUuid($correlationId));

        $activity = DB::table('activity_log')
            ->where('description', $event)
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $properties = json_decode((string) $activity->properties, true);
        $this->assertSame($operation, $properties['operation']);
        $this->assertSame($correlationId, $properties['correlation_id']);
        $this->assertArrayNotHasKey('exception', $properties);
        $this->assertArrayNotHasKey('input', $properties);
    }
}
