# Retail POS Stock and Variant Replenishment Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Retail POS stock reads, linked-variant checkout, replenishment targets, alerts, incoming coverage, notifications, and inventory image paths agree with the existing canonical inventory model.

**Architecture:** Keep `InventoryItem`/`InventoryColorVariant`/`InventorySize` as the source of truth for linked products. Add the smallest shared target-resolution path to `InventoryCheckoutService`; `RetailPosController` and `RetailPosPaymentService` use it for reads and writes, while `CheckLowStockJob` and `StockRequestApprovalService` keep the existing variant-aware replenishment workflow. The POS continues its existing post-checkout/Refresh fetch, and the product-level stock value is a display-only aggregate.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent transactions and row locks, PHPUnit, React 18, TypeScript 5.7, Vitest, Testing Library, Vite, pnpm.

---

## File map

- `app/Http/Controllers/Api/RetailPosController.php` — validate the optional catalog/inventory target identifiers and serialize canonical linked-product stock.
- `app/Services/InventoryCheckoutService.php` — shared catalog-to-inventory target resolution, exact target validation, live availability, and locked deduction.
- `app/Services/RetailPosPaymentService.php` — pass the selected catalog variant and inventory identifiers through the existing atomic checkout.
- `resources/js/Pages/ERP/cashier/POS.tsx` — retain target IDs in catalog/cart state, display selected-target stock, and include IDs in checkout payloads.
- `app/Services/InventoryReplenishmentService.php` — enforce per-color target enumeration and parent ownership during settings/target resolution.
- `app/Jobs/CheckLowStockJob.php` — enforce one active stock-condition class per target and target-only recovery/deduplication.
- `app/Services/StockRequestApprovalService.php` — preserve exact target identity and serialize target coverage/request creation under the existing item lock.
- `app/Models/InventoryImage.php`, `app/Http/Controllers/Erp/ProductInventoryController.php`, and `app/Http/Controllers/Erp/UploadInventoryController.php` — normalize public-disk paths and omit missing assets where the existing API already sanitizes images.
- `resources/js/Pages/ERP/inventory/UploadInventory.tsx`, `resources/js/Pages/ERP/inventory/ProductInventory.tsx`, and `resources/js/types/inventory.ts` — consume canonical image URLs without double-prefixing storage paths.
- `tests/Feature/RetailPosPaymentFlowTest.php` — linked POS listing/checkout regressions.
- `resources/js/Pages/ERP/cashier/__tests__/POS.retail-pagination.test.tsx` — extend the existing POS test harness for exact selected stock and refreshed catalog behavior.
- `tests/Unit/Services/InventoryReplenishmentServiceTest.php`, `tests/Feature/InventoryReplenishmentSettingsTest.php`, and `tests/Unit/CheckLowStockJobTest.php` — target enumeration, settings, state transitions, alerts, and request coverage.
- `tests/Feature/Procurement/ProcurementConcurrencyTest.php` and `tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php` — concurrency-safe automatic requests and procurement identity coverage.
- `tests/Feature/Notifications/InventoryNotificationTest.php` — queued alert/request recipient routing and variant context.
- `tests/Feature/ProductInventoryTest.php` — image URL/path regression if the existing API behavior is changed.

No migration, replenishment table, dependency, fake `ALL` variant, or second POS endpoint is planned.

## Execution rules

- Use `@superpowers:test-driven-development`, `@laravel-best-practices`, `@security-review`, `@vercel-react-best-practices`, `@typescript-advanced-types` only where complex types require it, `@karpathy-guidelines`, and `@ponytail` while implementing.
- Run CodeGraph before editing each application area, then use the narrowest failing test as the guide.
- The worktree already contains unrelated staged changes. Before every commit, inspect `git diff --cached --stat` and commit only the listed task paths with `git commit --only ... -- <paths>`; never stage or reset the unrelated changes.
- Do not edit `.env`, `vendor/`, `node_modules/`, or generated `public/build` files.

### Task 1: Establish the regression contract

**Files:**

- Test: `tests/Feature/RetailPosPaymentFlowTest.php`
- Test: `resources/js/Pages/ERP/cashier/__tests__/POS.retail-pagination.test.tsx`

- [ ] **Step 1: Record the clean scope boundary.**

Run:

```bash
git status --short
git diff --cached --stat
```

Expected: the existing unrelated staged changes remain visible and are not touched.

- [ ] **Step 2: Run the current focused baseline.**

Run:

```bash
php artisan test tests/Unit/CheckLowStockJobTest.php tests/Unit/Services/InventoryReplenishmentServiceTest.php tests/Feature/InventoryReplenishmentSettingsTest.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Feature/RetailPosPaymentFlowTest.php
pnpm exec vitest run resources/js/Pages/ERP/cashier/__tests__/POS.retail-pagination.test.tsx
```

Expected: the current suites pass; record any environment-specific skips before adding regressions.

- [ ] **Step 3: Add failing Laravel regressions.**

In `RetailPosPaymentFlowTest.php`, add fixtures with one linked product containing Black/US 8 = 4, Black/US 9 = 12, and White/US 8 = 20. Add tests that:

1. `GET /api/retail-pos/products` returns each exact live variant quantity, its `inventory_color_variant_id`/`inventory_size_id`, and a product-level aggregate of 36 that is display-only;
2. a successful sale of two Black/US 8 units followed by the same listing request returns 2 for that exact target;
3. a checkout that submits a valid same-product Black/US 9 ID while selecting Black/US 8 is rejected and creates no order, movement, or invoice; and
4. the existing insufficient-stock rollback assertion remains intact when the canonical target IDs are included.

In the existing POS test harness, add a variant fixture and assertions that selecting Black/US 8 shows 4, switching to Black/US 9 shows 12, and the checkout/refetch path consumes the refreshed catalog response. Keep the cart label semantics as database stock plus a separate cart quantity.

- [ ] **Step 4: Run only the new tests to confirm they fail for the current reasons.**

Run:

```bash
php artisan test tests/Feature/RetailPosPaymentFlowTest.php --filter='linked|canonical|inventory.*id|listing'
pnpm exec vitest run resources/js/Pages/ERP/cashier/__tests__/POS.retail-pagination.test.tsx
```

Expected: failures show missing canonical listing fields/refresh or missing wrong-target rejection; do not weaken the assertions to match stale behavior.

## Task 2: Implement the canonical POS target contract

**Files:**

- Modify: `app/Http/Controllers/Api/RetailPosController.php:20-121`
- Modify: `app/Services/InventoryCheckoutService.php:15-`
- Modify: `app/Services/RetailPosPaymentService.php:25-`
- Test: `tests/Feature/RetailPosPaymentFlowTest.php`

- [ ] **Step 1: Add the request boundary fields.**

Validate an optional catalog `variant_id` and nullable integer `inventory_color_variant_id`/`inventory_size_id` on each item. Keep the existing product/shop authorization and all current checkout validation. The service must still support legacy payloads that only carry normalized size/color strings.

- [ ] **Step 2: Add one shared target-resolution path.**

Keep the logic in `InventoryCheckoutService` unless the current class cannot share it without unrelated coupling. Resolve a linked catalog variant from the shop-scoped product and selected `variant_id` (using normalized strings only for legacy requests), then derive the authoritative inventory target. Client inventory IDs are cross-checks only:

- a size ID must belong to the linked item and the exact derived color parent/size;
- a color ID must belong to the linked item and exact selected color target;
- a linked variant that cannot resolve is returned as zero/unavailable and cannot fall back to `ProductVariant.quantity`;
- an unlinked product continues using current product/`ProductVariant` quantities.

Return the target IDs and live quantity for listing, and accept the same resolved target in `availableForCheckout()`/`deduct()`. Lock and re-read the parent and child row inside the existing checkout transaction before validating quantity and decrementing it. Do not add a second stock mirror write.

- [ ] **Step 3: Make the product listing canonical without N+1 queries.**

In `listProducts()`, keep the current shop/search/active/limit behavior, load the linked inventory items and their color/size children for the returned product IDs, and map each catalog variant to its exact target quantity and IDs. Set linked product `stock_quantity` to the sum of effective target quantities (or the parent quantity when no variants exist); never use that aggregate for variant checkout authorization.

- [ ] **Step 4: Pass target identity through payment processing.**

In `RetailPosPaymentService`, use the selected catalog variant and target IDs for both availability and deduction. Preserve the current transaction boundary for order/receipt, stock movement, and Finance posting. A mismatch or insufficient target must throw the existing 422 path before any persistent side effect; retain idempotency behavior.

- [ ] **Step 5: Run the POS backend regressions.**

Run:

```bash
php artisan test tests/Feature/RetailPosPaymentFlowTest.php --filter='linked|canonical|inventory.*id|listing'
```

Expected: PASS, including exact 4/12/20 reads, post-sale 2, wrong same-product ID rejection, and unchanged rollback behavior.

- [ ] **Step 6: Commit only this task’s files.**

```bash
git commit --only -m "fix: use canonical inventory targets in retail POS" -- app/Http/Controllers/Api/RetailPosController.php app/Services/InventoryCheckoutService.php app/Services/RetailPosPaymentService.php tests/Feature/RetailPosPaymentFlowTest.php
```

## Task 3: Wire exact target identity into the POS UI

**Files:**

- Modify: `resources/js/Pages/ERP/cashier/POS.tsx:130-190,875-930,2360-2580,2760-2880,3140-3220`
- Test: `resources/js/Pages/ERP/cashier/__tests__/POS.retail-pagination.test.tsx`

- [ ] **Step 1: Extend the failing UI test fixture.**

Mock the API response with the canonical snake-case target IDs and quantities. Assert that the selected variant’s stock text changes synchronously when color/size changes, and that the existing successful-payment refetch requests the catalog again. Assert that the checkout request contains `variant_id` plus the exact inventory IDs.

- [ ] **Step 2: Map and retain canonical IDs.**

Extend `RetailProductVariant` and `RetailCartItem` with nullable inventory target IDs. Map API rows without renaming or dropping the IDs, preserve them when adding/updating a cart line, and use the selected target’s `stock` for the product card. Keep cart quantity independent from the stock label.

- [ ] **Step 3: Submit exact IDs and preserve existing refresh behavior.**

Add `variant_id`, `inventory_color_variant_id`, and `inventory_size_id` to each checkout item when present. Keep the existing `fetchRetailProducts(retailSearch)` after success and the existing Refresh button; do not add polling, a second endpoint, or client-side authority.

- [ ] **Step 4: Run the focused frontend test.**

Run:

```bash
pnpm exec vitest run resources/js/Pages/ERP/cashier/__tests__/POS.retail-pagination.test.tsx
```

Expected: PASS, including the existing pagination assertions and the new exact-stock/ID/refetch assertions.

- [ ] **Step 5: Commit only the UI files.**

```bash
git commit --only -m "fix: refresh exact retail POS variant stock" -- resources/js/Pages/ERP/cashier/POS.tsx resources/js/Pages/ERP/cashier/__tests__/POS.retail-pagination.test.tsx
```

## Task 4: Harden effective target ownership and settings persistence

**Files:**

- Modify: `app/Services/InventoryReplenishmentService.php:19-`
- Modify: `app/Services/StockRequestApprovalService.php:71-180`
- Test: `tests/Unit/Services/InventoryReplenishmentServiceTest.php`
- Test: `tests/Feature/InventoryReplenishmentSettingsTest.php`

- [ ] **Step 1: Add failing target-boundary tests.**

Cover a mixed shape where Black has size rows and White is color-only, plus a size row whose `inventory_color_variant_id` belongs to another item. Assert that enumeration produces Black sizes and White’s color target, and that settings/automatic target resolution rejects the foreign-parent row without partially updating any target.

Retain the existing save/reopen assertions for `auto_stock_request_enabled`, `reorder_level`, and `reorder_quantity` on every actual size/color target.

- [ ] **Step 2: Implement per-color enumeration and ownership checks.**

Keep `effectiveTargets()` as the single enumerator: sizes for colors that have them, color rows for colors without sizes, and the parent only when there are no color targets. In both settings update and automatic target resolution, require the child item and parent color to match; reject standalone color settings when that color has size rows. Preserve the existing authorization and transaction rollback.

- [ ] **Step 3: Run the settings/target tests.**

```bash
php artisan test tests/Unit/Services/InventoryReplenishmentServiceTest.php tests/Feature/InventoryReplenishmentSettingsTest.php
```

Expected: PASS with no new migration or serializer changes unless a test identifies a real response-shape gap.

- [ ] **Step 4: Commit only the target files.**

```bash
git commit --only -m "fix: validate inventory replenishment target ownership" -- app/Services/InventoryReplenishmentService.php app/Services/StockRequestApprovalService.php tests/Unit/Services/InventoryReplenishmentServiceTest.php tests/Feature/InventoryReplenishmentSettingsTest.php
```

## Task 5: Make alert classes and automatic requests target-safe

**Files:**

- Modify: `app/Jobs/CheckLowStockJob.php:33-240`
- Modify: `app/Services/StockRequestApprovalService.php:71-320`
- Test: `tests/Unit/CheckLowStockJobTest.php`
- Test: `tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php`
- Test: `tests/Feature/Procurement/ProcurementConcurrencyTest.php`

- [ ] **Step 1: Add failing alert-transition tests.**

In `CheckLowStockJobTest.php`, add:

- low-to-out transition: resolve low stock before creating out-of-stock;
- out-to-low transition: resolve out-of-stock before creating low stock;
- above-reorder recovery that changes only the matching variant;
- repeated scans that leave one unresolved condition alert; and
- mixed-shape and separate-variant cases proving unrelated colors/sizes remain untouched.

Keep the automation-off assertion: alerts are allowed, automatic requests are not.

- [ ] **Step 2: Add exact coverage and concurrency tests.**

Use Black/US 8 as the target and create unrelated Black/US 9 and White/US 8 incoming records across open stock requests, legacy replenishment requests, purchase requests, purchase orders, and received quantities. Assert only matching, still-open/unreceived units reduce `quantity_needed`.

Extend `ProcurementConcurrencyTest` with the repository’s existing MySQL/`pcntl_fork` gate. Run two `CheckLowStockJob` instances for one low target and assert one automatic request only; SQLite remains an intentional skip. The request must use `quantity_needed`, `status = 'pending'`, `is_auto_generated = true`, the existing compatible `request_source`, exact `requested_color`/normalized `requested_size`, and the existing automatic-replenishment note/source label.

- [ ] **Step 3: Implement one active condition class per target.**

Inside each target-locked alert transaction, re-read quantity/settings and resolve the opposite condition before creating the current low/out alert. A normal target resolves both condition classes for that target only. Keep alert identity as shop + item + color target ID + size target ID + alert type.

- [ ] **Step 4: Make request creation serialize coverage and dedupe.**

Keep the existing parent-item lock in `StockRequestApprovalService`, validate the exact target again, then recompute all incoming coverage while holding the lock before persisting only the uncovered quantity. Existing open requests count as coverage; overlapping scans therefore cannot both observe zero coverage. Do not change the table or invent a request-level alert identity.

- [ ] **Step 5: Run the replenishment/procurement tests.**

```bash
php artisan test tests/Unit/CheckLowStockJobTest.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Feature/Procurement/ProcurementConcurrencyTest.php
```

Expected: PASS, with the concurrency test either passing on MySQL+pcntl or reporting its existing environment skip.

- [ ] **Step 6: Commit only the alert/request files.**

```bash
git commit --only -m "fix: deduplicate target-specific stock alerts and requests" -- app/Jobs/CheckLowStockJob.php app/Services/StockRequestApprovalService.php tests/Unit/CheckLowStockJobTest.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Feature/Procurement/ProcurementConcurrencyTest.php
```

## Task 6: Verify queued notification routing and variant context

**Files:**

- Modify if required: `app/Listeners/SendLowStockNotification.php`
- Modify if required: `app/Listeners/SendOutOfStockNotification.php`
- Modify if required: `app/Notifications/LowStockNotification.php`
- Modify if required: `app/Notifications/OutOfStockNotification.php`
- Modify if required: existing stock-request notification path in `app/Services/StockRequestApprovalService.php`
- Create: `tests/Feature/Notifications/InventoryNotificationTest.php`

- [ ] **Step 1: Add recipient and context tests.**

Use `Notification::fake()` and real shop-scoped users to prove low/out-of-stock notifications reach only same-shop users with `inventory.view`, include product/color/size/remaining context, and remain queued. Prove automatic stock-request notifications select the shop’s Procurement Manager and use Finance only when no Procurement Manager exists; unrelated roles and other shops receive nothing.

- [ ] **Step 2: Fix only the failing notification boundary.**

Keep `ShouldQueue` and after-commit dispatch behavior. Ensure alert listeners query the intended `inventory.view` permission and the automatic request path keeps the existing `StockRequestApprovalService` notification flow. Do not make notifications synchronous or broaden tenant scope.

- [ ] **Step 3: Run the notification tests.**

```bash
php artisan test tests/Feature/Notifications/InventoryNotificationTest.php
```

Expected: PASS with variant context in notification data and the exact recipient matrix.

- [ ] **Step 4: Commit only notification files.**

```bash
git commit --only -m "fix: route inventory notifications by shop and role" -- app/Listeners/SendLowStockNotification.php app/Listeners/SendOutOfStockNotification.php app/Notifications/LowStockNotification.php app/Notifications/OutOfStockNotification.php app/Services/StockRequestApprovalService.php tests/Feature/Notifications/InventoryNotificationTest.php
```

If no notification source file changes are required, commit only the new test.

## Task 7: Normalize inventory image URLs and missing-file fallback

**Files:**

- Modify if required: `app/Models/InventoryImage.php:42-`
- Modify if required: `app/Http/Controllers/Erp/ProductInventoryController.php:247-281`
- Modify if required: `app/Http/Controllers/Erp/UploadInventoryController.php` image response/sanitization paths
- Modify if required: `resources/js/Pages/ERP/inventory/UploadInventory.tsx:151-`
- Modify if required: `resources/js/Pages/ERP/inventory/ProductInventory.tsx:38-`
- Modify if required: `resources/js/types/inventory.ts:70-77`
- Test: `tests/Feature/ProductInventoryTest.php` or the narrowest existing image test location

- [ ] **Step 1: Reproduce before changing the path code.**

With `Storage::fake('public')`, exercise relative `inventory/...`, legacy `/storage/...`, `storage/...`, and `public/...` values. Record whether the current model accessor, API sanitizer, and React consumers generate double prefixes or return missing files. Treat the reported production filename as an operational data/symlink question if it cannot be reproduced locally.

- [ ] **Step 2: Add the smallest failing regression.**

Assert that a valid stored image yields one `/storage/inventory/...` URL, legacy prefixes normalize to the same URL, and a missing physical file is omitted from the inventory response so the existing fallback renders. Do not assert that a missing production file is recreated.

- [ ] **Step 3: Implement only the reproducible fix.**

Normalize the path before `Storage::url()` and before public-disk existence checks. Prefer the API-provided canonical URL in React; otherwise use one shared small path-normalization helper in the existing inventory path, not duplicated prefix concatenation. Keep upload storage on the public disk and do not broad-migrate old files.

- [ ] **Step 4: Run the image regression.**

```bash
php artisan test tests/Feature/ProductInventoryTest.php --filter='image|storage|path'
```

Expected: PASS, or no source change if the existing backend already passes and only production storage state is missing; record that conclusion.

- [ ] **Step 5: Commit only changed image files.**

```bash
git commit --only -m "fix: normalize inventory image storage paths" -- app/Models/InventoryImage.php app/Http/Controllers/Erp/ProductInventoryController.php app/Http/Controllers/Erp/UploadInventoryController.php resources/js/Pages/ERP/inventory/UploadInventory.tsx resources/js/Pages/ERP/inventory/ProductInventory.tsx resources/js/types/inventory.ts tests/Feature/ProductInventoryTest.php
```

Use only the paths actually changed.

## Task 8: Full verification and handoff evidence

**Files:**

- Documentation: update this plan checkboxes and any concise developer notes needed by the final behavior.
- Optional learning: `docs/ai-learning-log.md` only for a durable, non-sensitive lesson.

- [ ] **Step 1: Run the focused backend suites.**

```bash
php artisan test tests/Feature/RetailPosPaymentFlowTest.php tests/Unit/CheckLowStockJobTest.php tests/Unit/Services/InventoryReplenishmentServiceTest.php tests/Feature/InventoryReplenishmentSettingsTest.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Feature/Procurement/ProcurementConcurrencyTest.php tests/Feature/Notifications/InventoryNotificationTest.php tests/Feature/ProductInventoryTest.php
```

- [ ] **Step 2: Run frontend checks.**

```bash
pnpm run test:frontend
pnpm run build
```

Do not stage generated `public/build` output unless the repository’s existing release convention explicitly requires it; preserve unrelated generated changes already in the worktree.

- [ ] **Step 3: Run hygiene and review checks.**

```bash
git diff --check
git status --short
```

Perform the required sequential review stack: `@ponytail` simplification, repository standards/spec/risk review, TypeScript/React review with `@vercel-react-best-practices`, `@typescript-advanced-types` only if applicable, `@karpathy-guidelines`, code-splitting and improvement claims, `@security-review`, and `@superpowers:verification-before-completion`. Review only this task’s diff against the two committed design documents; do not review or alter unrelated staged work.

- [ ] **Step 4: Manually exercise the command on a known low-stock target.**

In a controlled local environment, identify one known shop/item/color/size target and record its quantity, `auto_stock_request_enabled`, `reorder_level`, and `reorder_quantity`. Run:

```bash
php artisan inventory:check-alerts --shop-owner-id=<known-shop-owner-id>
```

Verify the matching alert, exact automatic request or intentional skip, exact incoming coverage, and recipients. Verify the normal queue worker path without consuming unrelated production jobs; if the local queue cannot be safely isolated, report that limitation rather than claiming delivery.

- [ ] **Step 5: Complete the final report with the required evidence.**

Explicitly report:

1. why Retail POS stock was stale;
2. how POS obtains/refetches exact selected color/size stock;
3. confirmation that backend insufficient-stock protection remains intact;
4. why saved variant settings were not honored;
5. how `CheckLowStockJob` resolves actual targets;
6. how variant alert identity/deduplication works;
7. why notifications were missing;
8. how automatic requests preserve exact color/size;
9. whether incoming-supply matching had a variant bug;
10. the inventory image 404 root cause or unreproducible operational finding;
11. files changed;
12. tests added/updated; and
13. the manual `inventory:check-alerts` result.

- [ ] **Step 6: Perform the final reuse/dead-code check.**

Confirm no unused imports, abandoned test fixtures, duplicate target resolvers, dead UI state, new dependencies, or stale TODOs were introduced. Do not mark the task complete until `@superpowers:verification-before-completion` has fresh command evidence for every claimed pass.

## Execution record -- 2026-09-09

Tasks 1-7 were implemented in the preceding commits. This follow-up traced the procurement stock-request notification from persistence through the browser:

- [x] Stock-request submission and repair-forwarding notifications emit the canonical procurement approval route.
- [x] Notification URL normalization preserves the canonical procurement route and query while retaining legacy inventory routing.
- [x] Procurement browser click reaches `/erp/procurement/stock-request-approval?stock_request=1` with no 4xx responses.
- [x] Focused backend matrix passes: 62 tests, 264 assertions, and 2 intentional MySQL/pcntl skips.
- [x] Focused frontend checks pass: 13 tests; the production build passes.
- [x] The image regression fixture explicitly uses `business_type=both` and `category=shoes` so the `/items` endpoint does not filter the test row.
- [ ] The full frontend suite has 235 passing files and 1,383 passing tests; one pre-existing suite cannot resolve `@testing-library/dom`.
- [x] The known low-stock command queued the check; the isolated job created the exact alert and automatic request with variant context.
- [x] Stock movements now queue the low-stock check automatically after commit.
- [x] Replenishment settings saves now queue the low-stock check automatically after commit.
- [x] Inventory low/out-of-stock events now create ERP notifications with exact variant context for same-shop inventory viewers.
- [ ] Queue-worker delivery was not consumed because the database queue also contains unrelated jobs.
- [x] Windows composer dev omits Pail (which requires pcntl) while retaining queue:listen, so queued automatic checks no longer need a manual alert command.
