<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AttendanceClockInEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/ynsAAAAASUVORK5CYII=';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_self_check_in_requires_a_photo_before_creating_employee_or_attendance(): void
    {
        [$shop, $user] = $this->makeClockInAccount();

        $this->actingAs($user, 'user')
            ->postJson('/api/staff/attendance/check-in', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);

        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseMissing('employees', [
            'shop_owner_id' => $shop->id,
            'email' => $user->email,
        ]);
    }

    public function test_shop_can_disable_clock_in_photos_and_staff_check_in_without_storing_one(): void
    {
        Storage::fake('local');
        [$shop, $user] = $this->makeClockInAccount(['attendance_photo_required' => false]);

        $this->actingAs($user, 'user')
            ->getJson('/api/staff/shop-hours/today')
            ->assertOk()
            ->assertJsonPath('attendance_photo_required', false);

        $this->actingAs($user, 'user')
            ->postJson('/api/staff/attendance/check-in', [])
            ->assertOk()
            ->assertJsonPath('message', 'Checked in successfully');

        $employee = Employee::where('shop_owner_id', $shop->id)
            ->where('email', $user->email)
            ->firstOrFail();
        $attendance = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();

        $this->assertNull($attendance->check_in_photo_path);
        $this->assertNull($attendance->check_in_photo_captured_at);
        $this->assertSame([], Storage::disk('local')->allFiles('attendance'));
    }

    public function test_self_check_in_stores_private_photo_without_prompt_preference(): void
    {
        Storage::fake('local');
        [$shop, $user] = $this->makeClockInAccount(['late_grace_period_minutes' => 15]);

        $this->actingAs($user, 'user')
            ->post('/api/staff/attendance/check-in', [
                'photo' => $this->validCameraPhoto(),
                'dismiss_camera_prompt' => true,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'present')
            ->assertJsonPath('attendance.is_late', false)
            ->assertJsonPath('attendance.minutes_late', 0)
            ->assertJsonMissingPath('attendance.check_in_photo_path');

        $employee = Employee::where('shop_owner_id', $shop->id)
            ->where('email', $user->email)
            ->firstOrFail();
        $attendance = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();

        $this->assertNotEmpty($attendance->check_in_photo_path);
        $this->assertStringStartsWith("attendance/{$shop->id}/{$employee->id}/", $attendance->check_in_photo_path);
        $this->assertNotNull($attendance->check_in_photo_captured_at);
        $this->assertSame(15, $attendance->late_grace_period_minutes);
        $this->assertTrue(Storage::disk('local')->exists($attendance->check_in_photo_path));
        $this->assertArrayNotHasKey('check_in_photo_path', $attendance->toArray());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'attendance_camera_prompt_dismissed' => false,
        ]);

        $status = $this->actingAs($user, 'user')
            ->getJson('/api/staff/attendance/status')
            ->assertOk();
        $this->assertArrayNotHasKey('attendance_camera_prompt_dismissed', $status->json());
    }

    public function test_multipart_check_in_keeps_geofence_coordinates(): void
    {
        [$shop, $user] = $this->makeClockInAccount([
            'attendance_geofence_enabled' => true,
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
            'shop_geofence_radius' => 100,
        ]);

        $this->actingAs($user, 'user')
            ->post('/api/staff/attendance/check-in', [
                'photo' => $this->validCameraPhoto(),
                'latitude' => 14.5995,
                'longitude' => 120.9842,
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $attendance = AttendanceRecord::where('shop_owner_id', $shop->id)->firstOrFail();
        $this->assertSame(14.5995, (float) $attendance->check_in_latitude);
        $this->assertSame(120.9842, (float) $attendance->check_in_longitude);
    }

    public function test_invalid_and_oversized_photos_are_rejected_without_attendance_changes(): void
    {
        [, $user] = $this->makeClockInAccount();

        foreach ([
            UploadedFile::fake()->create('clock-in.txt', 1, 'text/plain'),
            UploadedFile::fake()->create('clock-in.jpg', 5121, 'image/jpeg'),
        ] as $photo) {
            $this->actingAs($user, 'user')
                ->post('/api/staff/attendance/check-in', ['photo' => $photo], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['photo']);
        }

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_private_photo_requires_same_shop_attendance_access(): void
    {
        Storage::fake('local');
        $shop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $shop->id]);
        $photoPath = $this->validCameraPhoto()->store(
            "attendance/{$shop->id}/{$employee->id}/2026-09-03",
            'local',
        );
        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:00:00',
            'expected_check_in' => '08:00:00',
            'check_in_photo_path' => $photoPath,
            'check_in_photo_captured_at' => now(),
            'status' => 'present',
        ]);

        $accessPermission = Permission::findOrCreate('access-attendance-records', 'user');
        $otherPermission = Permission::findOrCreate('access-employee-directory', 'user');
        $sameShopHr = User::factory()->for($shop)->create(['status' => 'active']);
        $sameShopHr->givePermissionTo($accessPermission);
        $sameShopWithoutAttendanceAccess = User::factory()->for($shop)->create(['status' => 'active']);
        $sameShopWithoutAttendanceAccess->givePermissionTo($otherPermission);
        $otherShopHr = User::factory()->for($otherShop)->create(['status' => 'active']);
        $otherShopHr->givePermissionTo($accessPermission);

        $this->actingAs($sameShopHr, 'user')
            ->getJson('/api/hr/attendance')
            ->assertOk()
            ->assertJsonPath('data.0.has_check_in_photo', true)
            ->assertJsonMissingPath('data.0.check_in_photo_path');

        $this->actingAs($sameShopHr, 'user')
            ->get("/api/hr/attendance/{$attendance->id}/check-in-photo")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($sameShopWithoutAttendanceAccess, 'user')
            ->getJson("/api/hr/attendance/{$attendance->id}/check-in-photo")
            ->assertForbidden();

        $this->actingAs($otherShopHr, 'user')
            ->getJson("/api/hr/attendance/{$attendance->id}/check-in-photo")
            ->assertNotFound();
    }

    public function test_shop_owner_can_view_only_their_own_private_attendance_photos(): void
    {
        Storage::fake('local');
        config(['shop_modules.enforcement_enabled' => true]);

        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $otherShop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach ([$shop, $otherShop] as $owner) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => 'hr_employees',
                'enabled' => true,
            ]);
        }

        $employee = Employee::factory()->active()->create(['shop_owner_id' => $shop->id]);
        $photoPath = $this->validCameraPhoto()->store(
            "attendance/{$shop->id}/{$employee->id}/2026-09-03",
            'local',
        );
        $attendance = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:00:00',
            'expected_check_in' => '08:00:00',
            'check_in_photo_path' => $photoPath,
            'check_in_photo_captured_at' => now(),
            'status' => 'present',
        ]);

        $ownerPhotoUrl = "/api/shop-owner/erp/hr/attendance/{$attendance->id}/check-in-photo";

        $this->actingAs($shop, 'shop_owner')
            ->get($ownerPhotoUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($otherShop, 'shop_owner')
            ->getJson($ownerPhotoUrl)
            ->assertNotFound();

        $this->actingAs($shop, 'shop_owner')
            ->getJson("/api/hr/attendance/{$attendance->id}/check-in-photo")
            ->assertForbidden()
            ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');
    }

    private function makeClockInAccount(array $shopAttributes = []): array
    {
        config(['app.shop_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::create(2026, 9, 3, 8, 15, 0, 'Asia/Manila'));

        $shop = ShopOwner::factory()->create(array_merge([
            'thursday_open' => '08:00:00',
            'thursday_close' => '17:00:00',
        ], $shopAttributes));
        $user = User::factory()->for($shop)->create(['status' => 'active']);

        return [$shop, $user];
    }

    private function validCameraPhoto(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'clock-in.png',
            base64_decode(self::VALID_PNG),
        );
    }
}
