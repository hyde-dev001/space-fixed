<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\ShopOwner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoClockOutEmployeesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_auto_clock_out_records_are_marked_with_the_reason(): void
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 18, 15, 0, 'Asia/Manila'));

        $shop = ShopOwner::factory()->create([
            'thursday_open' => '09:00:00',
            'thursday_close' => '18:00:00',
        ]);
        $employee = Employee::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
        ]);
        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '09:00',
            'status' => 'present',
        ]);

        $this->artisan('attendance:auto-clockout')->assertExitCode(0);

        $attendance->refresh();

        $this->assertSame('18:00', $attendance->check_out_time->format('H:i'));
        $this->assertTrue($attendance->auto_clocked_out);
        $this->assertSame('Auto clocked out at shop closing time', $attendance->auto_clockout_reason);
    }
}
