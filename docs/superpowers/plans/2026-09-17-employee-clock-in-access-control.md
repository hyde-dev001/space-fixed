# Employee Clock-In Access Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce read-only ERP access for employees without an active attendance record and make clock-in eligibility follow the shop owner's live weekday hours through the configured closing minute.

**Architecture:** A single global middleware will guard mutating requests for `User::isEmployeeAccount()` users while leaving safe reads, clock-in, and account-security routes available. Inertia will share the current attendance state for the ERP shell notice, and the existing Time In page/controller will use minute-normalized comparisons plus focus/poll refreshes for dynamic shop hours.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit/Laravel feature tests, Inertia 2, React 18, TypeScript 5.7, Vitest, Testing Library, Vite 7, Tailwind CSS 4.

## Global Constraints

- This applies to employee `User` accounts identified by a non-null `shop_owner_id`, including Staff, Manager, Cashier, Repairer, Finance, and Logistics accounts.
- It does not apply to customer accounts or the shop-owner guard/portal.
- Safe read methods (`GET`, `HEAD`, and `OPTIONS`) remain available to employees.
- An active attendance record has `check_in_time` and no `check_out_time`.
- Clock-in remains available before an active record exists.
- Logout, password, and MFA/security maintenance remain available so employees cannot be trapped in an account workflow.
- Shop-hours settings are read from the authenticated shop owner's weekday fields (`monday_open` through `sunday_close`), so the attendance page never assumes a fixed schedule.
- Both backend validation and the Time In page will compare normalized minutes rather than raw seconds.
- For a configured close of `11:45 PM`, clock-in is allowed through `11:45:59 PM` and rejected from `11:46:00 PM`.
- The existing 30-minute early check-in window and overnight schedule behavior remain unchanged.
- No migration or dependency is needed.
- Preserve all unrelated working-tree changes; do not modify generated `public/build`, `.pnpm-store`, `.tmp`, or unrelated repair/shop-owner files.

---

### Task 1: Add failing backend access-control and clock-window tests

**Files:**
- Create: `tests/Feature/HR/EmployeeClockInAccessTest.php`
- Read-only references: `app/Http/Controllers/Erp/HR/AttendanceController.php`, `app/Models/User.php`, `app/Models/Employee.php`, `app/Models/HR/AttendanceRecord.php`, `routes/hr-api.php`, `routes/web.php`

**Interfaces:**
- Consumes: `User::isEmployeeAccount()`, `AttendanceRecord` attendance fields, `/api/staff/attendance/check-in`, `/api/staff/attendance/lunch-start`, `/api/staff/shop-hours/today`, and `/api/cart/clear`.
- Produces: failing feature coverage for the middleware contract and minute-precision schedule behavior.

- [x] **Step 1: Write the failing feature test**

Create the test with this complete setup and assertions:

```php
<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\ShopOwner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'date' => '2026-09-03',
            'check_in_time' => '08:00',
            'expected_check_in' => '08:00',
            'expected_check_out' => '20:00',
            'status' => 'present',
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
```

- [x] **Step 2: Run the backend test to verify it fails for the missing lock and closing boundary**

Run: `php artisan test tests/Feature/HR/EmployeeClockInAccessTest.php`

Expected: the new tests fail because the application currently reaches `selfLunchStart` before returning the required `423 EMPLOYEE_NOT_CLOCKED_IN`, and the `20:00:59` check-in is rejected by the controller's raw-second comparison. Existing unrelated failures, if any, must be recorded separately.

### Task 2: Add failing frontend tests for live shop hours, layout notice, and minute precision

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx`
- Modify: `resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx`

**Interfaces:**
- Consumes: `TimeIn`'s exported `isClockInAllowedAtTime`, the existing `usePage` test state, and the ERP layout.
- Produces: frontend regression coverage for the final closing minute, focus refresh, and employee read-only notice.

- [x] **Step 1: Add the failing Time In assertions**

Append these tests to `resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx`:

```tsx
it('allows the configured closing minute through second 59', () => {
    const shopHours = { open: '10:00', close: '20:00', is_open: true };

    expect(isClockInAllowedAtTime(new Date(2026, 8, 7, 20, 0, 59), shopHours)).toBe(true);
    expect(isClockInAllowedAtTime(new Date(2026, 8, 7, 20, 1, 0), shopHours)).toBe(false);
});

it('refreshes shop hours when the attendance tab regains focus', async () => {
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));
    const initialShopHoursCalls = fetchMock.mock.calls.filter(
        ([url]) => url === '/api/staff/shop-hours/today',
    ).length;

    fireEvent(window, new Event('focus'));

    await waitFor(() => {
        expect(
            fetchMock.mock.calls.filter(([url]) => url === '/api/staff/shop-hours/today'),
        ).toHaveLength(initialShopHoursCalls + 1);
    });
});
```

- [x] **Step 2: Add the failing ERP layout notice assertions**

In `resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx`, add `employeeAttendance` to the hoisted state and include it in the mocked `usePage().props`. Reset it in `beforeEach` and `afterEach`, then add these tests:

```tsx
it('shows the employee read-only notice until the employee clocks in', () => {
  state.auth = {
    erpActor: { type: 'employee', ownerMode: false },
    user: { shop_owner_id: 7 },
  };
  state.url = '/erp/staff/dashboard';
  state.employeeAttendance = { is_employee: true, is_clocked_in: false };

  render(<AppLayoutERP><div>employee page</div></AppLayoutERP>);

  expect(screen.getByRole('status')).toHaveTextContent('Read-only mode');
  expect(screen.getByRole('link', { name: 'Go to Time In' })).toHaveAttribute('href', '/erp/time-in');
});

it('hides the employee read-only notice on Time In and after clock-in', () => {
  state.auth = {
    erpActor: { type: 'employee', ownerMode: false },
    user: { shop_owner_id: 7 },
  };
  state.employeeAttendance = { is_employee: true, is_clocked_in: true };

  render(<AppLayoutERP><div>employee page</div></AppLayoutERP>);
  expect(screen.queryByRole('status')).not.toBeInTheDocument();
});
```

- [x] **Step 3: Run the focused frontend tests to verify the new assertions fail**

Run: `pnpm run test:frontend -- resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx`

Expected: the new final-minute, focus-refresh, and read-only-notice assertions fail while the pre-existing tests remain attributable by test name.

### Task 3: Implement the server-side employee mutation guard

**Files:**
- Create: `app/Http/Middleware/EnsureEmployeeClockedIn.php`
- Modify: `bootstrap/app.php:142-224`

**Interfaces:**
- Consumes: authenticated `user` guard, `User::isEmployeeAccount()`, employee email/shop ownership, `AttendanceRecord`, and the configured `app.shop_timezone`.
- Produces: global middleware behavior returning HTTP `423` with `code: EMPLOYEE_NOT_CLOCKED_IN` for employee mutations without an active current-day record.

- [x] **Step 1: Implement the middleware with the exact route exceptions**

Create `EnsureEmployeeClockedIn` with the following behavior:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEmployeeClockedIn
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $user = Auth::guard('user')->user();

        if (! $user instanceof User
            || ! $user->isEmployeeAccount()
            || $request->routeIs(
                'user.logout',
                'staff.attendance.checkin',
                'erp.password.update',
                'erp.security.sessions.logout-others',
                'erp.security.totp.*',
                'erp.mfa.challenge.verify',
            )) {
            return $next($request);
        }

        $timezone = config('app.shop_timezone', 'Asia/Manila');
        $today = now($timezone)->toDateString();
        $employee = Employee::query()
            ->where('shop_owner_id', $user->shop_owner_id)
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->first();

        $hasActiveAttendance = $employee !== null
            && AttendanceRecord::query()
                ->where('shop_owner_id', $user->shop_owner_id)
                ->where('employee_id', $employee->getKey())
                ->where('date', $today)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->exists();

        if ($hasActiveAttendance) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'code' => 'EMPLOYEE_NOT_CLOCKED_IN',
            'message' => 'Please clock in on the Time In page before processing business actions.',
        ], Response::HTTP_LOCKED);
    }
}
```

- [x] **Step 2: Register it in both global stacks**

Append `\App\Http\Middleware\EnsureEmployeeClockedIn::class` to the existing `web` and `api` middleware arrays in `bootstrap/app.php`, after the existing employee/security checks. Do not add it to the `shop_owner` guard or customer-only route definitions.

- [x] **Step 3: Run the backend access tests**

Run: `php artisan test tests/Feature/HR/EmployeeClockInAccessTest.php`

Expected: the pre-clock-in employee lock, active attendance pass-through, customer boundary, weekday response, and closed-day assertions pass; only the closing-minute controller test remains red until Task 5.

### Task 4: Share attendance state and render the ERP read-only notice

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/js/layout/AppLayout_ERP.tsx`
- Modify: `resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx`

**Interfaces:**
- Consumes: authenticated employee `User`, `Employee` email/shop relation, current shop timezone, and active `AttendanceRecord` query.
- Produces: Inertia prop `employeeAttendance: { is_employee: boolean, is_clocked_in: boolean, date: string } | null` and an accessible ERP notice with a `/erp/time-in` link.

- [x] **Step 1: Add the server-shared employee attendance prop**

Import `Carbon` and `App\Models\Employee` in `HandleInertiaRequests.php`. Before the returned prop array, compute only for `User::isEmployeeAccount()` users:

```php
$employeeAttendance = null;

if ($user instanceof User && $user->isEmployeeAccount()) {
    $timezone = config('app.shop_timezone', 'Asia/Manila');
    $today = Carbon::now($timezone)->toDateString();
    $employee = Employee::query()
        ->where('shop_owner_id', $user->shop_owner_id)
        ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
        ->first();

    $employeeAttendance = [
        'is_employee' => true,
        'is_clocked_in' => $employee !== null && $employee->attendanceRecords()
            ->where('shop_owner_id', $user->shop_owner_id)
            ->where('date', $today)
            ->whereNotNull('check_in_time')
            ->whereNull('check_out_time')
            ->exists(),
        'date' => $today,
    ];
}
```

Add `'employeeAttendance' => $employeeAttendance` to the root shared props. Customer and shop-owner requests must receive `null`.

- [x] **Step 2: Add the notice to the existing ERP shell**

In `AppLayout_ERP.tsx`, read `auth.user.shop_owner_id`, `employeeAttendance`, and `page.url` from the existing `usePage()` result. Render a monochrome `role="status"` banner in the existing content wrapper only when the current user is an employee, `employeeAttendance.is_clocked_in !== true`, and the path is neither `/erp/time-in` nor `/erp/staff/attendance`:

```tsx
{showEmployeeReadOnlyNotice && (
  <div role="status" className="mb-4 flex flex-col gap-2 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 sm:flex-row sm:items-center sm:justify-between">
    <span>Read-only mode. Clock in before processing business actions.</span>
    <a className="font-semibold text-gray-900 underline underline-offset-4 dark:text-white" href="/erp/time-in">
      Go to Time In
    </a>
  </div>
)}
```

Keep the existing owner-shell decision and all existing sidebar/header content unchanged.

- [x] **Step 3: Run the layout tests**

Run: `pnpm run test:frontend -- resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx`

Expected: the employee notice is visible on operational employee pages, hidden on Time In/active attendance, and never appears in owner mode.

### Task 5: Normalize backend clock-in comparisons to minutes

**Files:**
- Modify: `app/Http/Controllers/Erp/HR/AttendanceController.php:520-590`
- Test: `tests/Feature/HR/EmployeeClockInAccessTest.php`

**Interfaces:**
- Consumes: dynamic weekday fields already read from the authenticated `ShopOwner`, including overnight close adjustment.
- Produces: clock-in validation where a close of `HH:MM` accepts every second in that minute and rejects the next minute.

- [x] **Step 1: Replace only the raw-second validation comparisons**

After the existing `$shopCloseTime` overnight adjustment, derive minute-normalized values and use them for the early/late window checks:

```php
$clockInMinute = $now->copy()->startOfMinute();
$shopOpenMinute = $shopOpenTime->copy()->startOfMinute();
$shopCloseMinute = $shopCloseTime->copy()->startOfMinute();
$earliestAllowedMinute = $shopOpenMinute->copy()->subMinutes(30);
```

Use `$clockInMinute` versus `$earliestAllowedMinute` for the too-early check, `$clockInMinute` versus `$shopCloseMinute` for the outside-hours check, and the same normalized values for the early-status branch. Keep `$now` for the stored `H:i` clock-in timestamp and preserve the existing overnight and second-shift branches.

- [x] **Step 2: Run the focused backend tests**

Run: `php artisan test tests/Feature/HR/EmployeeClockInAccessTest.php tests/Feature/HR/AttendanceLatenessPrecisionTest.php tests/Feature/HR/AttendanceLunchTest.php`

Expected: all focused attendance tests pass, including `20:00:59` accepted, `20:01:00` rejected, minute-precision lateness, and lunch transition behavior.

### Task 6: Make the Time In page minute-accurate and refresh live shop hours

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/TimeIn.tsx:169-184,240-315`
- Test: `resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx`

**Interfaces:**
- Consumes: the existing `/api/staff/shop-hours/today` response (`day`, `is_open`, `open`, `close`, geofence fields) and existing attendance fetch functions.
- Produces: client eligibility matching backend minute precision, plus shop-hours refresh on mount, window focus, and a 60-second interval.

- [x] **Step 1: Normalize `isClockInAllowedAtTime` to minute precision**

Replace the helper's seconds arithmetic with:

```tsx
const currentMinutes = now.getHours() * 60 + now.getMinutes();
const openMinutes = open.hour * 60 + open.minute;
const closeMinutes = close.hour * 60 + close.minute;
const earliestCheckIn = openMinutes - 30;

if (closeMinutes < openMinutes) {
    return currentMinutes >= earliestCheckIn || currentMinutes <= closeMinutes;
}

return currentMinutes >= earliestCheckIn && currentMinutes <= closeMinutes;
```

Keep the existing invalid/missing schedule and `is_open === false` guards.

- [x] **Step 2: Add focus and polling cleanup around the existing shop-hours fetch**

After `fetchShopHours` is declared, add one effect that registers a focus handler and a 60-second timer:

```tsx
useEffect(() => {
    const refreshShopHours = () => {
        void fetchShopHours();
    };
    const intervalId = window.setInterval(refreshShopHours, 60_000);

    window.addEventListener('focus', refreshShopHours);

    return () => {
        window.clearInterval(intervalId);
        window.removeEventListener('focus', refreshShopHours);
    };
}, []);
```

Leave the existing mount fetch in place so the page loads the latest schedule immediately, and do not introduce fixed opening/closing values.

- [x] **Step 3: Run the focused frontend tests**

Run: `pnpm run test:frontend -- resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx`

Expected: all Time In tests pass, including the final-minute and focus-refresh tests, and all layout notice tests pass.

### Task 7: Review, verify, and inspect the final scoped diff

**Files:**
- Inspect only the implementation files and tests listed in Tasks 1–6 plus the approved design doc `docs/superpowers/specs/2026-09-17-employee-clock-in-access-control-design.md`.

**Interfaces:**
- Consumes: passing focused tests and the final working-tree state.
- Produces: evidence-backed completion report with unrelated user changes preserved.

- [x] **Step 1: Run the complete relevant verification**

Run:

```text
php artisan test tests/Feature/HR/EmployeeClockInAccessTest.php tests/Feature/HR/AttendanceLatenessPrecisionTest.php tests/Feature/HR/AttendanceLunchTest.php
pnpm run test:frontend -- resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx
pnpm run build
git diff --check
```

Expected: focused PHP and frontend suites pass, Vite production build succeeds, and `git diff --check` reports no whitespace errors. If the build updates already-dirty generated files under `public/build`, leave those user-owned changes untouched and report them separately.

- [x] **Step 2: Perform the required sequential review**

Confirm: the middleware is employee-only and tenant-scoped; customer/shop-owner requests are not gated; safe reads and security routes remain available; no controller validation or overnight behavior was removed; the layout reuses the existing ERP shell; the page has no hardcoded shop schedule; changed TypeScript has no unused imports or unnecessary assertions; no debug output or temporary code was introduced; and the implementation reuses `User::isEmployeeAccount()` and `AttendanceRecord` without adding a dependency, migration, or speculative abstraction.

- [x] **Step 3: Inspect the final worktree scope**

Run:

```text
git status --short
git diff --stat
git diff -- app/Http/Middleware/EnsureEmployeeClockedIn.php bootstrap/app.php app/Http/Middleware/HandleInertiaRequests.php app/Http/Controllers/Erp/HR/AttendanceController.php resources/js/layout/AppLayout_ERP.tsx resources/js/Pages/ERP/STAFF/TimeIn.tsx tests/Feature/HR/EmployeeClockInAccessTest.php resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx docs/superpowers/plans/2026-09-17-employee-clock-in-access-control.md
git diff --check
```

Expected: only the requested attendance/access-control files are added or modified by this work; the pre-existing unrelated dirty files remain present and are not reverted.
