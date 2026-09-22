<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeClockInAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_erp_employee_role_is_locked_before_clock_in(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 12, 0, 0, 'Asia/Manila'));

        foreach (['STAFF', 'MANAGER', 'CASHIER', 'REPAIRER', 'FINANCE', 'LOGISTICS'] as $role) {
            $shop = ShopOwner::factory()->create([
                'thursday_open' => '08:00:00',
                'thursday_close' => '20:00:00',
            ]);
            $user = User::factory()->for($shop)->create([
                'role' => $role,
                'status' => 'active',
            ]);

            $this->actingAs($user, 'user')
                ->postJson('/api/staff/attendance/lunch-start')
                ->assertStatus(423)
                ->assertJson([
                    'success' => false,
                    'code' => 'EMPLOYEE_NOT_CLOCKED_IN',
                    'message' => 'Please clock in on the Time In page before processing business actions.',
                ]);
        }
    }

    public function test_employee_mutation_is_allowed_with_an_active_attendance_record(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 12, 0, 0, 'Asia/Manila'));
        $shop = ShopOwner::factory()->create([
            'thursday_open' => '08:00:00',
            'thursday_close' => '20:00:00',
        ]);
        $user = User::factory()->for($shop)->create(['status' => 'active']);
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $user->email,
        ]);

        DB::table('attendance_records')->insert([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:00',
            'expected_check_in' => '08:00',
            'expected_check_out' => '20:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'user')
            ->postJson('/api/staff/attendance/lunch-start')
            ->assertOk();
    }

    public function test_customer_authentication_is_not_gated_by_employee_attendance(): void
    {
        $customer = User::factory()->create(['role' => 'CUSTOMER']);

        $this->actingAs($customer, 'user')
            ->postJson('/api/cart/clear')
            ->assertOk();
    }

    public function test_employee_can_logout_from_a_privileged_session_before_clocking_in(): void
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->for($shop)->create([
            'role' => 'MANAGER',
            'status' => 'active',
        ]);
        $admin = SuperAdmin::factory()->create();

        $this->actingAs($user, 'user')
            ->actingAs($admin, 'super_admin')
            ->post('/admin/logout')
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('super_admin');
    }

    public function test_check_in_accepts_the_final_configured_minute(): void
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 20, 0, 59, 'Asia/Manila'));
        $shop = ShopOwner::factory()->create([
            'thursday_open' => '19:00:00',
            'thursday_close' => '20:00:00',
        ]);
        $user = User::factory()->for($shop)->create(['status' => 'active']);

        $this->actingAs($user, 'user')
            ->postJson('/api/staff/attendance/check-in')
            ->assertOk();
    }

    public function test_self_check_in_recognizes_an_existing_eloquent_date_record(): void
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 12, 0, 0, 'Asia/Manila'));

        $shop = ShopOwner::factory()->create([
            'thursday_open' => '08:00:00',
            'thursday_close' => '20:00:00',
        ]);
        $user = User::factory()->for($shop)->create(['status' => 'active']);
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $user->email,
        ]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:00',
            'status' => 'present',
        ]);

        $this->actingAs($user, 'user')
            ->postJson('/api/staff/attendance/check-in')
            ->assertStatus(422)
            ->assertJsonPath('error', 'You have already checked in and have not clocked out yet');

        $this->assertSame(
            1,
            AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('date', '2026-09-03')
                ->count(),
        );
    }

    public function test_check_in_rejects_the_minute_after_configured_close(): void
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 20, 1, 0, 'Asia/Manila'));
        $shop = ShopOwner::factory()->create([
            'thursday_open' => '19:00:00',
            'thursday_close' => '20:00:00',
        ]);
        $user = User::factory()->for($shop)->create(['status' => 'active']);

        $this->actingAs($user, 'user')
            ->postJson('/api/staff/attendance/check-in')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Outside shop hours');
    }

    public function test_check_in_uses_the_current_weekday_schedule_and_reports_a_closed_day(): void
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 4, 10, 30, 0, 'Asia/Manila'));
        $shop = ShopOwner::factory()->create([
            'friday_open' => '10:00:00',
            'friday_close' => '18:00:00',
        ]);
        $user = User::factory()->for($shop)->create(['status' => 'active']);

        $this->actingAs($user, 'user')
            ->getJson('/api/staff/shop-hours/today')
            ->assertOk()
            ->assertJson([
                'day' => 'Friday',
                'is_open' => true,
                'open' => '10:00',
                'close' => '18:00',
            ]);

        Carbon::setTestNow(Carbon::create(2026, 9, 5, 10, 30, 0, 'Asia/Manila'));

        $this->actingAs($user, 'user')
            ->getJson('/api/staff/shop-hours/today')
            ->assertOk()
            ->assertJson([
                'day' => 'Saturday',
                'is_open' => false,
                'open' => null,
                'close' => null,
            ]);
    }
}
