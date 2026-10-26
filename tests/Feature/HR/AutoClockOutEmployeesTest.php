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

    public function test_auto_clock_out_saves_actual_recorded_lunch_hours_without_an_extra_flat_deduction(): void
    {
        $record = $this->attendance('2026-10-25', '09:00', '18:00', ['lunch_break_start' => '12:00', 'lunch_break_end' => '12:30']);
        Carbon::setTestNow(Carbon::parse('2026-10-25 18:15', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame('8.50', $record->fresh()->working_hours);
        $this->assertSame('18:00', $record->fresh()->check_out_time->format('H:i'));
    }

    public function test_missed_runs_close_prior_days_using_the_saved_schedule_and_are_idempotent(): void
    {
        $record = $this->attendance('2026-10-25', '16:30', '21:00');
        $record->shopOwner->update(['sunday_close' => '17:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-26 01:00', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame('21:00', $record->fresh()->check_out_time?->format('H:i'));
        $this->assertSame('4.50', $record->fresh()->working_hours);
        $saved = $record->fresh()->getRawOriginal();
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame($saved, $record->fresh()->getRawOriginal());
    }

    public function test_approved_overtime_waits_for_grace_and_records_regular_and_overtime_separately(): void
    {
        $record = $this->attendance('2026-10-25', '09:00', '18:00', ['lunch_break_start' => '12:00', 'lunch_break_end' => '13:00']);
        $ot = $this->overtime($record, '18:00', '20:00', 2);
        Carbon::setTestNow(Carbon::parse('2026-10-25 20:20', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertNull($record->fresh()->check_out_time);
        Carbon::setTestNow(Carbon::parse('2026-10-25 20:31', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame('8.00', $record->fresh()->working_hours);
        $this->assertSame('2.00', $record->fresh()->overtime_hours);
        $this->assertSame('2.00', $ot->fresh()->actual_hours);
        $this->assertSame('20:00', $record->fresh()->check_out_time->format('H:i'));
    }

    public function test_overtime_grace_crossing_midnight_does_not_clock_out_early(): void
    {
        $record = $this->attendance('2026-10-25', '20:00', '23:00');
        $this->overtime($record, '23:00', '23:45', 0.75);
        Carbon::setTestNow(Carbon::parse('2026-10-25 23:50', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertNull($record->fresh()->check_out_time);
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:16', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame('3.00', $record->fresh()->working_hours);
        $this->assertSame('0.75', $record->fresh()->overtime_hours);
    }

    public function test_unconfirmed_historical_schedule_and_check_in_after_close_are_left_for_review(): void
    {
        $old = $this->attendance('2026-10-24', '09:00', '18:00');
        \Illuminate\Support\Facades\DB::table('attendance_records')->where('id', $old->id)->update(['expected_check_out' => null]);
        $late = $this->attendance('2026-10-25', '19:00', '18:00');
        Carbon::setTestNow(Carbon::parse('2026-10-25 20:00', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertNull($old->fresh()->check_out_time);
        $this->assertNull($late->fresh()->check_out_time);
    }

    public function test_later_note_edits_preserve_the_finalized_auto_clock_out_hours(): void
    {
        $record = $this->attendance('2026-10-25', '09:00', '18:00', ['lunch_break_start' => '12:00', 'lunch_break_end' => '12:30']);
        Carbon::setTestNow(Carbon::parse('2026-10-25 18:15', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $record->refresh()->update(['notes' => 'HR reviewed the automatic checkout.']);
        $this->assertSame('8.50', $record->fresh()->working_hours);
    }

    public function test_overnight_shift_waits_until_its_next_day_closing_time(): void
    {
        $record = $this->attendance('2026-10-25', '22:00', '06:00', ['expected_check_in' => '22:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-25 23:00', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertNull($record->fresh()->check_out_time);
        Carbon::setTestNow(Carbon::parse('2026-10-26 06:15', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame('06:00', $record->fresh()->check_out_time?->format('H:i'));
        $this->assertSame('8.00', $record->fresh()->working_hours);
    }

    public function test_completed_future_and_other_shop_not_yet_due_records_remain_untouched(): void
    {
        $closed = $this->attendance('2026-10-25', '09:00', '18:00', ['check_out_time' => '17:00', 'working_hours' => 7]);
        $later = $this->attendance('2026-10-25', '09:00', '22:00');
        $future = $this->attendance('2026-10-26', '09:00', '18:00');
        Carbon::setTestNow(Carbon::parse('2026-10-25 20:00', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame('17:00', $closed->fresh()->check_out_time->format('H:i'));
        $this->assertSame('7.00', $closed->fresh()->working_hours);
        $this->assertNull($later->fresh()->check_out_time);
        $this->assertNull($future->fresh()->check_out_time);
    }

    public function test_recorded_lunch_covering_the_entire_visit_remains_zero_without_phantom_hours(): void
    {
        $record = $this->attendance('2026-10-25', '17:00', '18:00', ['lunch_break_start' => '17:00', 'lunch_break_end' => '18:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-25 18:15', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame('0.00', $record->fresh()->working_hours);
    }

    public function test_auto_clock_out_is_registered_with_overlap_protection(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'attendance:auto-clockout'));
        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_paid_overtime_is_not_rewritten_by_an_automatic_historical_repair(): void
    {
        $record = $this->attendance('2026-10-25', '09:00', '18:00');
        $ot = $this->overtime($record, '18:00', '20:00', 2);
        $ot->update(['is_paid' => true, 'actual_hours' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-10-26 01:00', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertNull($record->fresh()->check_out_time);
        $this->assertSame('1.00', $ot->fresh()->actual_hours);
    }

    public function test_overtime_is_capped_and_does_not_start_before_the_approved_period(): void
    {
        $record = $this->attendance('2026-10-25', '09:00', '18:00');
        $ot = $this->overtime($record, '19:00', '20:00', 1);
        Carbon::setTestNow(Carbon::parse('2026-10-25 20:31', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame('9.00', $record->fresh()->working_hours);
        $this->assertSame('1.00', $record->fresh()->overtime_hours);
        $this->assertSame('19:00:00', $ot->fresh()->actual_start_time);
    }

    public function test_owner_attendance_page_receives_the_finalized_checkout_and_hours_only_for_their_shop(): void
    {
        $record = $this->attendance('2026-10-25', '16:30', '21:00');
        $foreign = $this->attendance('2026-10-25', '09:00', '18:00');
        $record->shopOwner->update(['registration_type' => 'company']);
        Carbon::setTestNow(Carbon::parse('2026-10-26 01:00', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->actingAs($record->shopOwner->fresh(), 'shop_owner')->get('/shop-owner/erp/hr/attendance')->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('ERP/HR/HR')
                ->has('initialAttendance.data', 1)->where('initialAttendance.data.0.id', $record->id)
                ->where('initialAttendance.data.0.check_out_time', '21:00')->where('initialAttendance.data.0.working_hours', '4.50'));
    }

    public function test_manual_checkout_keeps_its_recorded_lunch_calculation(): void
    {
        $record = $this->attendance('2026-10-25', '09:00', '18:00', ['lunch_break_start' => '12:00', 'lunch_break_end' => '12:30']);
        $user = \App\Models\User::factory()->create(['shop_owner_id' => $record->shop_owner_id, 'email' => $record->employee->email, 'status' => 'active']);
        Carbon::setTestNow(Carbon::parse('2026-10-25 13:00', 'Asia/Manila'));
        $this->actingAs($user, 'user')->postJson('/api/staff/attendance/check-out')->assertOk();
        $this->assertSame('3.50', $record->fresh()->working_hours);
        $this->assertTrue($record->fresh()->is_early_departure);
        $this->assertSame(300, $record->fresh()->minutes_early_departure);
    }

    public function test_approved_overtime_ending_next_day_is_not_an_early_departure(): void
    {
        $record = $this->attendance('2026-10-25', '18:00', '23:00');
        $this->overtime($record, '23:00', '00:30', 1.5);
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:45', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertNull($record->fresh()->check_out_time);
        Carbon::setTestNow(Carbon::parse('2026-10-26 01:01', 'Asia/Manila'));
        $this->artisan('attendance:auto-clockout')->assertSuccessful();
        $this->assertSame('5.00', $record->fresh()->working_hours);
        $this->assertSame('1.50', $record->fresh()->overtime_hours);
        $this->assertFalse($record->fresh()->is_early_departure);
        $this->assertSame(0, $record->fresh()->minutes_early_departure);
    }

    private function attendance(string $date, string $in, string $out, array $extra = []): AttendanceRecord
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse($date.' 12:00', 'Asia/Manila'));
        $day = strtolower(Carbon::parse($date)->format('l'));
        $shop = ShopOwner::factory()->create([$day.'_open' => '09:00', $day.'_close' => $out]);
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $shop->id]);

        return AttendanceRecord::create(['shop_owner_id' => $shop->id, 'employee_id' => $employee->id, 'date' => $date,
            'check_in_time' => $in, 'expected_check_in' => '09:00', 'expected_check_out' => $out, 'status' => 'present', ...$extra]);
    }

    private function overtime(AttendanceRecord $record, string $start, string $end, float $hours): \App\Models\HR\OvertimeRequest
    {
        return \App\Models\HR\OvertimeRequest::create(['shop_owner_id' => $record->shop_owner_id, 'employee_id' => $record->employee_id,
            'overtime_date' => $record->date->toDateString(), 'start_time' => $start, 'end_time' => $end, 'hours' => $hours,
            'reason' => 'Synthetic approved shift extension', 'status' => 'approved']);
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
