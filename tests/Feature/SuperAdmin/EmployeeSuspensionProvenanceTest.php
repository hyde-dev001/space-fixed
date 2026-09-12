<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\AccountSuspension;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Repositories\HR\EmployeeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class EmployeeSuspensionProvenanceTest extends TestCase
{
    use BuildsPhaseTwoWorkflowFixtures;
    use RefreshDatabase;

    public function test_model_and_repository_employee_status_writers_clear_privileged_provenance(): void
    {
        $shop = $this->approvedPhaseTwoShop();
        $suspension = AccountSuspension::create([
            'account_type' => AccountSuspension::ACCOUNT_TYPE_CUSTOMER,
            'account_id' => 999,
            'source' => AccountSuspension::SOURCE_RUNTIME,
            'reason' => 'Provenance fixture',
        ]);
        $employee = Employee::factory()->suspended()->create([
            'shop_owner_id' => $shop->id,
            'privileged_suspension_id' => $suspension->id,
        ]);

        $employee->activate();
        $this->assertNull($employee->fresh()->privileged_suspension_id);

        $employee->forceFill([
            'status' => 'suspended',
            'privileged_suspension_id' => $suspension->id,
        ])->save();

        app(EmployeeRepository::class)->activate($employee->id, $shop->id);
        $this->assertNull($employee->fresh()->privileged_suspension_id);

        $employee->forceFill([
            'status' => 'active',
            'privileged_suspension_id' => $suspension->id,
        ])->save();

        app(EmployeeRepository::class)->suspend($employee->id, $shop->id);
        $this->assertNull($employee->fresh()->privileged_suspension_id);
    }

}
