# Inventory Auto Stock Request Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move automatic low-stock requests into the canonical stock-request workflow and expose safe per-item automation settings in Manage Stock Items.

**Architecture:** Add one persisted enable flag to `inventory_items`, reuse `reorder_level` and `reorder_quantity`, and make `StockRequestApprovalService` the shared creation path for manual and automatic requests. Preserve existing alert/coverage logic and legacy replenishment records, while new automatic records use `stock_request_approvals` and carry `is_auto_generated` metadata. Allow `requested_by` to be null for system-generated requests so the UI can identify `System` rather than a fake human.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, PHPUnit, Inertia/React, TypeScript, Vite, Vitest.

---

### Task 1: Add the per-item automation contract

**Files:**
- Create: `database/migrations/2026_09_07_000001_add_auto_stock_request_enabled_to_inventory_items.php`
- Create: `database/migrations/2026_09_07_000002_allow_system_stock_request_requester.php`
- Modify: `app/Models/InventoryItem.php`
- Modify: `resources/js/types/inventory.ts`
- Test: `tests/Feature/Inventory/AutoStockRequestSettingsTest.php`

- [ ] **Step 1: Write failing settings tests**
  - Verify the default is disabled, valid updates persist, zero/negative request quantities are rejected, and a different shop cannot update the item.
- [ ] **Step 2: Run the focused test and confirm failure**
  - Run `php artisan test tests/Feature/Inventory/AutoStockRequestSettingsTest.php --colors=never`.
- [ ] **Step 3: Add the boolean migration/model cast/fillable and frontend type**
  - Keep `reorder_quantity` as the canonical request amount.
- [ ] **Step 4: Run the focused settings test**
  - Expected: PASS.

### Task 2: Enforce item write authorization and expose settings in Manage Stock Items

**Files:**
- Modify: `app/Http/Controllers/Erp/UploadInventoryController.php`
- Modify: `resources/js/Pages/ERP/inventory/UploadInventory.tsx`
- Modify: `resources/js/services/inventoryAPI.ts` (only if the existing type contract requires it)
- Test: `tests/Feature/Inventory/AutoStockRequestSettingsTest.php`

- [ ] **Step 1: Add authorization regression coverage**
  - Verify create/update item writes require the existing `InventoryItemPolicy` abilities and remain tenant-scoped.
- [ ] **Step 2: Run the authorization tests and confirm failure**
  - Run the focused feature test with the new test names.
- [ ] **Step 3: Authorize `create`/`update` and related item writes consistently**
  - Preserve category/business-type checks and existing owner/employee context.
- [ ] **Step 4: Add the existing modal controls**
  - Initialize settings from the API, validate whole numbers client-side, submit `auto_stock_request_enabled`, `reorder_level`, and `reorder_quantity`, and hide only request quantity when disabled.
- [ ] **Step 5: Run backend tests and frontend tests/build**
  - Run `php artisan test tests/Feature/Inventory/AutoStockRequestSettingsTest.php --colors=never` and the focused Vitest test.

### Task 3: Centralize canonical Stock Request creation

**Files:**
- Modify: `app/Models/StockRequestApproval.php`
- Modify: `app/Services/StockRequestApprovalService.php`
- Modify: `app/Http/Controllers/Erp/StockRequestApprovalController.php`
- Test: `tests/Feature/Procurement/AutoStockRequestWorkflowTest.php`

- [ ] **Step 1: Write failing service/workflow tests**
  - Verify manual creation still works, automatic creation writes `stock_request_approvals`, uses the correct shop/item/quantity/priority/status/source metadata, and notifies Procurement with Finance fallback.
- [ ] **Step 2: Run the focused tests and confirm failure**
  - Run `php artisan test tests/Feature/Procurement/AutoStockRequestWorkflowTest.php --colors=never`.
- [ ] **Step 3: Add the smallest shared creation method**
  - Generate the request number inside the service, preserve validation at the HTTP boundary, require `requested_by` for manual requests, allow null only for automatic requests, and mark automatic records with `is_auto_generated`.
- [ ] **Step 4: Route manual controller creation through the service**
  - Preserve per-size/manual/repair validation before the service call.
- [ ] **Step 5: Run focused procurement tests**
  - Expected: PASS for new and existing manual workflow coverage.

### Task 4: Switch low-stock automation to the canonical table without losing coverage/idempotency

**Files:**
- Modify: `app/Jobs/CheckLowStockJob.php`
- Modify: `tests/Unit/CheckLowStockJobTest.php`
- Modify: `tests/Feature/Procurement/AutoStockRequestWorkflowTest.php`

- [ ] **Step 1: Add failing ON/OFF, coverage, retry, and terminal-status tests**
  - Automation OFF creates alerts but no Stock Request; ON creates one canonical request; existing canonical incoming requests reduce demand; rejected/cancelled/closed records do not count; repeated handling does not duplicate.
- [ ] **Step 2: Run focused job tests and confirm failure**
  - Run `php artisan test tests/Unit/CheckLowStockJobTest.php tests/Feature/Procurement/AutoStockRequestWorkflowTest.php --colors=never`.
- [ ] **Step 3: Use the enabled flag and canonical stock-request quantities in the job**
  - Keep legacy replenishment rows in coverage for historical compatibility, but do not create new ones.
- [ ] **Step 4: Add transaction/locking protection at the smallest shared boundary**
  - Lock the inventory item and re-check covered demand while creating the canonical request so queue retries/concurrent workers cannot create duplicate requests for the same item.
- [ ] **Step 5: Run all low-stock/procurement regressions**
  - Expected: PASS.

### Task 5: Show automatic source/reason in the existing Stock Requests UI

**Files:**
- Modify: `resources/js/types/procurement.ts`
- Modify: `resources/js/Pages/ERP/inventory/StockRequest.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/StockRequestApproval.tsx` (only if the shared detail does not already expose it)
- Test: `resources/js/Pages/ERP/inventory/__tests__/StockRequest.test.tsx`

- [ ] **Step 1: Add a failing rendering assertion**
  - Automatic requests render `Source: Automatic Stock Request` and `Reason: Stock reached reorder level` without fake requester attribution.
- [ ] **Step 2: Run the focused frontend test and confirm failure**
  - Run `pnpm exec vitest run resources/js/Pages/ERP/inventory/__tests__/StockRequest.test.tsx`.
- [ ] **Step 3: Add only the source/reason fields and render them in the existing detail pattern**
- [ ] **Step 4: Run the focused frontend test**
  - Expected: PASS.

### Task 6: Verify, review, and record the result

**Files:**
- Modify: `docs/ai-learning-log.md` only if a durable project lesson is found.

- [ ] **Step 1: Run PHP formatting/syntax and focused tests**
- [ ] **Step 2: Run `git diff --check` and frontend build**
  - `pnpm run build` produces the fresh `public/build` requested by the project workflow.
- [ ] **Step 3: Perform sequential standards/spec/security/simplification/dead-code review**
- [ ] **Step 4: Report exact files, tests, scheduler/queue assumptions, and any legacy reconciliation limitation**
