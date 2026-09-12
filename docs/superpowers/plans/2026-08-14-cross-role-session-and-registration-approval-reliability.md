# Cross-Role Session and Registration Approval Reliability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve the valid customer, employee, shop-owner, or privileged actor when another guard in the shared Laravel session is stale, while restoring customer guard consistency and proving registration approval works end to end.

**Architecture:** Keep the existing multi-guard session architecture, but make lifecycle enforcement remove only the invalid guard and rotate the session identifier without flushing unrelated authentication state. Align customer onboarding with the existing `user` guard, make badge polling passive on `401`, and verify the production registration path through a real registration-to-approval feature test plus explicit deployment synchronization.

**Tech Stack:** Laravel 12, PHP 8.2+, PHPUnit, Inertia 2, React 18, TypeScript 5.7, Vitest, Vite 7, pnpm.

---

## File map

- Modify `app/Http/Middleware/CheckEmployeeSuspension.php`: isolate lifecycle removal by guard, preserve valid guards, scope employee checks by tenant, and retain narrow onboarding/recovery exceptions.
- Modify `tests/Feature/Auth/SuspensionSessionEnforcementTest.php`: add mixed-guard, tenant-scoping, pending-owner, and ERP/API regressions.
- Modify `app/Http/Controllers/UserController.php`: authenticate customer registration and unverified customer login through `user`; tenant-scope employee login checks.
- Modify `app/Http/Controllers/EmailVerificationController.php`: continue a signed customer verification through the canonical `user` guard.
- Modify `routes/web.php`: make verification notice/resend accept `user` and `shop_owner`, selecting `user` for customers.
- Modify `tests/Feature/UserSide/CustomerRegistrationAddressTest.php`: prove registration establishes `user` and can immediately reach protected location/address endpoints.
- Create `resources/js/hooks/__tests__/useBadgeCounts.test.tsx`: prove an incidental `401` stops polling without navigation.
- Modify `resources/js/hooks/useBadgeCounts.ts`: remove redirect authority from the background poll.
- Create `tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php`: submit the real shop-owner registration payload and approve the resulting pending owner through the privileged endpoint.
- Modify `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`: strengthen safe Inertia failure/correlation coverage if the end-to-end test exposes a response-boundary gap.
- Modify `docs/super-admin-changes-guide.md`: record migration/cache/worker deployment order and smoke tests.
- Modify `docs/ai-learning-log.md`: record the durable shared-session guard-isolation lesson without account data or secrets.
- Regenerate `public/build/manifest.json` and affected `public/build/assets/*`: publish the matching frontend build.

### Task 1: Guard-isolated lifecycle enforcement

**Files:**
- Modify: `tests/Feature/Auth/SuspensionSessionEnforcementTest.php`
- Modify: `app/Http/Middleware/CheckEmployeeSuspension.php`

- [ ] **Step 1: Add failing mixed-guard regression tests**

Add tests that model the browser session rather than isolated guards:

```php
public function test_pending_owner_guard_does_not_destroy_valid_employee_session(): void
{
    $employeeOwner = ShopOwner::factory()->approved()->create();
    $employee = User::factory()->create([
        'email' => 'active.employee@example.test',
        'status' => 'active',
        'shop_owner_id' => $employeeOwner->id,
    ]);
    Employee::factory()->active()->create([
        'shop_owner_id' => $employeeOwner->id,
        'email' => $employee->email,
    ]);
    $pendingOwner = ShopOwner::factory()->pending()->create();

    \Spatie\Permission\Models\Permission::findOrCreate('access-product-upload-staff', 'user');
    $employee->givePermissionTo('access-product-upload-staff');

    $this->actingAs($pendingOwner, 'shop_owner')
        ->actingAs($employee, 'user')
        ->getJson('/api/products/meta/showroom-entitlement')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson(route('user.me'))
        ->assertOk()
        ->assertJsonPath('user.id', $employee->id);

    $this->assertGuest('shop_owner');
    $this->assertAuthenticatedAs($employee, 'user');
}

public function test_unavailable_user_guard_does_not_destroy_valid_owner_product_session(): void
{
    $owner = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
    $staleUser = User::factory()->create(['status' => 'suspended']);

    $this->actingAs($staleUser, 'user')
        ->actingAs($owner, 'shop_owner')
        ->getJson(route('shop_owner.products.showroom-entitlement'))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertGuest('user');
    $this->assertAuthenticatedAs($owner, 'shop_owner');
}
```

Also add focused tests for:

- a valid `super_admin` session surviving a stale pending `shop_owner` guard;
- the pending owner retaining access to `shop-owner.pending-approval` but not an operational owner route;
- the rejected-owner private-document/resubmission exceptions remaining unchanged;
- same normalized employee email in another shop not affecting the current employee;
- duplicate matching employee rows in the same shop remaining denied; and
- existing single suspended/archived guard behavior still returning the lifecycle denial and logging out that guard.

- [ ] **Step 2: Run the tests and verify the current middleware fails the new cases**

Run:

```powershell
$env:APP_KEY='base64:MDAxMTIyMzM0NDU1NjY3Nzg4ODk5YWFiYmNjZGRlZWY='
php artisan test tests/Feature/Auth/SuspensionSessionEnforcementTest.php --stop-on-failure
```

Expected: at least the mixed-guard tests fail because `session()->invalidate()` removes the valid guard, while the original lifecycle tests still pass before the failing case.

- [ ] **Step 3: Implement guard-only removal and tenant-scoped employee validation**

Refactor `handle()` without introducing a new service or dependency:

```php
$validApplicationGuardPresent = Auth::guard('super_admin')->check();
$firstDenial = null;
$removedInvalidGuard = false;

// Preserve explicit pending/rejected onboarding and recovery routes.
if ($this->isOwnerLifecycleException($routeName, $authenticatedShopOwner)) {
    return $next($request);
}

if (Auth::guard('shop_owner')->check()) {
    $shopOwner = $this->freshShopOwner();

    if ($shopOwner?->trashed() || ! $this->isShopOwnerOperational($shopOwner?->status)) {
        Auth::guard('shop_owner')->logout();
        $removedInvalidGuard = true;
        $firstDenial ??= [
            route('shop-owner.login.form'),
            'Your shop account is unavailable. Please contact support.',
            $shopOwner?->trashed() || ! $this->isShopOwnerSuspended($shopOwner?->status)
                ? 'account_unavailable'
                : 'account_suspended',
        ];
    } else {
        $validApplicationGuardPresent = true;
    }
}

if (Auth::guard('user')->check()) {
    // Reload User and parent ShopOwner exactly as today.
    // For linked employees only, query Employee by shop_owner_id + normalized email.
    // On failure, logout only `user` and record the first denial.
    // On success, set $validApplicationGuardPresent = true.
}

if ($removedInvalidGuard && $request->hasSession()) {
    $request->session()->regenerate();
}

if (! $validApplicationGuardPresent && is_array($firstDenial)) {
    return $this->suspendedResponse($request, ...$firstDenial);
}

return $next($request);
```

Implement the employee query only for linked employees:

```php
$employees = Employee::withTrashed()
    ->where('shop_owner_id', $user->shop_owner_id)
    ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
    ->orderBy('id')
    ->get();
```

Remove the broad `shop-owner.erp.*` bypass. Keep only named pending/rejected onboarding and recovery exceptions. Change `logoutGuard()` so it logs out one guard only; perform the one session-ID rotation after all guard checks.

- [ ] **Step 4: Run lifecycle and ERP actor tests**

Run:

```powershell
$env:APP_KEY='base64:MDAxMTIyMzM0NDU1NjY3Nzg4ODk5YWFiYmNjZGRlZWY='
php artisan test tests/Feature/Auth/SuspensionSessionEnforcementTest.php tests/Feature/BusinessScaling/ErpActorContextTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php --stop-on-failure
```

Expected: PASS, allowing only existing repository warnings about unavailable Vite manifest assets in the test environment.

- [ ] **Step 5: Commit the lifecycle fix**

```powershell
git add -- app/Http/Middleware/CheckEmployeeSuspension.php tests/Feature/Auth/SuspensionSessionEnforcementTest.php
git commit -m "fix: isolate shared session guard lifecycle"
```

### Task 2: Canonical customer `user` guard

**Files:**
- Modify: `tests/Feature/UserSide/CustomerRegistrationAddressTest.php`
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `app/Http/Controllers/EmailVerificationController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Change the customer registration test to require `user`**

Replace the current `auth('web')->id()` assertion with:

```php
$user = User::query()
    ->where('email', 'juan.dela.cruz@gmail.com')
    ->firstOrFail();

$this->assertAuthenticatedAs($user, 'user');
$this->assertGuest('web');

$this->getJson(route('user.addresses.index'))
    ->assertOk();
```

Add a signed-verification regression that verifies the customer and leaves the same customer authenticated on `user`, plus an unverified-login regression proving the verification notice is reachable through `auth:user`.

- [ ] **Step 2: Run the customer registration test and verify it fails on the current `web` guard**

Run:

```powershell
$env:APP_KEY='base64:MDAxMTIyMzM0NDU1NjY3Nzg4ODk5YWFiYmNjZGRlZWY='
php artisan test tests/Feature/UserSide/CustomerRegistrationAddressTest.php --stop-on-failure
```

Expected: FAIL because registration currently authenticates `web`, while protected address APIs require `user`.

- [ ] **Step 3: Align customer onboarding and verification with `user`**

Make the surgical guard substitutions:

```php
// UserController::register
Auth::guard('user')->login($user);
$request->session()->regenerate();

// UserController::login, unverified customer branch
Auth::guard('user')->login($user);
$request->session()->regenerate();
```

Tenant-scope the employee status lookup in `UserController::login` using both `shop_owner_id` and normalized email.

Update verification routes:

```php
$user = Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user();
```

Use `auth:user,shop_owner` on the verification notice and resend routes. In `EmailVerificationController`, after a valid signed customer verification, establish `user` if it is not already authenticated and regenerate the session identifier before rendering success. Keep the existing shop-owner branch on `shop_owner`.

- [ ] **Step 4: Run customer auth and address/location regressions**

Run:

```powershell
$env:APP_KEY='base64:MDAxMTIyMzM0NDU1NjY3Nzg4ODk5YWFiYmNjZGRlZWY='
php artisan test tests/Feature/UserSide/CustomerRegistrationAddressTest.php tests/Feature/CheckoutAddressOwnershipTest.php tests/Feature/UserSide/ShippingEstimateControllerTest.php --stop-on-failure
```

Expected: PASS.

- [ ] **Step 5: Commit the customer guard fix**

```powershell
git add -- app/Http/Controllers/UserController.php app/Http/Controllers/EmailVerificationController.php routes/web.php tests/Feature/UserSide/CustomerRegistrationAddressTest.php
git commit -m "fix: use canonical customer session guard"
```

### Task 3: Passive customer badge polling

**Files:**
- Create: `resources/js/hooks/__tests__/useBadgeCounts.test.tsx`
- Modify: `resources/js/hooks/useBadgeCounts.ts`

- [ ] **Step 1: Add a failing hook test for `401` behavior**

Create a probe component and mock Inertia navigation:

```tsx
const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
  router: { visit: inertia.visit },
}));

const Probe = () => {
  const counts = useBadgeCounts(true);
  return <span>{counts.orderStatusCount}</span>;
};

it('stops polling without navigating when badge counts return 401', async () => {
  vi.useFakeTimers();
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 401 }));

  render(<Probe />);
  await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
  await vi.advanceTimersByTimeAsync(4000);

  expect(fetch).toHaveBeenCalledTimes(1);
  expect(inertia.visit).not.toHaveBeenCalled();
});
```

Restore timers and globals in `afterEach`.

- [ ] **Step 2: Run the focused frontend test and verify it fails**

Run:

```powershell
pnpm exec vitest run resources/js/hooks/__tests__/useBadgeCounts.test.tsx
```

Expected: FAIL because the current hook calls `router.visit('/user/login')` and continues scheduling requests.

- [ ] **Step 3: Remove redirect authority and stop the polling loop**

Remove the `router` import. Use an effect-local stop flag:

```tsx
useEffect(() => {
  if (!enabled) return;

  let stopped = false;

  const fetchCounts = async () => {
    if (stopped) return;

    const response = await fetch('/api/customer/badge-counts', requestOptions);

    if (response.status === 401) {
      stopped = true;
      setCounts({ orderStatusCount: 0, repairStatusCount: 0, chatIconCount: 0, userIconCount: 0 });
      return;
    }

    if (response.ok) {
      // Keep the existing response normalization.
    }
  };

  void fetchCounts();
  const interval = window.setInterval(() => void fetchCounts(), 2000);

  return () => {
    stopped = true;
    window.clearInterval(interval);
  };
}, [enabled]);
```

Keep network errors non-navigating and preserve existing safe console diagnostics.

- [ ] **Step 4: Run focused hook, address-map, and navigation tests**

Run:

```powershell
pnpm exec vitest run resources/js/hooks/__tests__/useBadgeCounts.test.tsx resources/js/components/address/__tests__/CustomerAddressMapPicker.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx
```

Expected: PASS.

- [ ] **Step 5: Commit the polling fix**

```powershell
git add -- resources/js/hooks/useBadgeCounts.ts resources/js/hooks/__tests__/useBadgeCounts.test.tsx
git commit -m "fix: keep badge polling from redirecting sessions"
```

### Task 4: End-to-end shop-owner registration approval

**Files:**
- Create: `tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php`
- Modify only if the test exposes a response-boundary defect: `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php`
- Modify only if needed for safe failure coverage: `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`

- [ ] **Step 1: Add the real HTTP registration-to-approval test**

The test must use `RefreshDatabase`, `Storage::fake('local')`, `Queue::fake()`, the existing registration OTP cache key, valid Cavite coordinates, and four one-pixel PNG documents. Submit `POST /shop-owner/register`, load the created pending owner and documents, then authenticate a completed privileged actor without clearing the pending owner guard.

Core sequence:

```php
$this->markRegistrationEmailVerified($email);

$this->postJson('/shop-owner/register', $this->registrationPayload($email))
    ->assertCreated()
    ->assertJsonPath('success', true);

$owner = ShopOwner::query()->where('email', $email)->firstOrFail();
$this->assertAuthenticatedAs($owner, 'shop_owner');

$admin = SuperAdmin::factory()->admin()->create();
$response = $this->actingAsCompletedPrivileged($admin)
    ->postJson(route('admin.registrations.approve', $owner), $this->approvalPayload($owner));

$response->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonPath('applied', true);
```

Build the registration request from the current production contract, not a factory-only shortcut:

```php
private function registrationPayload(string $email): array
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );

    $metadata = [
        'business_registration' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
        'mayors_permit' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'dated', 'expires_on' => '2027-01-01'],
        'bir_certificate' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
        'valid_id' => ['issued_on' => '2026-01-01', 'expiration_mode' => 'none', 'expires_on' => null],
    ];

    return [
        'first_name' => 'Approval',
        'last_name' => 'Regression',
        'email' => $email,
        'phone' => '09171234567',
        'business_name' => 'Approval Regression Shoes',
        'business_address' => 'Dasmariñas, Cavite',
        'business_type' => 'retail',
        'registration_type' => 'individual',
        'attendance_geofence_enabled' => true,
        'shop_latitude' => 14.3294,
        'shop_longitude' => 120.9367,
        'shop_address' => 'Dasmariñas, Cavite',
        'shop_geofence_radius' => 150,
        'business_registration_type' => 'dti_registration',
        'business_registration' => UploadedFile::fake()->createWithContent('dti.png', $png),
        'mayors_permit' => UploadedFile::fake()->createWithContent('permit.png', $png),
        'bir_certificate' => UploadedFile::fake()->createWithContent('bir.png', $png),
        'valid_id' => UploadedFile::fake()->createWithContent('id.png', $png),
        'document_metadata' => $metadata,
    ];
}

private function markRegistrationEmailVerified(string $email): void
{
    $normalized = strtolower(trim($email));

    Cache::put(
        'shop_owner_registration_email_otp:'.sha1($normalized),
        [
            'verified' => true,
            'verified_at' => now()->timestamp,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(60)->timestamp,
            'otp_hash' => null,
        ],
        now()->addMinutes(60),
    );
}
```

Assert the owner is approved, all required current documents are approved with reviewer metadata, `password_reset_tokens` exists, eligible `shop_owner_modules` rows exist, the privileged activity exists, and approval mail is queued. This test also proves a pending owner guard cannot destroy the valid privileged session before approval.

- [ ] **Step 2: Run the end-to-end test and inspect the exact first failure**

Run:

```powershell
$env:APP_KEY='base64:MDAxMTIyMzM0NDU1NjY3Nzg4ODk5YWFiYmNjZGRlZWY='
php artisan test tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php --stop-on-failure
```

Expected before Task 1: fail at the shared-session lifecycle boundary. Expected after Tasks 1-3: pass, or reveal a concrete registration/approval contract defect to fix at its source.

- [ ] **Step 3: Add safe Inertia failure assertions if missing**

For an injected approval-service exception, make a separate browser-shaped request with `X-Inertia: true`, `X-Requested-With: XMLHttpRequest`, and an HTML `Accept` header. Assert:

```php
$response->assertStatus(500)
    ->assertHeader('X-Correlation-ID')
    ->assertJsonPath('code', 'shop_registration_approval_error');

$this->assertStringNotContainsString('audit unavailable', $response->getContent());
$this->assertDatabaseHas('shop_owners', ['id' => $owner->id, 'status' => 'pending']);
```

Do not change the approval transaction when the end-to-end test already passes. Do not add a partial approval fallback.

- [ ] **Step 4: Run all registration decision and location registration tests**

Run:

```powershell
$env:APP_KEY='base64:MDAxMTIyMzM0NDU1NjY3Nzg4ODk5YWFiYmNjZGRlZWY='
php artisan test tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php --stop-on-failure
```

Expected: PASS.

- [ ] **Step 5: Commit the approval regression**

```powershell
git add -- tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php
git commit -m "test: cover registration approval end to end"
```

Stage only files that actually changed.

### Task 5: Deployment guidance and durable learning

**Files:**
- Modify: `docs/super-admin-changes-guide.md`
- Modify: `docs/ai-learning-log.md`

- [ ] **Step 1: Add the exact production deployment order**

Document:

```powershell
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

State that the backend commit and matching `public/build` must deploy together and that Hostinger/PHP-FPM/OPcache workers must be restarted through the host-supported mechanism.

- [ ] **Step 2: Add smoke tests and recovery guidance**

Include customer location/checkout, staff Add New Product followed by sidebar navigation, owner ERP navigation/action, and registration approval. Require a fresh `X-Correlation-ID` plus matching server exception class if approval still fails. Do not recommend database edits, status overrides, or authorization weakening.

- [ ] **Step 3: Add one durable learning entry**

Record that multiple Laravel session guards can coexist, so involuntary lifecycle enforcement must remove only the invalid guard and let route-specific middleware choose the actor. Do not include real account identifiers, cookies, tokens, or correlation IDs.

- [ ] **Step 4: Check documentation diff and commit**

Run:

```powershell
git diff --check
```

Then:

```powershell
git add -- docs/super-admin-changes-guide.md docs/ai-learning-log.md
git commit -m "docs: add shared session deployment checks"
```

### Task 6: Sequential review, full verification, build, and push

**Files:**
- Review all files changed since `e8a348df6`
- Regenerate: `public/build/manifest.json`
- Regenerate: affected `public/build/assets/*`

- [ ] **Step 1: Run the required sequential review stack**

Review in repository order:

1. Ponytail simplification: remove avoidable abstractions and keep guard handling in the existing middleware.
2. Standards review: Laravel conventions, focused tests, existing route/guard patterns.
3. Spec review: every approved outcome and security invariant.
4. TypeScript/React review: focused hook responsibility, cleanup, no `any` additions, no unnecessary rerenders.
5. Karpathy review: surface assumptions, remove only code orphaned by this change, keep the diff surgical.
6. Code-splitting review: `N/A` unless imports or bundle boundaries changed.
7. Security review: guard selection, session fixation, CSRF continuity, tenant scoping, privileged transaction rollback, and safe errors.

Record every result as pass, resolved finding, `N/A`, or not measured.

- [ ] **Step 2: Run the focused backend suite**

Run:

```powershell
$env:APP_KEY='base64:MDAxMTIyMzM0NDU1NjY3Nzg4ODk5YWFiYmNjZGRlZWY='
php artisan test tests/Feature/Auth/SuspensionSessionEnforcementTest.php tests/Feature/UserSide/CustomerRegistrationAddressTest.php tests/Feature/CheckoutAddressOwnershipTest.php tests/Feature/UserSide/ShippingEstimateControllerTest.php tests/Feature/BusinessScaling/ErpActorContextTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/SuperAdmin/ShopOwnerRegistrationApprovalEndToEndTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php
```

Expected: PASS, with any warnings reported exactly rather than described as clean passes.

- [ ] **Step 3: Run the complete frontend test suite**

Run:

```powershell
pnpm run test:frontend
```

Expected: PASS.

- [ ] **Step 4: Build production frontend assets**

Run:

```powershell
pnpm run build
```

Expected: Vite production build completes successfully and updates the committed manifest/assets.

- [ ] **Step 5: Run final hygiene checks**

Run:

```powershell
git diff --check
git status --short
```

Inspect changed areas for unused imports, stale `router` references, abandoned TODOs, duplicate guard queries, and accidentally logged sensitive values.

- [ ] **Step 6: Commit generated build assets and final corrections**

```powershell
git add -A -- public/build
git commit -m "build: publish cross-role session reliability fix"
```

Do not stage unrelated user changes if the worktree becomes dirty.

- [ ] **Step 7: Verify the committed tree and push**

Run:

```powershell
git status --short --branch
git log -6 --oneline --decorate
git push origin super-admin-phase-0-containment
```

Expected: clean worktree, branch synchronized with `origin/super-admin-phase-0-containment`, and the remote contains the implementation commits plus matching build assets.
