# Flexible Premium Plans and Connected Showroom Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let super admins manage premium plans and ordered benefits while synchronizing subscription slot entitlements and adding a second connected showroom only for capacities from 85 through 150.

**Architecture:** Keep `premium_plans` as the plan catalog and `shop_owner_subscriptions.showroom_slot_limit` as the active entitlement snapshot. Store ordered benefit text in one JSON column, add four guarded admin mutations to the existing controller, and reuse the current Inertia page. Isolate the two-room capacity calculation in a tiny tested TypeScript helper; leave the existing room path untouched for capacities at or below 84.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, PHPUnit 11, Inertia React 2, React 18, TypeScript 5.7, Three.js 0.182, Tailwind CSS 4, Vitest 3.

## Global Constraints

- Showroom slot limits must be integers from 1 through 150.
- Capacities from 1 through 84 must keep the existing showroom geometry and behavior unchanged.
- Capacities from 85 through 150 must use two equal-size connected rooms and split capacity as `ceil(limit / 2)` and `floor(limit / 2)`.
- Slot increases apply immediately to active/showroom-entitled subscriptions; decreases apply only when renewal copies the current plan.
- Archive instead of delete; archived plans remain linked to current and historical subscriptions and can be reactivated.
- Plan codes are immutable after creation.
- Ordered plan benefits contain at most 20 nonempty strings of at most 200 characters each.
- Do not add a package, drag-and-drop system, benefit table, or state-management abstraction.

---

## File Map

- Create `database/migrations/2026_07_12_000000_add_benefits_to_premium_plans_table.php`: add/remove the nullable JSON column.
- Modify `app/Models/PremiumPlan.php`: make `benefits` mass assignable and array-cast.
- Modify `database/seeders/PremiumPlanSeeder.php`: seed the current common benefit bullets.
- Create `tests/Feature/AdminPremiumPlanManagementTest.php`: admin CRUD-state and entitlement propagation coverage.
- Modify `app/Http/Controllers/SuperAdminController.php`: expose plans and implement create/update/archive/reactivate.
- Modify `routes/web.php`: register four plan-management routes inside the existing `super_admin.auth` admin group.
- Modify `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx`: plan cards and shared create/edit modal.
- Modify `resources/js/Pages/ShopOwner/Premium/premuimBenefits.tsx`: type and render backend benefits.
- Create `resources/js/Pages/UserSide/Products/showroomRooms.ts`: pure capacity/room split helper.
- Create `resources/js/Pages/UserSide/Products/showroomRooms.test.ts`: 84/85/100/150 boundary checks.
- Modify `resources/js/Pages/UserSide/Products/VirtualShowroom.tsx`: select room products, add connected-room doors, and expose room HUD state.
- Modify `tests/Feature/PremiumFeatureTest.php`: assert active plan benefits and archived-plan visibility through the real API.

### Task 1: Persist Ordered Plan Benefits

**Files:**
- Create: `database/migrations/2026_07_12_000000_add_benefits_to_premium_plans_table.php`
- Modify: `app/Models/PremiumPlan.php`
- Modify: `database/seeders/PremiumPlanSeeder.php`
- Test: `tests/Feature/AdminPremiumPlanManagementTest.php`

**Interfaces:**
- Produces: `PremiumPlan::$benefits` as `array<int, string>|null`.
- Consumes: existing `premium_plans` fields and `PremiumPlan::active()`.

- [ ] **Step 1: Write the failing model persistence test**

Create the test class with `RefreshDatabase`, a `createPlan()` helper matching `PremiumFeatureTest`, and this test:

```php
/** @test */
public function premium_plan_preserves_ordered_benefits(): void
{
    $plan = $this->createPlan([
        'benefits' => ['360-degree images', 'Priority showroom placement'],
    ]);

    $this->assertSame(
        ['360-degree images', 'Priority showroom placement'],
        $plan->fresh()->benefits,
    );
}
```

- [ ] **Step 2: Run the focused test and confirm the missing-column failure**

Run: `php artisan test tests/Feature/AdminPremiumPlanManagementTest.php --filter=premium_plan_preserves_ordered_benefits`

Expected: FAIL because `premium_plans.benefits` does not exist or is not fillable.

- [ ] **Step 3: Add the migration and model cast**

Migration body:

```php
public function up(): void
{
    Schema::table('premium_plans', function (Blueprint $table) {
        $table->json('benefits')->nullable()->after('showroom_slot_limit');
    });
}

public function down(): void
{
    Schema::table('premium_plans', function (Blueprint $table) {
        $table->dropColumn('benefits');
    });
}
```

Add `'benefits'` to `$fillable` and `'benefits' => 'array'` to `$casts` in `PremiumPlan`.

- [ ] **Step 4: Seed current benefit copy without inventing plan tiers**

Add this identical ordered array to each existing seed record:

```php
'benefits' => [
    'View shoes in horizontal detail inside the showroom',
    'Enable image-sequence uploads for showroom presentation',
],
```

Duration and slot capacity remain derived from their dedicated fields in the UI, not duplicated in JSON.

- [ ] **Step 5: Run the focused test**

Run: `php artisan test tests/Feature/AdminPremiumPlanManagementTest.php --filter=premium_plan_preserves_ordered_benefits`

Expected: PASS (1 test).

- [ ] **Step 6: Commit the persistence slice**

```bash
git add database/migrations/2026_07_12_000000_add_benefits_to_premium_plans_table.php app/Models/PremiumPlan.php database/seeders/PremiumPlanSeeder.php tests/Feature/AdminPremiumPlanManagementTest.php
git commit -m "feat: store ordered premium plan benefits"
```

### Task 2: Add Safe Admin Plan Mutations

**Files:**
- Modify: `tests/Feature/AdminPremiumPlanManagementTest.php`
- Modify: `app/Http/Controllers/SuperAdminController.php:156-273`
- Modify: `routes/web.php:1643-1650`

**Interfaces:**
- Produces routes: `POST /admin/premium-plans`, `PUT /admin/premium-plans/{premiumPlan}`, `POST /admin/premium-plans/{premiumPlan}/archive`, and `POST /admin/premium-plans/{premiumPlan}/reactivate`.
- Produces Inertia prop: `plans: Array<{id, plan_code, name, description, price, duration_days, showroom_slot_limit, benefits, status, active_subscriptions_count}>`.
- Consumes `PremiumPlan::$benefits` from Task 1 and `ShopOwnerSubscription::showroomEntitled()`.

- [ ] **Step 1: Add authentication and create-validation tests**

Use `SuperAdmin::factory()->create()` and `actingAs($admin, 'super_admin')`. Assert a guest is redirected by `super_admin.auth`, then assert an authenticated create request persists normalized benefits:

```php
$response = $this->actingAs($admin, 'super_admin')->post('/admin/premium-plans', [
    'plan_code' => 'elite',
    'name' => 'Elite',
    'description' => 'Two-room showroom plan',
    'price' => 799,
    'duration_days' => 30,
    'showroom_slot_limit' => 100,
    'benefits' => ['Two connected showroom rooms', 'Image-sequence uploads'],
]);

$response->assertRedirect('/admin/subscription-management');
$this->assertDatabaseHas('premium_plans', [
    'plan_code' => 'elite',
    'showroom_slot_limit' => 100,
    'status' => 'active',
]);
```

Add a data-provider test rejecting slot limits `0` and `151`, duplicate codes, durations `0` and `3651`, 21 benefits, blank benefits, and 201-character benefits.

- [ ] **Step 2: Add failing update propagation tests**

Create two active subscriptions on the same plan. Update 84 to 100 and assert both snapshots become 100. Then update 100 to 60 and assert both snapshots remain 100 while the plan becomes 60. Also assert the submitted `plan_code` cannot change the stored code.

- [ ] **Step 3: Add failing archive/reactivate and page-prop tests**

Assert archive changes only `premium_plans.status` to `archived`, leaves an active subscription unchanged, and reactivate restores `active`. Request `/admin/subscription-management` and use `assertInertia()` to verify archived plans and `active_subscriptions_count` are present.

- [ ] **Step 4: Run the new feature test and verify route failures**

Run: `php artisan test tests/Feature/AdminPremiumPlanManagementTest.php`

Expected: FAIL because the routes and controller methods do not exist.

- [ ] **Step 5: Register guarded routes**

Inside the existing `Route::middleware('super_admin.auth')->prefix('admin')->name('admin.')->group(...)` block add:

```php
Route::post('/premium-plans', [SuperAdminController::class, 'storePremiumPlan'])->name('premium-plans.store');
Route::put('/premium-plans/{premiumPlan}', [SuperAdminController::class, 'updatePremiumPlan'])->name('premium-plans.update');
Route::post('/premium-plans/{premiumPlan}/archive', [SuperAdminController::class, 'archivePremiumPlan'])->name('premium-plans.archive');
Route::post('/premium-plans/{premiumPlan}/reactivate', [SuperAdminController::class, 'reactivatePremiumPlan'])->name('premium-plans.reactivate');
```

- [ ] **Step 6: Add one shared validation method and the minimal mutations**

Import `PremiumPlan`, `ShopOwnerSubscription`, `DB`, and `Rule`. Add:

```php
private function validatedPremiumPlan(Request $request, ?PremiumPlan $plan = null): array
{
    return $request->validate([
        'plan_code' => [$plan ? 'sometimes' : 'required', 'string', 'max:50', Rule::unique('premium_plans', 'plan_code')->ignore($plan?->id)],
        'name' => ['required', 'string', 'max:120'],
        'description' => ['nullable', 'string', 'max:1000'],
        'price' => ['required', 'numeric', 'min:0'],
        'duration_days' => ['required', 'integer', 'between:1,3650'],
        'showroom_slot_limit' => ['required', 'integer', 'between:1,150'],
        'benefits' => ['present', 'array', 'max:20'],
        'benefits.*' => ['required', 'string', 'max:200'],
    ]);
}
```

Trim/filter benefits before persistence. On update, remove `plan_code` from the payload and use `DB::transaction()`. If the new slot limit is higher, update only linked `showroomEntitled()` subscriptions whose snapshot is lower. Never bulk-update on a decrease. Archive/reactivate only set `status` and redirect back with `success`.

- [ ] **Step 7: Expose plans in `showSubscriptionManagement()`**

Query `PremiumPlan::withCount(['subscriptions as active_subscriptions_count' => fn ($query) => $query->showroomEntitled()])->orderBy('price')->get()` and add the normalized collection as the `plans` Inertia prop without changing existing `subscriptions` or `stats` keys.

- [ ] **Step 8: Run admin and premium regressions**

Run: `php artisan test tests/Feature/AdminPremiumPlanManagementTest.php tests/Feature/PremiumFeatureTest.php`

Expected: PASS.

- [ ] **Step 9: Commit the backend behavior**

```bash
git add app/Http/Controllers/SuperAdminController.php routes/web.php tests/Feature/AdminPremiumPlanManagementTest.php
git commit -m "feat: manage premium plans from admin"
```

### Task 3: Render Dynamic Benefits for Shop Owners

**Files:**
- Modify: `tests/Feature/PremiumFeatureTest.php`
- Modify: `resources/js/Pages/ShopOwner/Premium/premuimBenefits.tsx:15-23,496-528`

**Interfaces:**
- Consumes API plan field `benefits: string[]` returned by `PremiumCheckoutController::plans()`.
- Produces plan cards whose feature list is duration, slot capacity, then ordered backend benefits.

- [ ] **Step 1: Add a failing API visibility test**

Authenticate a retail shop owner, create one active and one archived plan with distinct benefits, request `/api/shop-owner/premium/plans`, and assert the active plan includes its benefits while the archived plan code is absent.

- [ ] **Step 2: Run the focused test**

Run: `php artisan test tests/Feature/PremiumFeatureTest.php --filter=plans_api_returns_active_plan_benefits_in_order`

Expected: FAIL because `PremiumCheckoutController::plans()` does not select `benefits`.

- [ ] **Step 3: Include benefits in the existing plan query**

In `app/Http/Controllers/ShopOwner/PremiumCheckoutController.php:707-709`, add `'benefits'` to the selected columns. Keep `where('status', 'active')` unchanged.

- [ ] **Step 4: Replace hard-coded plan bullets**

Add `benefits: string[];` to `PremiumPlan`. Keep the two generated bullets for duration and slot capacity, then render:

```tsx
{plan.benefits.map((benefit) => (
  <li key={benefit} className="flex items-start gap-3">
    {checkIcon}<span className="text-sm leading-snug text-black/65">{benefit}</span>
  </li>
))}
```

Remove only the two current hard-coded feature bullets; preserve checkout, upgrade, downgrade, and explanatory showroom content.

- [ ] **Step 5: Run backend and TypeScript/build checks**

Run: `php artisan test tests/Feature/PremiumFeatureTest.php --filter=plans_api_returns_active_plan_benefits_in_order`

Expected: PASS.

Run: `pnpm run build`

Expected: Vite build completes without TypeScript errors.

- [ ] **Step 6: Commit the dynamic plan cards**

```bash
git add app/Http/Controllers/ShopOwner/PremiumCheckoutController.php resources/js/Pages/ShopOwner/Premium/premuimBenefits.tsx tests/Feature/PremiumFeatureTest.php
git commit -m "feat: render editable premium benefits"
```

### Task 4: Add Plan Management UI to Subscription Management

**Files:**
- Modify: `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx:1-700`

**Interfaces:**
- Consumes Task 2 `plans` Inertia prop and four admin routes.
- Produces local `editingPlan: PremiumPlanItem|null`, `isPlanModalOpen: boolean`, and one `useForm<PlanForm>()` form.

- [ ] **Step 1: Define exact frontend types and form defaults**

Add `PremiumPlanItem`, extend `PageProps` with `plans`, and define:

```tsx
type PlanForm = {
  plan_code: string;
  name: string;
  description: string;
  price: string;
  duration_days: number;
  showroom_slot_limit: number;
  benefits: string[];
};

const emptyPlan: PlanForm = {
  plan_code: '', name: '', description: '', price: '',
  duration_days: 30, showroom_slot_limit: 48, benefits: [],
};
```

- [ ] **Step 2: Add plan cards above the existing metrics**

Render a `Plan Management` header with `Create Plan`, a responsive card grid, an archived badge, subscriber count, price/days/slots, ordered benefits, `Edit`, and `Archive` or `Reactivate`. Do not move or rewrite the existing subscription metrics/table/modal.

- [ ] **Step 3: Add the shared accessible create/edit modal**

Reuse `ModalPortal`. Disable plan code when editing. Use native number inputs with `min/max`, inline Inertia errors, benefit text inputs, `Add benefit`, `Remove`, `Move up`, and `Move down`. Show the exact capacity hint: `1–84: current room · 85–150: two connected rooms`.

- [ ] **Step 4: Wire submissions and confirmations**

Use `form.post('/admin/premium-plans')` for create and `form.put('/admin/premium-plans/{id}')` for edit. Use the existing SweetAlert pattern for archive/reactivate, preserve scroll, and reset/close only after success.

- [ ] **Step 5: Verify the compiled UI**

Run: `pnpm run build`

Expected: Vite build completes without TypeScript errors.

Manual check: open `/admin/subscription-management`; create a 100-slot plan with reordered benefits, edit it, archive it, reactivate it, and confirm the subscription table still filters and opens details.

- [ ] **Step 6: Commit the admin UI**

```bash
git add resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx
git commit -m "feat: add premium plan management UI"
```

### Task 5: Calculate Connected Room Distribution

**Files:**
- Create: `resources/js/Pages/UserSide/Products/showroomRooms.ts`
- Create: `resources/js/Pages/UserSide/Products/showroomRooms.test.ts`

**Interfaces:**
- Produces `getShowroomRooms(capacity: number): { start: number; count: number }[]`.
- Consumers use zero-based product slices: `shoes.slice(start, start + count)`.

- [ ] **Step 1: Write the failing boundary test**

```ts
import { describe, expect, it } from 'vitest';
import { getShowroomRooms } from './showroomRooms';

describe('getShowroomRooms', () => {
  it.each([
    [84, [{ start: 0, count: 84 }]],
    [85, [{ start: 0, count: 43 }, { start: 43, count: 42 }]],
    [100, [{ start: 0, count: 50 }, { start: 50, count: 50 }]],
    [150, [{ start: 0, count: 75 }, { start: 75, count: 75 }]],
  ])('splits %i slots', (capacity, expected) => {
    expect(getShowroomRooms(capacity as number)).toEqual(expected);
  });
});
```

- [ ] **Step 2: Run and confirm the missing-module failure**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Products/showroomRooms.test.ts`

Expected: FAIL because `./showroomRooms` does not exist.

- [ ] **Step 3: Implement the minimal pure helper**

```ts
export const getShowroomRooms = (capacity: number) => {
  const safeCapacity = Math.min(150, Math.max(1, Math.trunc(capacity)));
  if (safeCapacity <= 84) return [{ start: 0, count: safeCapacity }];

  const firstRoom = Math.ceil(safeCapacity / 2);
  return [
    { start: 0, count: firstRoom },
    { start: firstRoom, count: safeCapacity - firstRoom },
  ];
};
```

- [ ] **Step 4: Run the helper test**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Products/showroomRooms.test.ts`

Expected: PASS (4 cases).

- [ ] **Step 5: Commit the calculation**

```bash
git add resources/js/Pages/UserSide/Products/showroomRooms.ts resources/js/Pages/UserSide/Products/showroomRooms.test.ts
git commit -m "test: define connected showroom room split"
```

### Task 6: Integrate the Second Connected Showroom

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/VirtualShowroom.tsx:100-2117,2240-2520`
- Test: `resources/js/Pages/UserSide/Products/showroomRooms.test.ts`

**Interfaces:**
- Consumes `getShowroomRooms(showroomDisplayCapacity)` from Task 5.
- Produces `activeRoomIndex`, current room product slice, forward/return door interaction, and room HUD copy.

- [ ] **Step 1: Add room state without changing the <=84 path**

Compute `rooms` with `useMemo`, add `activeRoomIndex`, clamp/reset it when capacity changes, and derive `activeRoom`, `roomShoes`, `roomCapacity`, and `roomLayoutCapacity`. For two rooms, `roomLayoutCapacity` is always `rooms[0].count` so both rooms have identical dimensions even when the split is odd; for one room it equals `showroomDisplayCapacity`. When only one room exists, `roomShoes` must equal the existing `shoes` input.

- [ ] **Step 2: Scope product display meshes to the active room**

Replace only the product-placement loop's `shoes` source with `roomShoes`, use `roomCapacity` for its renderable count, and include those values in the effect dependencies. Use `roomLayoutCapacity` for room/shelf geometry when there are two rooms, making both rooms the same size. Keep room construction, shelf definitions, lighting, camera, controls, low-power cap, and all geometry thresholds exactly on the original `showroomDisplayCapacity` path when there is one room.

- [ ] **Step 3: Add doors only when `rooms.length === 2`**

Create one visible door mesh/sign on the far wall using the existing Three.js scene/material cleanup pattern. Clicking the room-1 door calls `setActiveRoomIndex(1)`; the room-2 return door calls `setActiveRoomIndex(0)`. After switching, reset the camera/controls to the existing entrance position so the user cannot spawn inside geometry. Do not create doors for capacities at or below 84.

- [ ] **Step 4: Add discoverable HUD navigation**

Show `Room 1 of 2 · Slots 1–43` or the calculated range, plus a keyboard-accessible `Go to Room 2`/`Return to Room 1` button that invokes the same state change as the 3D door. Preserve the existing total-capacity labels elsewhere.

- [ ] **Step 5: Run automated checks**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Products/showroomRooms.test.ts`

Expected: PASS.

Run: `pnpm run build`

Expected: Vite build completes without TypeScript errors.

- [ ] **Step 6: Run focused manual showroom checks**

Verify entitlements 84, 85, 100, and 150 using seeded/test subscriptions. At 84 confirm no door and unchanged placement. At 85 confirm 43/42 and both directions. At 100 confirm 50/50. At 150 confirm 75/75, no more than the entitled products appear, and switching rooms repeatedly does not duplicate meshes or event listeners.

- [ ] **Step 7: Commit the connected-room UI**

```bash
git add resources/js/Pages/UserSide/Products/VirtualShowroom.tsx
git commit -m "feat: add connected showroom for large plans"
```

### Task 7: Full Regression Verification

**Files:**
- Verify only; fix failures in the smallest owning file from Tasks 1–6.

**Interfaces:**
- Consumes every prior task's public routes, props, model fields, and helper.
- Produces a verified feature with no additional API surface.

- [ ] **Step 1: Run backend premium/admin tests**

Run: `php artisan test tests/Feature/AdminPremiumPlanManagementTest.php tests/Feature/PremiumFeatureTest.php`

Expected: PASS.

- [ ] **Step 2: Run the frontend suite**

Run: `pnpm run test:frontend`

Expected: PASS.

- [ ] **Step 3: Run the production build**

Run: `pnpm run build`

Expected: PASS.

- [ ] **Step 4: Verify migration reversibility on the test database**

Run: `php artisan migrate:fresh --seed --env=testing`

Expected: migrations and `PremiumPlanSeeder` complete successfully with benefits populated.

- [ ] **Step 5: Commit only if verification required a correction**

```bash
git add app database resources/js routes tests
git commit -m "fix: complete premium plan regressions"
```

Skip this commit when verification made no file changes.
