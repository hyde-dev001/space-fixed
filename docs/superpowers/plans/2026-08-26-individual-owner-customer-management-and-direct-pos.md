# Individual Owner Customer Management and Direct POS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Restore the Individual owner's customer-management workspace and make Cashier open the unified Retail/Repair POS directly, while leaving the company owner shell and both dashboards unchanged.

**Architecture:** Reuse the existing CRM module landing/tabs and owner-scoped route contract instead of adding a second customer-management surface. Reuse the existing unified `ERP/cashier/POS` page at the canonical Cashier route, so Retail and Repair remain selectable inside the POS. Keep company navigation on its current module tabs and direct operations.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript, PHPUnit/Pest-style Laravel feature tests, Vitest, Tailwind CSS.

---

### Task 1: Lock the approved behavior with regression tests

**Files:**
- Modify: `tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`

- [ ] **Step 1: Write failing assertions for Individual customer navigation and direct Cashier rendering.**
  - Assert an Individual owner with CRM, retail, and repair access receives `customers` in the operate group.
  - Assert the company owner still receives the existing `retail`, `repair`, and `customers` operation items without Individual-only tabs.
  - Assert Individual CRM tabs include Customers, Customer Reviews, and Customer Support.
  - Assert `/shop-owner/operate/payments` renders `ERP/cashier/POS`, not `ShopOwner/Payments/CanonicalPaymentsLanding`.

- [ ] **Step 2: Run only the new/affected tests and verify they fail for the current implementation.**
  - Run `php artisan test tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php` from the target worktree.

### Task 2: Restore Individual CRM page contracts without widening company access

**Files:**
- Modify: `config/shop_modules.php`
- Reuse: `app/Services/ErpRouteCatalog.php`
- Reuse: `app/Services/ErpWorkspaceNavigationService.php`

- [ ] **Step 1: Enable the existing CRM module for Individual owners.**
  - Add `individual` to the CRM module registration types.
  - Preserve CRM business-type restrictions and the company module behavior.

- [ ] **Step 2: Restore the existing Customer Support page as an Individual-only owner tab.**
  - Keep its shared `navigation_visible` value false so company tabs do not change.
  - Set owner access, Individual registration/business types, server-resolved owner persistence, and owner-only navigation visibility.
  - Leave the already scoped CRM read APIs and customer-support conversation APIs tenant/owner constrained.

- [ ] **Step 3: Run CRM route and navigation feature tests.**
  - Confirm the three Individual customer tabs render and company behavior remains unchanged.

### Task 3: Add the Individual Customer Management sidebar destination

**Files:**
- Modify: `app/Services/OwnerShell/CanonicalOwnerShellService.php`
- Modify: `tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php`

- [ ] **Step 1: Include the existing `customers` destination in the Individual operate group when CRM is eligible.**
  - Reuse `shop-owner.shell.operate.customers` and the existing CRM module landing route.
  - Do not add children to the canonical shell item; the module page owns its tabs.

- [ ] **Step 2: Verify Individual and company group composition.**
  - Individual: Home, Approval Center when enabled, Operate with Retail/Repair/Customers/Cashier as eligible.
  - Company: existing Oversee, Operate, Reports & Audit ordering and items remain unchanged.

### Task 4: Make Cashier open the unified POS directly

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/CanonicalOwnerPaymentsController.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`
- Reuse: `resources/js/Pages/ERP/cashier/POS.tsx`

- [ ] **Step 1: Render the existing unified POS component from `/shop-owner/operate/payments`.**
  - Return `Inertia::render('ERP/cashier/POS')` with the ERP mode prop expected by the page if required.
  - Remove the intermediate Retail Point of Sale / Repair Point of Sale chooser from this canonical route.
  - Preserve route authorization and Individual-only Cashier access.

- [ ] **Step 2: Verify the POS page exposes the existing internal Retail/Repair selector.**
  - Keep mode resolution based on the authenticated owner's business type.
  - Do not alter company POS routes or the existing repair/retail source routes.

### Task 5: Full focused verification and review

**Files:**
- Review changed files and nearby route/navigation tests.

- [ ] **Step 1: Run backend focused tests.**
  - `php artisan test tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`

- [ ] **Step 2: Run frontend checks for changed UI contracts.**
  - `pnpm run test:frontend`
  - `pnpm run build`

- [ ] **Step 3: Verify browser-visible behavior.**
  - Log in as Individual and confirm Customer Management tabs and direct unified Cashier.
  - Confirm company navigation and POS entry points remain unchanged.

- [ ] **Step 4: Run hygiene and review checks.**
  - `git diff --check`
  - Inspect the diff for stale chooser references, dead imports, unauthorized company exposure, and accidental dashboard changes.
