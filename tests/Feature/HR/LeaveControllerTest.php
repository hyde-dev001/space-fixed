<?php

namespace Tests\Feature\HR;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\HR\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class LeaveControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $shopOwner;
    protected $hrUser;
    protected $managerUser;
    protected $employee;
    protected $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopOwner = ShopOwner::factory()->create();

        $this->hrUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'HR',
        ]);

        $this->employee = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $this->manager = Employee::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $this->managerUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'manager',
        ]);

        // Create leave balance
        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'balance' => 15,
            'used' => 0,
            'shop_owner_id' => $this->shopOwner->id,
        ]);
    }

    #[Test]
    public function test_employee_can_apply_leave()
    {
        $leaveData = [
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'days' => 3,
            'reason' => 'Family vacation',
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/leave-requests', $leaveData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'employee_id', 'status']
            ]);

        $this->assertDatabaseHas('hr_leave_requests', [
            'employee_id' => $this->employee->id,
            'status' => 'pending',
            'days' => 3,
        ]);
    }

    #[Test]
    public function test_leave_balance_validated()
    {
        $leaveData = [
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(25)->format('Y-m-d'), // 21 days
            'days' => 21,
            'reason' => 'Extended vacation',
        ];

        // Employee only has 15 days available
        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/leave-requests', $leaveData);

        // Should fail validation or return error
        $response->assertStatus(422); // Validation error

        $this->assertDatabaseMissing('hr_leave_requests', [
            'employee_id' => $this->employee->id,
            'days' => 21,
        ]);
    }

    #[Test]
    public function test_manager_can_approve_team_leave()
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'status' => 'pending',
            'days' => 3,
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Leave request approved']);

        $this->assertDatabaseHas('hr_leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'approved',
            'approved_by' => $this->hrUser->id,
        ]);
    }

    #[Test]
    public function test_manager_cannot_approve_other_team_leave()
    {
        // Create another shop owner
        $otherShopOwner = ShopOwner::factory()->create();
        $otherEmployee = Employee::factory()->create([
            'shop_owner_id' => $otherShopOwner->id,
        ]);

        $leaveRequest = LeaveRequest::factory()->create([
            'employee_id' => $otherEmployee->id,
            'status' => 'pending',
            'shop_owner_id' => $otherShopOwner->id,
        ]);

        // Manager from different shop tries to approve
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve");

        $response->assertStatus(404); // Should not find leave request

        $this->assertDatabaseHas('hr_leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'pending', // Status unchanged
        ]);
    }

    #[Test]
    public function test_leave_deducted_from_balance_on_approval()
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'status' => 'pending',
            'days' => 5,
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $initialBalance = LeaveBalance::where('employee_id', $this->employee->id)
            ->where('leave_type', 'annual')
            ->first();

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve");

        $response->assertStatus(200);

        $updatedBalance = LeaveBalance::where('employee_id', $this->employee->id)
            ->where('leave_type', 'annual')
            ->first();

        $this->assertEquals(
            $initialBalance->balance - 5,
            $updatedBalance->balance
        );

        $this->assertEquals(
            $initialBalance->used + 5,
            $updatedBalance->used
        );
    }

    #[Test]
    public function test_can_reject_leave_request()
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'status' => 'pending',
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/reject", [
                'rejection_reason' => 'Insufficient staffing',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('hr_leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'rejected',
            'rejection_reason' => 'Insufficient staffing',
        ]);
    }

    #[Test]
    public function test_can_get_employee_leave_balance()
    {
        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->getJson("/api/hr/leave-requests/balance/{$this->employee->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => ['leave_type', 'balance', 'used']
            ]);
    }

    #[Test]
    public function test_cannot_apply_overlapping_leave()
    {
        // Create existing leave request
        LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(15),
            'status' => 'approved',
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        // Try to create overlapping leave
        $leaveData = [
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'start_date' => now()->addDays(12)->format('Y-m-d'),
            'end_date' => now()->addDays(17)->format('Y-m-d'),
            'days' => 6,
            'reason' => 'Another vacation',
        ];

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/hr/leave-requests', $leaveData);

        $response->assertStatus(422); // Validation error for overlap
    }

    #[Test]
    public function test_can_list_all_leave_requests()
    {
        LeaveRequest::factory()->count(5)->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->getJson('/api/hr/leave-requests');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }
}
