<?php

namespace Tests\Feature\HR;

use App\Models\HR\AttendanceRecord;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AttendanceLatenessPrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_self_check_in_uses_minute_precision_for_schedule_status(): void
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 0, 27, 37, 'Asia/Manila'));
        $shop = ShopOwner::factory()->create([
            'thursday_open' => '00:27:00',
            'thursday_close' => '23:59:00',
        ]);
        $user = User::factory()->for($shop)->create();

        $this->actingAs($user, 'user')
            ->post('/api/staff/attendance/check-in', [
                'photo' => $this->validCameraPhoto(),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'present')
            ->assertJsonPath('attendance.is_late', false)
            ->assertJsonPath('attendance.minutes_late', 0);
    }

    public function test_model_lateness_uses_minute_precision_at_the_boundary(): void
    {
        $onTime = new AttendanceRecord([
            'check_in_time' => Carbon::create(2026, 9, 3, 0, 27, 37, 'Asia/Manila'),
            'expected_check_in' => Carbon::create(2026, 9, 3, 0, 27, 0, 'Asia/Manila'),
        ]);
        $onTime->calculateLateness();

        $this->assertFalse($onTime->is_late);
        $this->assertSame(0, $onTime->minutes_late);

        $late = new AttendanceRecord([
            'check_in_time' => Carbon::create(2026, 9, 3, 0, 28, 0, 'Asia/Manila'),
            'expected_check_in' => Carbon::create(2026, 9, 3, 0, 27, 0, 'Asia/Manila'),
        ]);
        $late->calculateLateness();

        $this->assertTrue($late->is_late);
        $this->assertSame(1, $late->minutes_late);
    }

    public function test_grace_period_exempts_exact_boundary_and_is_snapshotted(): void
    {
        $shop = ShopOwner::factory()->create([
            'thursday_open' => '08:00:00',
            'thursday_close' => '17:00:00',
            'late_grace_period_minutes' => 15,
        ]);
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
        ]);

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:15:00',
            'expected_check_in' => '08:00:00',
            'expected_check_out' => '17:00:00',
            'status' => 'late',
        ]);

        $this->assertSame('present', $attendance->status);
        $this->assertFalse($attendance->is_late);
        $this->assertSame(0, $attendance->minutes_late);
        $this->assertSame(15, $attendance->late_grace_period_minutes);

        $shop->update(['late_grace_period_minutes' => 0]);
        $attendance->update(['check_out_time' => '17:00:00']);

        $this->assertSame(15, $attendance->fresh()->late_grace_period_minutes);
        $this->assertSame('present', $attendance->fresh()->status);
        $this->assertFalse($attendance->fresh()->is_late);
    }

    public function test_manual_hr_attendance_entry_uses_the_shop_grace_period(): void
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 12, 0, 0, 'Asia/Manila'));
        $shop = ShopOwner::factory()->create([
            'thursday_open' => '08:00:00',
            'thursday_close' => '17:00:00',
            'late_grace_period_minutes' => 15,
        ]);
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $shop->id]);
        $hr = User::factory()->for($shop)->create(['status' => 'active']);
        $hr->givePermissionTo(Permission::findOrCreate('access-attendance-records', 'user'));
        $hrEmployee = Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $hr->email,
        ]);
        AttendanceRecord::create([
            'employee_id' => $hrEmployee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:00:00',
            'expected_check_in' => '08:00:00',
            'expected_check_out' => '17:00:00',
            'status' => 'present',
        ]);

        $this->actingAs($hr, 'user')
            ->postJson('/api/hr/attendance', [
                'employee_id' => $employee->id,
                'date' => '2026-09-03',
                'check_in_time' => '08:15',
                'status' => 'late',
            ])
            ->assertCreated()
            ->assertJsonPath('attendance.status', 'present')
            ->assertJsonPath('attendance.is_late', false)
            ->assertJsonPath('attendance.minutes_late', 0)
            ->assertJsonPath('attendance.late_grace_period_minutes', 15);
    }

    public function test_check_in_one_minute_over_grace_keeps_full_lateness(): void
    {
        $shop = ShopOwner::factory()->create([
            'thursday_open' => '08:00:00',
            'thursday_close' => '17:00:00',
            'late_grace_period_minutes' => 15,
        ]);
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
        ]);

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:16:00',
            'expected_check_in' => '08:00:00',
            'expected_check_out' => '17:00:00',
            'status' => 'present',
        ]);

        $this->assertSame('late', $attendance->status);
        $this->assertTrue($attendance->is_late);
        $this->assertSame(16, $attendance->minutes_late);
        $this->assertSame(15, $attendance->late_grace_period_minutes);
    }

    public function test_zero_grace_keeps_any_lateness(): void
    {
        $shop = ShopOwner::factory()->create([
            'thursday_open' => '08:00:00',
            'thursday_close' => '17:00:00',
            'late_grace_period_minutes' => 0,
        ]);
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
        ]);

        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:01:00',
            'expected_check_in' => '08:00:00',
            'expected_check_out' => '17:00:00',
            'status' => 'present',
        ]);

        $this->assertSame('late', $attendance->status);
        $this->assertTrue($attendance->is_late);
        $this->assertSame(1, $attendance->minutes_late);
        $this->assertSame(0, $attendance->late_grace_period_minutes);
    }

    public function test_status_fallback_honors_the_saved_grace_snapshot(): void
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 12, 0, 0, 'Asia/Manila'));
        $shop = ShopOwner::factory()->create([
            'thursday_open' => '08:00:00',
            'thursday_close' => '17:00:00',
            'late_grace_period_minutes' => 0,
        ]);
        $user = User::factory()->for($shop)->create(['status' => 'active']);
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shop->id,
            'email' => $user->email,
        ]);
        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:15:00',
            'expected_check_in' => '08:00:00',
            'expected_check_out' => '17:00:00',
            'late_grace_period_minutes' => 15,
            'status' => 'present',
        ]);

        DB::table('attendance_records')->where('id', $attendance->id)->update([
            'minutes_late' => 15,
            'is_late' => true,
            'status' => 'late',
        ]);

        $this->actingAs($user, 'user')
            ->getJson('/api/staff/attendance/status')
            ->assertOk()
            ->assertJsonPath('status', 'present')
            ->assertJsonPath('is_late', false)
            ->assertJsonPath('minutes_late', 0);
    }

    private function validCameraPhoto(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'clock-in.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/ynsAAAAASUVORK5CYII='),
        );
    }
}
