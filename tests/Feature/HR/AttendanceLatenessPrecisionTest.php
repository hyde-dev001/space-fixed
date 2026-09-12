<?php

namespace Tests\Feature\HR;

use App\Models\HR\AttendanceRecord;
use App\Models\ShopOwner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->postJson('/api/staff/attendance/check-in')
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
}
