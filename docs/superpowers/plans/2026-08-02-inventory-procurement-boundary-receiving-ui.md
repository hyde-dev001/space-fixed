# Inventory–Procurement Boundary and Receiving UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make multi-size receiving inputs self-explanatory and prevent Inventory-only users from seeing or opening Procurement pages while preserving Supplier Orders receiving.

**Architecture:** Reuse the shared `PurchaseOrderReceiptPanel` and add visible labels beside its existing per-size inputs. Enforce the module boundary in three existing layers: sidebar visibility, web-route middleware/removal of Inventory aliases, and seeded/persisted role permissions. Keep the shared Procurement API permissions required for Inventory receiving.

**Tech Stack:** Laravel 12, Spatie Laravel Permission, Inertia, React 18, TypeScript, Vitest, Testing Library, Vite.

---

## File Map

- Modify `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx`: visibly identify every per-size received/defective input.
- Create `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx`: guard the multi-size receiving labels.
- Modify `resources/js/layout/AppSidebar_ERP.tsx`: stop treating Inventory Supplier Orders access as Procurement module access.
- Modify `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`: guard Inventory-only sidebar separation.
- Modify `routes/web.php`: remove Procurement page aliases from the Inventory group and enforce the matching permission on every Procurement page route.
- Modify `database/seeders/RolesAndPermissionsSeeder.php`: remove PR create/submit permissions from the Inventory Manager role.
- Create `database/migrations/2026_08_02_000006_remove_procurement_creation_permissions_from_inventory_manager_role.php`: remove stale role assignments in existing installations.
- Modify `tests/Feature/Procurement/ProcurementAuthorizationTest.php`: guard seeded permissions, existing-role cleanup, direct URL denial, and retained Supplier Orders access.
- Refresh `public/build/`: include the verified frontend changes for deployment.

### Task 1: Label Every Multi-Size Receiving Input

**Files:**
- Create: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx`

- [ ] **Step 1: Write the failing component test**

Render an in-transit PO item with eligible sizes `US 3` and `US 5`, then require visible labels for both received and defective inputs:

```tsx
it("shows a visible size label beside every multi-size receiving input", () => {
  render(
    <PurchaseOrderReceiptPanel
      order={multiSizeOrder}
      canReceive
      canVoid={false}
      onChanged={vi.fn()}
    />,
  );

  expect(screen.getAllByText("US 3")).toHaveLength(2);
  expect(screen.getAllByText("US 5")).toHaveLength(2);
  expect(screen.getByRole("spinbutton", { name: "Received Runner US 3" })).toBeInTheDocument();
  expect(screen.getByRole("spinbutton", { name: "Defective Runner US 3" })).toBeInTheDocument();
});
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
node node_modules/vitest/vitest.mjs run resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx
```

Expected: FAIL because the inputs have accessible names but no visible per-input size labels.

- [ ] **Step 3: Add the minimum visible labels**

Wrap each existing per-size input in a label without changing receipt state or payload construction:

```tsx
const sizeLabel = `${size.size_system ?? "US"} ${size.size}`;

return (
  <label key={size.id} className="flex items-center gap-2">
    <span className="w-12 shrink-0 text-xs font-medium text-gray-600 dark:text-gray-300">
      {sizeLabel}
    </span>
    <input aria-label={`Received ${item.product_name} ${sizeLabel}`} ... />
  </label>
);
```

Apply the same visible label wrapper to defective inputs. Do not alter quantity validation, accepted-stock math, idempotency, or Finance behavior.

- [ ] **Step 4: Run the component test and verify GREEN**

Run the Task 1 Vitest command again.

Expected: 1 test file passed.

- [ ] **Step 5: Commit**

```powershell
git add -- resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx
git commit -m "fix: label multi-size receiving inputs"
```

### Task 2: Hide Procurement Navigation from Inventory-Only Users

**Files:**
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`

- [ ] **Step 1: Write the failing sidebar test**

Make the sidebar test state configurable for role and permissions. Render an Inventory Manager with `view-inventory`, `access-supplier-order-monitoring`, `procurement.view`, and `procurement.receive_purchase_orders`.

```tsx
it("keeps Inventory Supplier Orders without exposing Procurement navigation", () => {
  state.role = "Inventory Manager";
  state.roles = ["Inventory Manager"];
  state.permissions = [
    "view-inventory",
    "access-supplier-order-monitoring",
    "procurement.view",
    "procurement.receive_purchase_orders",
  ];

  render(<AppSidebarERP />);

  expect(screen.getByRole("link", { name: /supplier orders/i })).toBeInTheDocument();
  expect(screen.queryByRole("link", { name: /purchase requests/i })).not.toBeInTheDocument();
  expect(screen.queryByRole("link", { name: /purchase orders/i })).not.toBeInTheDocument();
  expect(screen.queryByRole("link", { name: /suppliers management/i })).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run the sidebar test and verify RED**

```powershell
node node_modules/vitest/vitest.mjs run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
```

Expected: FAIL because `access-supplier-order-monitoring` currently activates the whole Procurement section.

- [ ] **Step 3: Fix the shared Procurement sidebar gate**

Remove only `access-supplier-order-monitoring` from `procurementPagePermissions` inside `hasProcurementAccess()`. Retain `PROCUREMENT MANAGER`, `view-procurement`, and the four explicit Procurement page permissions.

- [ ] **Step 4: Run the sidebar test and verify GREEN**

Run the Task 2 Vitest command again.

Expected: all sidebar tests passed.

- [ ] **Step 5: Commit**

```powershell
git add -- resources/js/layout/AppSidebar_ERP.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
git commit -m "fix: separate inventory and procurement navigation"
```

### Task 3: Enforce the Module Boundary on Routes and Existing Roles

**Files:**
- Modify: `routes/web.php`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Create: `database/migrations/2026_08_02_000006_remove_procurement_creation_permissions_from_inventory_manager_role.php`
- Modify: `tests/Feature/Procurement/ProcurementAuthorizationTest.php`

- [ ] **Step 1: Write failing authorization tests**

Extend the seeded-permission test:

```php
$this->assertTrue($inventory->hasPermissionTo('procurement.view'));
$this->assertTrue($inventory->hasPermissionTo('procurement.receive_purchase_orders'));
$this->assertFalse($inventory->hasPermissionTo('procurement.create_purchase_requests'));
$this->assertFalse($inventory->hasPermissionTo('procurement.submit_purchase_requests'));
```

Add a direct-page boundary test:

```php
public function test_inventory_manager_can_receive_supplier_orders_but_cannot_open_procurement_pages(): void
{
    $this->seed(RolesAndPermissionsSeeder::class);
    [$user] = $this->userForShop();
    $user->assignRole('Inventory Manager');

    $this->actingAs($user, 'user')
        ->get('/erp/inventory/supplier-order-monitoring')
        ->assertOk();

    foreach ([
        '/erp/procurement/purchase-request',
        '/erp/procurement/stock-request-approval',
        '/erp/procurement/purchase-orders',
        '/erp/procurement/suppliers-management',
    ] as $url) {
        $this->actingAs($user, 'user')->get($url)->assertForbidden();
    }

    foreach ([
        '/erp/inventory/purchase-request',
        '/erp/inventory/stock-request-approval',
        '/erp/inventory/purchase-orders',
        '/erp/inventory/suppliers-management',
    ] as $url) {
        $this->actingAs($user, 'user')->get($url)->assertNotFound();
    }
}
```

Add matching-page authorization tests:

```php
public function test_explicit_procurement_page_permission_does_not_unlock_sibling_pages(): void
{
    [$user] = $this->userForShop();
    $this->give($user, 'access-purchase-requests');

    $this->actingAs($user, 'user')
        ->get('/erp/procurement/purchase-request')
        ->assertOk();

    $this->actingAs($user, 'user')
        ->get('/erp/procurement/purchase-orders')
        ->assertForbidden();
}

public function test_procurement_manager_retains_all_procurement_pages(): void
{
    $this->seed(RolesAndPermissionsSeeder::class);
    [$user] = $this->userForShop();
    $user->assignRole('Procurement Manager');

    foreach ($this->procurementPageUrls() as $url) {
        $this->actingAs($user, 'user')->get($url)->assertOk();
    }
}
```

Add a migration test that grants the two stale PR permissions to the Inventory Manager role, executes the migration `up()`, and asserts both permissions were removed while `procurement.view` and `procurement.receive_purchase_orders` remain.

- [ ] **Step 2: Run the authorization test and verify RED**

```powershell
php artisan test tests/Feature/Procurement/ProcurementAuthorizationTest.php
```

Expected: FAIL on seeded Inventory permissions, Procurement direct access, Inventory aliases, and missing cleanup migration.

- [ ] **Step 3: Tighten new Inventory role assignments**

In `RolesAndPermissionsSeeder`, remove:

```php
'procurement.create_purchase_requests',
'procurement.submit_purchase_requests',
```

Keep `procurement.view` and `procurement.receive_purchase_orders` because Supplier Orders loads and receives POs through the shared Procurement API.

- [ ] **Step 4: Remove the unintended Inventory page aliases**

Delete the four Procurement page closures from the `/erp/inventory` group:

- `stock-request-approval`
- `purchase-request`
- `purchase-orders`
- `suppliers-management`

Keep `/erp/inventory/supplier-order-monitoring` unchanged.

- [ ] **Step 5: Enforce the matching permission on every Procurement page**

Keep only `auth:user` on the `/erp/procurement` route group. Add route-level permission middleware:

```php
// Purchase Request
->middleware('permission:view-procurement|access-purchase-requests')

// Purchase Orders
->middleware('permission:view-procurement|access-purchase-orders')

// Stock Request Approval
->middleware('permission:view-procurement|access-stock-request-approval')

// Suppliers Management
->middleware('permission:view-procurement|access-suppliers-management')
```

`view-procurement` remains the full-module gate used by Procurement Manager. An explicit page permission opens only its matching page. Do not include `access-supplier-order-monitoring`, `access-procurement-dashboard`, or API-only `procurement.view` in these page gates.

- [ ] **Step 6: Clean stale permissions in existing databases**

Create an idempotent data migration that:

1. Finds the `Inventory Manager` role for guard `user`.
2. Finds `procurement.create_purchase_requests` and `procurement.submit_purchase_requests`.
3. Deletes only their matching `role_has_permissions` rows.
4. Clears Spatie's permission cache.
5. Restores only those rows in `down()` with `insertOrIgnore`.

Do not remove direct, intentionally assigned user permissions.

- [ ] **Step 7: Run backend tests and verify GREEN**

```powershell
php artisan test tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php
```

Expected: all tests passed; Inventory receiving remains green.

- [ ] **Step 8: Commit**

```powershell
git add -- routes/web.php database/seeders/RolesAndPermissionsSeeder.php database/migrations/2026_08_02_000006_remove_procurement_creation_permissions_from_inventory_manager_role.php tests/Feature/Procurement/ProcurementAuthorizationTest.php
git commit -m "fix: enforce inventory procurement boundary"
```

### Task 4: Regression Verification and Deployment Build

**Files:**
- Refresh: `public/build/`

- [ ] **Step 1: Run the focused frontend suite**

```powershell
node node_modules/vitest/vitest.mjs run resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx
```

Expected: all focused tests passed.

- [ ] **Step 2: Run the focused backend suite**

```powershell
php artisan test tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php
```

Expected: all focused tests passed.

- [ ] **Step 3: Run the complete frontend suite**

```powershell
node node_modules/vitest/vitest.mjs run
```

Expected: all frontend test files passed.

- [ ] **Step 4: Build production assets**

```powershell
node node_modules/vite/bin/vite.js build
```

Expected: Vite exits 0 and refreshes `public/build/manifest.json` plus hashed assets.

- [ ] **Step 5: Inspect and commit the build**

```powershell
git status --short
git diff --check
git add -A -- public/build
git commit -m "build: refresh inventory receiving assets"
```

- [ ] **Step 6: Push the branch**

```powershell
git push --force-with-lease origin fix/procurement-practical-gaps
```

Use `--force-with-lease` because the branch was rebased onto the latest `solespace-b`; never use an unconditional force push.
