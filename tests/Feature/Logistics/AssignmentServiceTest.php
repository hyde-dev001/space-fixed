<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShippingMethod;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_rider_can_only_be_assigned_for_individual_shop(): void
    {
        $company = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $company->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $company->id,
            'rider_type' => 'shop_owner',
        ]);

        $this->expectException(ValidationException::class);

        app(AssignmentService::class)->assignInternalRider($leg, $rider, $company);
    }

    public function test_employee_rider_can_be_assigned_for_company_shop(): void
    {
        $company = ShopOwner::factory()->create(['registration_type' => 'company']);
        $user = User::factory()->create(['shop_owner_id' => $company->id]);
        Employee::factory()->active()->create([
            'shop_owner_id' => $company->id,
            'email' => $user->email,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $company->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $company->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $user->id,
        ]);

        $assignment = app(AssignmentService::class)->assignInternalRider($leg, $rider, $company);

        $this->assertSame('assigned', $assignment->status);
        $this->assertSame('assigned', $leg->fresh()->status->value);
    }

    public function test_unlinked_employee_rider_cannot_be_assigned(): void
    {
        $company = ShopOwner::factory()->create(['registration_type' => 'company']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $company->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $rider = RiderProfile::query()->create([
            'shop_owner_id' => $company->id,
            'rider_type' => 'employee',
            'name' => 'Unlinked employee',
            'availability_status' => 'available',
            'active' => true,
        ]);

        try {
            app(AssignmentService::class)->assignInternalRider($leg, $rider, $company);
            $this->fail('An unlinked employee rider was assigned work.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rider_profile_id', $exception->errors());
        }

        $this->assertDatabaseCount('delivery_assignments', 0);
        $this->assertSame('pending', $leg->fresh()->status->value);
    }

    #[DataProvider('blockedEmployeeStatuses')]
    public function test_employee_rider_cannot_receive_new_assignment_when_account_state_is_blocked(string $status): void
    {
        $company = ShopOwner::factory()->create(['registration_type' => 'company']);
        $user = User::factory()->create(['shop_owner_id' => $company->id]);
        Employee::factory()->create([
            'shop_owner_id' => $company->id,
            'email' => $user->email,
            'status' => $status,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $company->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $company->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $user->id,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(AssignmentService::class)->assignInternalRider($leg, $rider, $company);
        } finally {
            $this->assertDatabaseCount('delivery_assignments', 0);
            $this->assertSame('pending', $leg->fresh()->status->value);
        }
    }

    public static function blockedEmployeeStatuses(): array
    {
        return [
            'inactive' => ['inactive'],
            'suspended' => ['suspended'],
            'terminated' => ['terminated'],
        ];
    }

    public function test_status_change_does_not_delete_existing_assignment_history(): void
    {
        $company = ShopOwner::factory()->create(['registration_type' => 'company']);
        $user = User::factory()->create(['shop_owner_id' => $company->id]);
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $company->id,
            'email' => $user->email,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $company->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $company->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $user->id,
        ]);

        $assignment = app(AssignmentService::class)->assignInternalRider($leg, $rider, $company);

        $employee->update(['status' => 'inactive']);

        $this->assertDatabaseHas('delivery_assignments', [
            'id' => $assignment->id,
            'rider_profile_id' => $rider->id,
            'status' => 'assigned',
        ]);
    }

    #[DataProvider('unsupportedInternalAssignmentMethods')]
    public function test_assignment_service_rejects_unsupported_internal_methods(array $attributes): void
    {
        $company = ShopOwner::factory()->create(['registration_type' => 'company']);
        $user = User::factory()->create(['shop_owner_id' => $company->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $company->id]);
        $method = ShippingMethod::factory()->create($attributes);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'shipping_method_id' => $method->id,
        ]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $company->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $user->id,
        ]);

        try {
            app(AssignmentService::class)->assignInternalRider($leg, $rider, $company);
            $this->fail('Unsupported shipping method was accepted for rider assignment.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Selected shipping method is not supported by shop-owned logistics.',
                $exception->errors()['shipping_method_id'][0] ?? null,
            );
        }

        $this->assertDatabaseCount('delivery_assignments', 0);
        $this->assertSame('pending', $leg->fresh()->status->value);
    }

    public static function unsupportedInternalAssignmentMethods(): array
    {
        return [
            'inactive method' => [['carrier_type' => 'internal', 'requires_assignment' => true, 'active' => false]],
            'assignment free method' => [['carrier_type' => 'internal', 'requires_assignment' => false, 'active' => true]],
            'external method' => [['carrier_type' => 'external', 'requires_assignment' => false, 'active' => true]],
            'customer controlled method' => [['carrier_type' => 'customer_controlled', 'requires_assignment' => false, 'active' => true]],
        ];
    }
}
