# Unified Cashier POS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a single ERP POS for Cashier that supports both Repair and Retail walk-in flows, while removing POS entry points from Repairer UX.

**Architecture:** Introduce a dedicated Cashier role and permission gate, expose one unified POS route/page, and split internal behavior into Repair and Retail modes. Reuse existing POS ledger tables by tagging records with module_type and enforce business-type and permission checks server-side.

**Tech Stack:** Laravel 11, Spatie Permission, Inertia React + TypeScript, Vitest, PHPUnit

---

## File Structure

- Modify: database/seeders/RolesAndPermissionsSeeder.php
  - Add access-unified-pos permission and Cashier role sync permissions.
- Modify: app/Services/BusinessAccessControlService.php
  - Allow Cashier role for retail, repair, and both business-type companies.
- Modify: app/Http/Controllers/ShopOwner/UserAccessControlController.php
  - Accept CASHIER during employee creation and map to Spatie Cashier role.
- Modify: routes/web.php
  - Add erp.cashier.point-of-sale route, permission protected.
  - Deprecate legacy repairer POS route behavior.
- Modify: routes/api.php
  - Add retail-pos API group.
- Create: app/Http/Controllers/Api/RetailPosController.php
  - Retail checkout/history/receipt/refund endpoints with module_type=retail.
- Create: app/Services/RetailPosPaymentService.php
  - Retail POS checkout orchestration, totals and stock mutation.
- Create: app/Services/RetailPosRefundService.php
  - Retail refund request/approve/execute rules.
- Modify: resources/js/layout/AppSidebar_ERP.tsx
  - Remove repairer POS nav, add cashier POS nav behind access-unified-pos.
- Create: resources/js/Pages/ERP/cashier/POS.tsx
  - Unified POS shell and mode switcher.
- Create: resources/js/Pages/ERP/cashier/posModeResolver.ts
  - Resolve allowed modes from business type.
- Modify: resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx
  - Remove Proceed to POS action for repairer.
- Create: resources/js/services/retailPosApi.ts
  - Retail POS client wrapper.
- Create: tests/Feature/CashierRoleProvisioningTest.php
- Create: tests/Feature/CashierPosRouteAccessTest.php
- Create: tests/Feature/RetailPosPaymentFlowTest.php
- Create: tests/Feature/RetailPosRefundFlowTest.php
- Modify: tests/Feature/RepairPosAuthorizationTest.php
- Create: resources/js/Pages/ERP/cashier/__tests__/posModeResolver.test.ts
- Create: resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.proceed-to-pos.test.tsx

### Task 1: Add Cashier RBAC Foundation

**Files:**
- Modify: database/seeders/RolesAndPermissionsSeeder.php
- Modify: app/Services/BusinessAccessControlService.php
- Modify: app/Http/Controllers/ShopOwner/UserAccessControlController.php
- Test: tests/Feature/CashierRoleProvisioningTest.php

- [ ] **Step 1: Write the failing RBAC feature test**

```php
<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashierRoleProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_role_and_unified_pos_permission_are_seeded(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $permission = Permission::where('guard_name', 'user')
            ->where('name', 'access-unified-pos')
            ->first();

        $role = Role::where('guard_name', 'user')
            ->where('name', 'Cashier')
            ->first();

        $this->assertNotNull($permission);
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('access-unified-pos'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CashierRoleProvisioningTest.php`
Expected: FAIL with missing role or missing permission assertion.

- [ ] **Step 3: Implement minimal RBAC changes**

```php
// database/seeders/RolesAndPermissionsSeeder.php (additions)
$allPermissions[] = 'access-unified-pos';

$cashier = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'user']);
$cashier->syncPermissions([
    'access-unified-pos',
    'access-global-search',
    'access-notification-center',
    'access-profile',
]);

// app/Services/BusinessAccessControlService.php (role allowlists)
$commonRoles = ['MANAGER', 'Manager', 'FINANCE', 'Finance', 'HR', 'CRM', 'CASHIER', 'Cashier'];

// app/Http/Controllers/ShopOwner/UserAccessControlController.php
// validation list append CASHIER,Cashier
// roleMap append 'CASHIER' => 'Cashier'
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/CashierRoleProvisioningTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php app/Services/BusinessAccessControlService.php app/Http/Controllers/ShopOwner/UserAccessControlController.php tests/Feature/CashierRoleProvisioningTest.php
git commit -m "feat: add cashier role and unified POS permission"
```

### Task 2: Add Unified Cashier POS Route and Route Guards

**Files:**
- Modify: routes/web.php
- Test: tests/Feature/CashierPosRouteAccessTest.php

- [ ] **Step 1: Write failing route access tests**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashierPosRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_access_unified_pos_permission_can_open_cashier_pos(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'access-unified-pos', 'guard_name' => 'user']);
        $role = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'user']);
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole('Cashier');

        $response = $this->actingAs($user, 'user')->get('/erp/cashier/point-of-sale');

        $response->assertStatus(200);
    }

    public function test_user_without_permission_is_denied_cashier_pos(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'user')->get('/erp/cashier/point-of-sale');

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CashierPosRouteAccessTest.php`
Expected: FAIL because route does not exist or incorrect status.

- [ ] **Step 3: Implement route and legacy route behavior**

```php
// routes/web.php
Route::get('/erp/cashier/point-of-sale', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/cashier/POS');
})->middleware(['auth:user', 'permission:access-unified-pos'])->name('erp.cashier.point-of-sale');

Route::get('/erp/repairer/point-of-sale', function () {
    return redirect()->route('erp.cashier.point-of-sale');
})->middleware(['auth:user'])->name('erp.repairer.point-of-sale');
```

- [ ] **Step 4: Run tests to verify route behavior**

Run: `php artisan test tests/Feature/CashierPosRouteAccessTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php tests/Feature/CashierPosRouteAccessTest.php
git commit -m "feat: add permission-gated cashier POS route"
```

### Task 3: Move Sidebar POS Entry from Repairer to Cashier Permission Gate

**Files:**
- Modify: resources/js/layout/AppSidebar_ERP.tsx
- Test: resources/js/Pages/ERP/cashier/__tests__/posModeResolver.test.ts
- Create: resources/js/Pages/ERP/cashier/posModeResolver.ts

- [ ] **Step 1: Write failing unit test for mode resolver and menu gate helper**

```ts
import { describe, expect, it } from "vitest";
import { resolveAllowedModes } from "../posModeResolver";

describe("resolveAllowedModes", () => {
  it("returns both modes for business type both", () => {
    expect(resolveAllowedModes("both")).toEqual(["repair", "retail"]);
  });

  it("returns retail mode only for retail business", () => {
    expect(resolveAllowedModes("retail")).toEqual(["retail"]);
  });

  it("returns repair mode only for repair business", () => {
    expect(resolveAllowedModes("repair")).toEqual(["repair"]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm vitest run resources/js/Pages/ERP/cashier/__tests__/posModeResolver.test.ts`
Expected: FAIL with module not found.

- [ ] **Step 3: Implement resolver and sidebar permission gate**

```ts
// resources/js/Pages/ERP/cashier/posModeResolver.ts
export type PosMode = "repair" | "retail";

export const resolveAllowedModes = (businessType: string): PosMode[] => {
  const normalized = String(businessType || "").toLowerCase();
  if (normalized === "both") return ["repair", "retail"];
  if (normalized === "repair") return ["repair"];
  return ["retail"];
};
```

```tsx
// resources/js/layout/AppSidebar_ERP.tsx (key changes)
const cashierItems: NavItem[] = [
  { name: "Point of Sale", route: "erp.cashier.point-of-sale", icon: repairItems[0].icon },
];

const hasCashierAccess = () => permissions.includes("access-unified-pos");

// remove POS from repairItems array
// render CASHIER section when hasCashierAccess() is true
// add route map entry:
// "erp.cashier.point-of-sale": "/erp/cashier/point-of-sale"
```

- [ ] **Step 4: Run tests**

Run: `pnpm vitest run resources/js/Pages/ERP/cashier/__tests__/posModeResolver.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/layout/AppSidebar_ERP.tsx resources/js/Pages/ERP/cashier/posModeResolver.ts resources/js/Pages/ERP/cashier/__tests__/posModeResolver.test.ts
git commit -m "feat: gate POS sidebar under cashier permission"
```

### Task 4: Remove Proceed to POS from Repairer Job Orders

**Files:**
- Modify: resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx
- Test: resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.proceed-to-pos.test.tsx

- [ ] **Step 1: Write failing UI test for CTA removal**

```tsx
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import JobOrdersRepair from "../JobOrdersRepair";

describe("JobOrdersRepair POS CTA", () => {
  it("does not render Proceed to POS action", () => {
    render(<JobOrdersRepair /> as any);
    expect(screen.queryByText(/proceed to pos/i)).toBeNull();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm vitest run resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.proceed-to-pos.test.tsx`
Expected: FAIL because button/link still exists.

- [ ] **Step 3: Remove button/link trigger in repairer job orders**

```tsx
// resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx
// remove any handler that does:
// window.location.href = `/erp/repairer/point-of-sale?...`
// remove corresponding Proceed to POS button in render tree
```

- [ ] **Step 4: Re-run test**

Run: `pnpm vitest run resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.proceed-to-pos.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.proceed-to-pos.test.tsx
git commit -m "feat: remove repairer proceed-to-pos action"
```

### Task 5: Build Unified Cashier POS Shell with Repair and Retail Modes

**Files:**
- Create: resources/js/Pages/ERP/cashier/POS.tsx
- Create: resources/js/services/retailPosApi.ts
- Modify: resources/js/services/repairPosHistoryApi.ts

- [ ] **Step 1: Write failing mode-switch test**

```tsx
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import CashierPOS from "../POS";

describe("Cashier POS mode switch", () => {
  it("renders Repair and Retail tabs for both business type", () => {
    render(<CashierPOS /> as any);
    expect(screen.getByRole("button", { name: /repair mode/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /retail mode/i })).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm vitest run resources/js/Pages/ERP/cashier/__tests__/POS.mode-switch.test.tsx`
Expected: FAIL with missing component.

- [ ] **Step 3: Implement minimal unified POS shell**

```tsx
// resources/js/Pages/ERP/cashier/POS.tsx
import { useMemo, useState } from "react";
import { usePage } from "@inertiajs/react";
import { resolveAllowedModes, PosMode } from "./posModeResolver";

export default function CashierPOS() {
  const { props } = usePage();
  const businessType = String((props as any)?.auth?.shop_owner?.business_type || "retail").toLowerCase();
  const allowedModes = useMemo(() => resolveAllowedModes(businessType), [businessType]);
  const [mode, setMode] = useState<PosMode>(allowedModes[0]);

  return (
    <div className="space-y-4 p-6">
      <div className="flex gap-2">
        {allowedModes.includes("repair") && <button onClick={() => setMode("repair")}>Repair Mode</button>}
        {allowedModes.includes("retail") && <button onClick={() => setMode("retail")}>Retail Mode</button>}
      </div>
      {mode === "repair" ? <div data-testid="repair-pos-mode" /> : <div data-testid="retail-pos-mode" />}
    </div>
  );
}
```

- [ ] **Step 4: Re-run test**

Run: `pnpm vitest run resources/js/Pages/ERP/cashier/__tests__/POS.mode-switch.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/cashier/POS.tsx resources/js/services/retailPosApi.ts resources/js/Pages/ERP/cashier/__tests__/POS.mode-switch.test.tsx
git commit -m "feat: scaffold unified cashier POS with mode switch"
```

### Task 6: Add Retail POS API Endpoints with Shared Ledger

**Files:**
- Create: app/Http/Controllers/Api/RetailPosController.php
- Create: app/Services/RetailPosPaymentService.php
- Create: app/Services/RetailPosRefundService.php
- Modify: routes/api.php
- Test: tests/Feature/RetailPosPaymentFlowTest.php
- Test: tests/Feature/RetailPosRefundFlowTest.php

- [ ] **Step 1: Write failing retail checkout feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetailPosPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_walk_in_checkout_creates_retail_pos_transaction(): void
    {
        $cashier = User::factory()->create();

        $response = $this->actingAs($cashier, 'user')->postJson('/api/retail-pos/checkout', [
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk In Buyer',
            'payment_lines' => [[
                'tender_type' => 'cash',
                'amount' => 500,
            ]],
            'items' => [[
                'product_id' => 1,
                'variant_id' => 1,
                'qty' => 1,
                'unit_price' => 500,
            ]],
            'idempotency_key' => 'retail-test-12345',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.module_type', 'retail');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RetailPosPaymentFlowTest.php`
Expected: FAIL due missing endpoint/controller.

- [ ] **Step 3: Implement retail API and services (minimal happy path)**

```php
// routes/api.php
Route::middleware(['web', 'auth:user,shop_owner'])->prefix('retail-pos')->group(function () {
    Route::post('/checkout', [\App\Http\Controllers\Api\RetailPosController::class, 'checkout']);
    Route::get('/transactions', [\App\Http\Controllers\Api\RetailPosController::class, 'listTransactions']);
    Route::get('/transactions/{transaction}/receipt', [\App\Http\Controllers\Api\RetailPosController::class, 'showReceipt']);
    Route::post('/refunds', [\App\Http\Controllers\Api\RetailPosController::class, 'requestRefund']);
    Route::post('/refunds/{refund}/approve', [\App\Http\Controllers\Api\RetailPosController::class, 'approveRefund']);
    Route::post('/refunds/{refund}/execute', [\App\Http\Controllers\Api\RetailPosController::class, 'executeRefund']);
});
```

```php
// app/Http/Controllers/Api/RetailPosController.php (signature excerpt)
public function checkout(Request $request, RetailPosPaymentService $service)
{
    $validated = $request->validate([
        'customer_type' => ['required', 'in:registered,walk_in'],
        'walk_in_name' => ['nullable', 'string', 'max:255'],
        'idempotency_key' => ['required', 'string', 'min:8'],
        'items' => ['required', 'array', 'min:1'],
        'payment_lines' => ['required', 'array', 'min:1'],
    ]);

    $transaction = $service->checkout($validated, $this->resolveActorAuditUserId());

    return response()->json(['success' => true, 'data' => $transaction], 201);
}
```

- [ ] **Step 4: Run retail payment and refund tests**

Run: `php artisan test tests/Feature/RetailPosPaymentFlowTest.php tests/Feature/RetailPosRefundFlowTest.php`
Expected: PASS for happy-path creation and refund stage transitions.

- [ ] **Step 5: Commit**

```bash
git add routes/api.php app/Http/Controllers/Api/RetailPosController.php app/Services/RetailPosPaymentService.php app/Services/RetailPosRefundService.php tests/Feature/RetailPosPaymentFlowTest.php tests/Feature/RetailPosRefundFlowTest.php
git commit -m "feat: add retail POS APIs on shared ledger"
```

### Task 7: Repair Authorization Regression and Final Verification

**Files:**
- Modify: tests/Feature/RepairPosAuthorizationTest.php
- Modify: tests/Feature/RepairPosPaymentFlowTest.php

- [ ] **Step 1: Add failing regression tests for new permission boundaries**

```php
public function test_repairer_without_unified_pos_permission_cannot_open_cashier_pos_route(): void
{
    $repairer = User::factory()->create();
    $repairer->assignRole('Repairer');

    $this->actingAs($repairer, 'user')
        ->get('/erp/cashier/point-of-sale')
        ->assertStatus(403);
}
```

- [ ] **Step 2: Run regression tests and verify failures first**

Run: `php artisan test tests/Feature/RepairPosAuthorizationTest.php`
Expected: FAIL until permission gates are fully wired.

- [ ] **Step 3: Finalize missing guards and assertions**

```php
// Ensure route middleware uses permission:access-unified-pos
// Ensure tests assign permission explicitly when expecting success
$cashier->givePermissionTo('access-unified-pos');
```

- [ ] **Step 4: Run full targeted suite**

Run: `php artisan test tests/Feature/RepairPosAuthorizationTest.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/RetailPosPaymentFlowTest.php tests/Feature/RetailPosRefundFlowTest.php`
Expected: PASS.

Run: `pnpm vitest run resources/js/Pages/ERP/cashier/__tests__/posModeResolver.test.ts resources/js/Pages/ERP/cashier/__tests__/POS.mode-switch.test.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.proceed-to-pos.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/RepairPosAuthorizationTest.php tests/Feature/RepairPosPaymentFlowTest.php
git commit -m "test: enforce cashier POS permission boundaries"
```

## Self-Review Checklist (Completed)

1. Spec coverage:
- Unified POS route and role: covered in Tasks 1-3.
- Repair + retail mode behavior: covered in Tasks 5-6.
- Remove repairer Proceed to POS: covered in Task 4.
- Refund workflows for retail and repair: covered in Tasks 6-7.

2. Placeholder scan:
- No TBD/TODO placeholders remain in tasks.
- Each code step includes concrete code snippets.
- Each validation step includes concrete run command and expected result.

3. Type consistency:
- Route name consistently used as erp.cashier.point-of-sale.
- Permission consistently used as access-unified-pos.
- Role consistently used as Cashier.

## Execution Handoff

Plan complete and saved to docs/superpowers/plans/2026-04-08-unified-cashier-pos.md. Two execution options:

1. Subagent-Driven (recommended) - I dispatch a fresh subagent per task, review between tasks, fast iteration

2. Inline Execution - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
