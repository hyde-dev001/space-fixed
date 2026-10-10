# Logistics Employee Seeder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Seed one Logistics Dispatcher and one Logistics Rider employee and user account for every shop owner.

**Architecture:** Reuse `EmployeeSeeder`'s existing employee array and department-to-role map. Add one focused regression test to the existing logistics employee-role feature test; no new application abstractions, roles, permissions, or migrations are needed.

**Tech Stack:** Laravel, Eloquent seeders, Spatie Laravel Permission, PHPUnit

---

### Task 1: Seed both logistics employee roles

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php`
- Modify: `database/seeders/EmployeeSeeder.php:43-125,178-189`

- [ ] **Step 1: Write the failing feature test**

Add this import to `LogisticsEmployeeRoleAccessTest.php`:

```php
use Database\Seeders\EmployeeSeeder;
```

Add this test:

```php
public function test_employee_seeder_creates_both_logistics_roles(): void
{
    $this->seed(RolesAndPermissionsSeeder::class);

    $shop = ShopOwner::factory()->create([
        'registration_type' => 'company',
        'business_type' => 'both',
    ]);

    $this->seed(EmployeeSeeder::class);

    foreach (['Logistics Dispatcher', 'Logistics Rider'] as $role) {
        $email = str($role)->lower()->replace(' ', '.') . ".{$shop->id}@solespace.com";

        $this->assertDatabaseHas('employees', [
            'shop_owner_id' => $shop->id,
            'email' => $email,
            'department' => $role,
        ]);

        $user = User::where('email', $email)->firstOrFail();

        $this->assertTrue($user->hasRole($role));
        $this->assertContains($user->role, [
            'STAFF',
            strtoupper(str_replace(' ', '_', $role)),
        ]);
    }
}
```

- [ ] **Step 2: Run the new test and verify RED**

Run:

```bash
php artisan test tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php --filter=test_employee_seeder_creates_both_logistics_roles
```

Expected: FAIL because the dispatcher and rider seed records do not exist.

- [ ] **Step 3: Add the two employee definitions**

Append these entries to `$commonEmployees` before its closing bracket:

```php
[
    'first_name' => 'Daniel',
    'last_name' => 'Cruz',
    'email' => "logistics.dispatcher.{$shopOwner->id}@solespace.com",
    'position' => 'Logistics Dispatcher',
    'department' => 'Logistics Dispatcher',
    'salary' => 1076.92,
    'phone' => '+639180000001',
],
[
    'first_name' => 'Marco',
    'last_name' => 'Santos',
    'email' => "logistics.rider.{$shopOwner->id}@solespace.com",
    'position' => 'Logistics Rider',
    'department' => 'Logistics Rider',
    'salary' => 1076.92,
    'phone' => '+639180000002',
],
```

Extend `$roleMap` with:

```php
'Logistics Dispatcher' => ['role' => 'LOGISTICS_DISPATCHER', 'spatie' => 'Logistics Dispatcher'],
'Logistics Rider' => ['role' => 'LOGISTICS_RIDER', 'spatie' => 'Logistics Rider'],
```

Do not change `resolveCompatibleLegacyRole`; its default candidate list already falls back to `STAFF` for enum-backed databases, while SQLite keeps the requested role value. The test accepts both values to verify that cross-database contract.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php --filter=test_employee_seeder_creates_both_logistics_roles
```

Expected: PASS.

- [ ] **Step 5: Run the existing logistics employee-role test file**

Run:

```bash
php artisan test tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php
```

Expected: all tests PASS.

- [ ] **Step 6: Check formatting and the final diff**

Run:

```bash
vendor/bin/pint --test database/seeders/EmployeeSeeder.php tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php
git diff --check
```

Expected: both commands exit successfully with no formatting or whitespace errors.

- [ ] **Step 7: Commit the implementation**

```bash
git add database/seeders/EmployeeSeeder.php tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php
git commit -m "feat: seed logistics employees"
```
