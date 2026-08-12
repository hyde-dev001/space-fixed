<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\AccountSuspension;
use App\Models\Employee;
use App\Models\ReviewReport;
use App\Models\ShopOwner;
use App\Models\ShopReport;
use App\Models\ShopReportModerationAction;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PhaseTwoSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_two_tables_and_lifecycle_columns_exist_with_required_indexes(): void
    {
        self::assertTrue(Schema::hasTable('account_suspensions'));
        self::assertTrue(Schema::hasColumns('account_suspensions', [
            'account_type',
            'account_id',
            'source',
            'reason',
            'suspended_by_super_admin_id',
            'ended_at',
            'ended_by_super_admin_id',
            'end_reason',
            'linked_employee_id',
            'linked_employee_prior_status',
        ]));

        self::assertTrue(Schema::hasColumns('users', ['current_suspension_id', 'deleted_at']));
        self::assertTrue(Schema::hasColumns('shop_owners', ['current_suspension_id', 'deleted_at']));
        self::assertTrue(Schema::hasColumns('employees', ['privileged_suspension_id']));
        self::assertTrue(Schema::hasColumns('suspension_appeals', ['suspension_id', 'reviewer_id']));

        self::assertTrue(Schema::hasTable('shop_report_moderation_actions'));
        self::assertTrue(Schema::hasColumns('shop_report_moderation_actions', [
            'shop_owner_id',
            'actor_id',
            'requested_action',
            'applied_action',
            'report_ids',
            'decision_key',
            'warning_strike_number',
            'source',
            'legacy_audit_log_id',
            'notes',
        ]));

        $suspensionIndexes = collect(Schema::getIndexes('account_suspensions'))
            ->pluck('columns')
            ->map(static fn (array $columns): string => implode(',', $columns));
        self::assertTrue($suspensionIndexes->contains('account_type,account_id,ended_at'));

        $actionIndexes = collect(Schema::getIndexes('shop_report_moderation_actions'))
            ->pluck('columns')
            ->map(static fn (array $columns): string => implode(',', $columns));
        self::assertTrue($actionIndexes->contains('shop_owner_id,warning_strike_number'));
    }

    public function test_phase_two_models_expose_safe_casts_and_relations(): void
    {
        self::assertContains(SoftDeletes::class, class_uses_recursive(User::class));
        self::assertContains(SoftDeletes::class, class_uses_recursive(ShopOwner::class));

        self::assertSame('array', (new ShopReportModerationAction)->getCasts()['report_ids']);
        self::assertSame('datetime', (new AccountSuspension)->getCasts()['ended_at']);
        self::assertSame('datetime', (new SuspensionAppeal)->getCasts()['expires_at']);

        self::assertInstanceOf(BelongsTo::class, (new User)->currentSuspension());
        self::assertInstanceOf(BelongsTo::class, (new ShopOwner)->currentSuspension());
        self::assertInstanceOf(BelongsTo::class, (new Employee)->privilegedSuspension());
        self::assertInstanceOf(BelongsTo::class, (new SuspensionAppeal)->suspension());
        self::assertInstanceOf(HasMany::class, (new ShopReport)->moderationActions());
        self::assertInstanceOf(BelongsTo::class, (new ReviewReport)->customer());
    }

    public function test_employee_provenance_is_not_request_mass_assignable(): void
    {
        $employee = new Employee();
        $employee->fill(['privileged_suspension_id' => 41]);

        self::assertNull($employee->getAttribute('privileged_suspension_id'));
        self::assertNotContains('privileged_suspension_id', $employee->getFillable());
    }

    public function test_appeal_and_moderation_models_allow_phase_two_domain_values(): void
    {
        $appeal = new SuspensionAppeal();
        $appeal->fill(['status' => 'superseded', 'suspension_id' => 41]);

        self::assertSame('superseded', $appeal->status);
        self::assertSame(41, $appeal->suspension_id);

        $action = new ShopReportModerationAction();
        $action->fill([
            'report_ids' => [12, 10, 11],
            'requested_action' => 'warned',
            'applied_action' => 'warned',
            'source' => 'runtime',
        ]);

        self::assertSame([12, 10, 11], $action->report_ids);
        self::assertSame('runtime', $action->source);
    }
}
