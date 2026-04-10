# Password Change Standardization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Standardize change-password support for shop owner, customer, and ERP employee with consistent security policy, always-visible profile security UI, and throttled endpoints.

**Architecture:** Keep existing role-owned controllers and profile pages, then add the missing shop-owner endpoint while aligning customer and ERP validation/throttling. Use targeted backend feature tests for each role plus focused frontend visibility tests for profile security cards.

**Tech Stack:** Laravel 12, Inertia React + TypeScript, PHPUnit feature tests, Vitest + Testing Library.

---

## Scope Check

This plan is a single subsystem: profile password-change behavior standardization across three role entry points. It is not split because the requirements are coupled by policy consistency, route hardening, and shared UX placement expectations.

## File Structure

### New files
- Create: `tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php`
- Create: `tests/Feature/UserSide/CustomerPasswordUpdateTest.php`
- Create: `tests/Feature/ERP/ErpPasswordUpdateTest.php`
- Create: `resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx`
- Create: `resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx`

### Modified files
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ShopOwner/ShopProfileController.php`
- Modify: `app/Http/Controllers/UserSide/CustomerProfileController.php`
- Modify: `app/Http/Controllers/UserProfileController.php`
- Modify: `resources/js/Pages/UserSide/Profile/customerProfile.tsx`
- Modify: `resources/js/Pages/ShopOwner/Settings/shopProfile.tsx`

### Responsibility split
- `routes/web.php`: add/align throttled password update routes by role.
- Role controllers: enforce current-password check and strong password policy.
- Profile pages: render always-visible Security/Change Password sections.
- Feature tests: verify success, invalid current password, weak password, and throttle behavior.
- Frontend tests: verify security card visibility independent of personal-edit state.

---

### Task 1: Add Shop Owner Password Update Backend

**Files:**
- Create: `tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ShopOwner/ShopProfileController.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopOwnerPasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_owner_can_update_password_with_correct_current_password(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('CurrentPass1!'),
        ]);

        $response = $this->actingAs($owner, 'shop_owner')->post('/shop-owner/shop-profile/password', [
            'current_password' => 'CurrentPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $owner->refresh();
        $this->assertTrue(Hash::check('NewStrongPass1!', $owner->password));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php`
Expected: FAIL with 404 route missing or controller method missing.

- [ ] **Step 3: Write minimal implementation**

```php
// routes/web.php (inside shop-owner auth group)
Route::post('/shop-profile/password', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'updatePassword'])
    ->middleware('throttle:5,1')
    ->name('shop-profile.password.update');
```

```php
// app/Http/Controllers/ShopOwner/ShopProfileController.php
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

public function updatePassword(Request $request)
{
    $shopOwner = Auth::guard('shop_owner')->user();

    $request->validate([
        'current_password' => ['required'],
        'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
    ]);

    if (!Hash::check((string) $request->input('current_password'), (string) $shopOwner->password)) {
        return back()->withErrors([
            'current_password' => 'Current password is incorrect',
        ]);
    }

    $shopOwner->update([
        'password' => Hash::make((string) $request->input('password')),
    ]);

    return back()->with('success', 'Password updated successfully');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/ShopOwner/ShopProfileController.php tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php
git commit -m "feat(shop-owner): add throttled profile password update"
```

### Task 2: Align Customer and ERP Password Policy and Throttling

**Files:**
- Create: `tests/Feature/UserSide/CustomerPasswordUpdateTest.php`
- Create: `tests/Feature/ERP/ErpPasswordUpdateTest.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/UserSide/CustomerProfileController.php`
- Modify: `app/Http/Controllers/UserProfileController.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\UserSide;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerPasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_password_requires_symbol_in_new_password(): void
    {
        $customer = User::factory()->create([
            'password' => Hash::make('CurrentPass1!'),
            'shop_owner_id' => null,
        ]);

        $response = $this->actingAs($customer, 'user')->post('/customer-profile/password', [
            'current_password' => 'CurrentPass1!',
            'password' => 'NoSymbol123',
            'password_confirmation' => 'NoSymbol123',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
```

```php
<?php

namespace Tests\Feature\ERP;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ErpPasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function erp_password_rejects_wrong_current_password(): void
    {
        $employee = User::factory()->create([
            'password' => Hash::make('CurrentPass1!'),
        ]);

        $response = $this->actingAs($employee, 'user')->post('/erp/password', [
            'current_password' => 'WrongPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);

        $response->assertSessionHasErrors('current_password');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/UserSide/CustomerPasswordUpdateTest.php tests/Feature/ERP/ErpPasswordUpdateTest.php`
Expected: FAIL because current rules do not enforce symbols consistently and route middleware is not yet aligned.

- [ ] **Step 3: Write minimal implementation**

```php
// app/Http/Controllers/UserSide/CustomerProfileController.php
use Illuminate\Validation\Rules\Password;

$request->validate([
    'current_password' => ['required'],
    'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
]);
```

```php
// app/Http/Controllers/UserProfileController.php
use Illuminate\Validation\Rules\Password;

$request->validate([
    'current_password' => ['required'],
    'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
]);
```

```php
// routes/web.php
Route::post('/customer-profile/password', [CustomerProfileController::class, 'updatePassword'])
    ->middleware(['auth:user', 'throttle:5,1'])
    ->name('customer-profile.password');

Route::middleware(['auth:user', 'check.suspension'])->group(function () {
    Route::get('/erp/profile', [UserProfileController::class, 'show'])->name('erp.profile');
    Route::post('/erp/password', [UserProfileController::class, 'updatePassword'])
        ->middleware('throttle:5,1')
        ->name('erp.password.update');
});
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/UserSide/CustomerPasswordUpdateTest.php tests/Feature/ERP/ErpPasswordUpdateTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/UserSide/CustomerProfileController.php app/Http/Controllers/UserProfileController.php tests/Feature/UserSide/CustomerPasswordUpdateTest.php tests/Feature/ERP/ErpPasswordUpdateTest.php
git commit -m "feat(password): align customer and erp password policy and throttling"
```

### Task 3: Add Throttle Coverage for All Three Endpoints

**Files:**
- Modify: `tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php`
- Modify: `tests/Feature/UserSide/CustomerPasswordUpdateTest.php`
- Modify: `tests/Feature/ERP/ErpPasswordUpdateTest.php`

- [ ] **Step 1: Write the failing throttle tests**

```php
#[Test]
public function shop_owner_password_route_is_throttled_after_five_attempts(): void
{
    $owner = ShopOwner::factory()->approved()->create([
        'password' => Hash::make('CurrentPass1!'),
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($owner, 'shop_owner')->post('/shop-owner/shop-profile/password', [
            'current_password' => 'WrongPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);
    }

    $last = $this->actingAs($owner, 'shop_owner')->post('/shop-owner/shop-profile/password', [
        'current_password' => 'WrongPass1!',
        'password' => 'NewStrongPass1!',
        'password_confirmation' => 'NewStrongPass1!',
    ]);

    $last->assertStatus(429);
}
```

```php
#[Test]
public function customer_password_route_is_throttled_after_five_attempts(): void
{
    $customer = User::factory()->create([
        'password' => Hash::make('CurrentPass1!'),
        'shop_owner_id' => null,
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($customer, 'user')->post('/customer-profile/password', [
            'current_password' => 'WrongPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);
    }

    $last = $this->actingAs($customer, 'user')->post('/customer-profile/password', [
        'current_password' => 'WrongPass1!',
        'password' => 'NewStrongPass1!',
        'password_confirmation' => 'NewStrongPass1!',
    ]);

    $last->assertStatus(429);
}
```

```php
#[Test]
public function erp_password_route_is_throttled_after_five_attempts(): void
{
    $employee = User::factory()->create([
        'password' => Hash::make('CurrentPass1!'),
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($employee, 'user')->post('/erp/password', [
            'current_password' => 'WrongPass1!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);
    }

    $last = $this->actingAs($employee, 'user')->post('/erp/password', [
        'current_password' => 'WrongPass1!',
        'password' => 'NewStrongPass1!',
        'password_confirmation' => 'NewStrongPass1!',
    ]);

    $last->assertStatus(429);
}
```

- [ ] **Step 2: Run tests to verify current failures**

Run: `php artisan test --filter=throttled_after_five_attempts`
Expected: FAIL for any endpoint not yet throttled.

- [ ] **Step 3: Ensure middleware wiring is complete**

```php
// routes/web.php (final expected password routes)
Route::post('/customer-profile/password', [CustomerProfileController::class, 'updatePassword'])
    ->middleware(['auth:user', 'throttle:5,1'])
    ->name('customer-profile.password');

Route::middleware(['auth:shop_owner'])->prefix('shop-owner')->name('shop-owner.')->group(function () {
    Route::post('/shop-profile/password', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'updatePassword'])
        ->middleware('throttle:5,1')
        ->name('shop-profile.password.update');
});

Route::middleware(['auth:user', 'check.suspension'])->group(function () {
    Route::post('/erp/password', [UserProfileController::class, 'updatePassword'])
        ->middleware('throttle:5,1')
        ->name('erp.password.update');
});
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=throttled_after_five_attempts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php tests/Feature/UserSide/CustomerPasswordUpdateTest.php tests/Feature/ERP/ErpPasswordUpdateTest.php
git commit -m "test(password): cover throttle behavior across role routes"
```

### Task 4: Make Customer Security Card Always Visible

**Files:**
- Create: `resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx`
- Modify: `resources/js/Pages/UserSide/Profile/customerProfile.tsx`

- [ ] **Step 1: Write the failing frontend test**

```tsx
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const usePageMock = vi.fn();

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  Link: ({ children }: { children: ReactNode }) => <>{children}</>,
  usePage: () => usePageMock(),
  router: { post: vi.fn() },
}));

vi.mock("../../../layout/Navigation", () => ({
  default: () => <div>nav</div>,
}));

import CustomerProfile from "../customerProfile";

describe("customer profile security card", () => {
  beforeEach(() => {
    usePageMock.mockReset();
    usePageMock.mockReturnValue({
      url: "/customer-profile",
      props: {
        user: {
          id: 1,
          first_name: "Test",
          last_name: "Customer",
          name: "Test Customer",
          email: "customer@example.com",
          phone: null,
          address: null,
          profile_photo_url: null,
        },
        auth: { user: { id: 1, shop_owner_id: null } },
      },
    });
  });

  it("shows Change Password even when personal info is not in edit mode", () => {
    render(<CustomerProfile />);

    expect(screen.getAllByText(/change password/i).length).toBeGreaterThan(0);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test:frontend -- resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx`
Expected: FAIL because password section is currently gated by personal edit state.

- [ ] **Step 3: Write minimal implementation**

```tsx
// resources/js/Pages/UserSide/Profile/customerProfile.tsx
// Keep personal edit controls unchanged, but render Change Password section unconditionally.
// Remove wrappers like: {isEditingPersonal && ( ...Change Password... )}
// Replace with always-rendered card and keep same submit handler.

<div className="rounded-[28px] border border-gray-200 bg-white px-4 py-4 shadow-sm">
  <h2 className="mb-4 text-[1.02rem] font-semibold text-gray-900">Change Password</h2>
  <form onSubmit={handlePasswordSubmit} className="space-y-3">
    <input type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} placeholder="Enter current password" title="Current password" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none" />
    <input type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} placeholder="Enter new password" title="New password" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none" />
    <input type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} placeholder="Confirm new password" title="Confirm new password" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none" />
    <button type="submit" className="inline-flex w-full items-center justify-center rounded-full bg-[#16233b] px-4 py-3 text-sm font-medium text-white" disabled={isSubmitting}>
      {isSubmitting ? "Updating..." : "Update password"}
    </button>
  </form>
</div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test:frontend -- resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/UserSide/Profile/customerProfile.tsx resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx
git commit -m "feat(customer-profile): always show security password card"
```

### Task 5: Add Shop Owner Security Card UI and Frontend Coverage

**Files:**
- Create: `resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx`
- Modify: `resources/js/Pages/ShopOwner/Settings/shopProfile.tsx`

- [ ] **Step 1: Write the failing frontend test**

```tsx
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const usePageMock = vi.fn();

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => usePageMock(),
  router: { post: vi.fn() },
}));

vi.mock("../../../../layout/AppLayout_shopOwner", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import ShopProfile from "../shopProfile";

describe("shop owner profile security card", () => {
  beforeEach(() => {
    usePageMock.mockReset();
    usePageMock.mockReturnValue({
      props: {
        shop_owner: {
          id: 1,
          first_name: "Shop",
          last_name: "Owner",
          name: "Shop Owner",
          business_name: "Test Shop",
          email: "owner@example.com",
          phone: "09123456789",
          business_address: "Address",
        },
      },
    });
  });

  it("renders change password section", () => {
    render(<ShopProfile />);
    expect(screen.getByText(/change password/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test:frontend -- resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx`
Expected: FAIL because no change-password section exists on the page.

- [ ] **Step 3: Write minimal implementation**

```tsx
// resources/js/Pages/ShopOwner/Settings/shopProfile.tsx
// Add state
const [currentPassword, setCurrentPassword] = useState("");
const [newPassword, setNewPassword] = useState("");
const [confirmPassword, setConfirmPassword] = useState("");
const [isPasswordSubmitting, setIsPasswordSubmitting] = useState(false);

const handlePasswordSubmit = (event: React.FormEvent) => {
  event.preventDefault();

  setIsPasswordSubmitting(true);
  router.post('/shop-owner/shop-profile/password', {
    current_password: currentPassword,
    password: newPassword,
    password_confirmation: confirmPassword,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");
      setIsPasswordSubmitting(false);
      Swal.fire({ icon: "success", title: "Password updated", text: "Your password has been updated successfully." });
    },
    onError: () => {
      setIsPasswordSubmitting(false);
      Swal.fire({ icon: "error", title: "Password update failed", text: "Please check your input and try again." });
    },
  });
};

// Add a dedicated card in the main content area
<div className="bg-white dark:bg-gray-800 dark:bg-opacity-50 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 dark:border-opacity-50 overflow-hidden">
  <div className="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 dark:bg-opacity-80 px-6 py-4">
    <h3 className="text-lg font-bold text-gray-900 dark:text-white">Change Password</h3>
  </div>
  <div className="p-6">
    <form onSubmit={handlePasswordSubmit} className="space-y-4">
      <input type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} placeholder="Current password" className="w-full rounded-lg border border-gray-300 px-3 py-2" />
      <input type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} placeholder="New password" className="w-full rounded-lg border border-gray-300 px-3 py-2" />
      <input type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} placeholder="Confirm new password" className="w-full rounded-lg border border-gray-300 px-3 py-2" />
      <button type="submit" disabled={isPasswordSubmitting} className="rounded-lg bg-blue-600 px-4 py-2 text-white">
        {isPasswordSubmitting ? "Updating..." : "Update Password"}
      </button>
    </form>
  </div>
</div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test:frontend -- resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ShopOwner/Settings/shopProfile.tsx resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx
git commit -m "feat(shop-profile): add security card for password change"
```

### Task 6: Run Full Verification and Final Integration Check

**Files:**
- Modify: `tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php`
- Modify: `tests/Feature/UserSide/CustomerPasswordUpdateTest.php`
- Modify: `tests/Feature/ERP/ErpPasswordUpdateTest.php`

- [ ] **Step 1: Add end-to-end success assertions per role**

```php
// tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php
#[Test]
public function shop_owner_password_is_updated_with_valid_current_password(): void
{
  $owner = ShopOwner::factory()->approved()->create([
    'password' => Hash::make('CurrentPass1!'),
  ]);

  $this->actingAs($owner, 'shop_owner')->post('/shop-owner/shop-profile/password', [
    'current_password' => 'CurrentPass1!',
    'password' => 'NewStrongPass1!',
    'password_confirmation' => 'NewStrongPass1!',
  ]);

  $this->assertTrue(Hash::check('NewStrongPass1!', $owner->fresh()->password));
}
```

```php
// tests/Feature/UserSide/CustomerPasswordUpdateTest.php
#[Test]
public function customer_password_is_updated_with_valid_current_password(): void
{
  $customer = User::factory()->create([
    'password' => Hash::make('CurrentPass1!'),
    'shop_owner_id' => null,
  ]);

  $this->actingAs($customer, 'user')->post('/customer-profile/password', [
    'current_password' => 'CurrentPass1!',
    'password' => 'NewStrongPass1!',
    'password_confirmation' => 'NewStrongPass1!',
  ]);

  $this->assertTrue(Hash::check('NewStrongPass1!', $customer->fresh()->password));
}
```

```php
// tests/Feature/ERP/ErpPasswordUpdateTest.php
#[Test]
public function erp_user_password_is_updated_with_valid_current_password(): void
{
  $employee = User::factory()->create([
    'password' => Hash::make('CurrentPass1!'),
  ]);

  $this->actingAs($employee, 'user')->post('/erp/password', [
    'current_password' => 'CurrentPass1!',
    'password' => 'NewStrongPass1!',
    'password_confirmation' => 'NewStrongPass1!',
  ]);

  $this->assertTrue(Hash::check('NewStrongPass1!', $employee->fresh()->password));
}
```

- [ ] **Step 2: Run backend verification suite**

Run: `php artisan test tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php tests/Feature/UserSide/CustomerPasswordUpdateTest.php tests/Feature/ERP/ErpPasswordUpdateTest.php`
Expected: PASS.

- [ ] **Step 3: Run frontend verification suite**

Run: `pnpm test:frontend -- resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx`
Expected: PASS.

- [ ] **Step 4: Run focused smoke checks for touched profile pages**

Run: `php artisan test --filter=ShopOwnerPasswordUpdateTest|CustomerPasswordUpdateTest|ErpPasswordUpdateTest`
Expected: PASS and no auth/guard regressions in touched endpoints.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/ShopOwner/ShopOwnerPasswordUpdateTest.php tests/Feature/UserSide/CustomerPasswordUpdateTest.php tests/Feature/ERP/ErpPasswordUpdateTest.php
git commit -m "test(password): finalize multi-role password update coverage"
```

---

## Self-Review

### 1) Spec coverage
- Shop owner missing regular change-password flow: covered by Task 1 and Task 5.
- Customer always-visible security card: covered by Task 4.
- Employee alignment: covered by Task 2.
- Strong policy + current password requirement: covered by Task 1 and Task 2.
- Throttle for all endpoints: covered by Task 3.
- Verification strategy: covered by Task 6.

No uncovered approved requirement found.

### 2) Placeholder scan
- Removed generic TODO language.
- Every coding step has concrete snippets or exact target behavior.
- Every run step has exact command and expected outcome.

### 3) Type consistency
- Route paths are consistent with existing profile flows:
  - `/shop-owner/shop-profile/password`
  - `/customer-profile/password`
  - `/erp/password`
- Request fields are consistent across all roles:
  - `current_password`, `password`, `password_confirmation`

No naming mismatch found.
