<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\ShopOwner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceLunchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_employee_cannot_start_lunch_again_after_ending_the_lunch_break(): void
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 5, 12, 0, 0, 'Asia/Manila'));

        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
        ]);
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $user->email,
        ]);
        $attendanceId = DB::table('attendance_records')->insertGetId([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-05',
            'check_in_time' => '08:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attendance = AttendanceRecord::findOrFail($attendanceId);

        $this->actingAs($user, 'user')
            ->postJson('/api/staff/attendance/lunch-start')
            ->assertOk();

        $this->actingAs($user, 'user')
            ->postJson('/api/staff/attendance/lunch-end')
            ->assertOk();

        $this->actingAs($user, 'user')
            ->postJson('/api/staff/attendance/lunch-start')
            ->assertStatus(422)
            ->assertJson(['error' => 'Lunch break already ended']);

        $attendance->refresh();
        $this->assertNotNull($attendance->lunch_break_start);
        $this->assertNotNull($attendance->lunch_break_end);
    }
}
