# Automatic Replenishment Variant Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make automatic replenishment configurable and monitored at the most specific real inventory target—Color + Size, Color-only, or item—while keeping the existing alert, procurement, authorization, and UI architecture.

**Architecture:** Add settings to the existing child inventory tables, use one `InventoryReplenishmentService` to enumerate effective targets and persist settings, and pass the target context through the existing low-stock job and `StockRequestApprovalService`. Add one authorized item endpoint and a settings action/modal in Manage Stock Items; bulk actions write explicit real targets and never create inheritance or a fake aggregate variant.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, queued jobs/events, PHPUnit/Pest-compatible Laravel tests, Inertia 2, React 18, TypeScript 5.7, Vite 7, Vitest, Tailwind CSS 4.

---

## Baseline audit

The current source of truth and dependencies are:

- `inventory_items`: owns `available_quantity`, `reorder_level`, `reorder_quantity`, and `auto_stock_request_enabled`; it is currently the only replenishment target.
- `inventory_color_variants`: owns color and aggregate quantity but currently has no replenishment settings.
- `inventory_sizes`: owns the exact size quantity and color relation but currently has no replenishment settings.
- `inventory_alerts`: stores only `inventory_item_id`, alert type, threshold/current values, and resolution state; it has no stable child-target identity.
- `CheckLowStockJob`: queued job invoked by the existing daily `inventory:check-alerts` scheduler; it checks parent item quantities and creates item-level alerts/requests.
- `StockRequestApprovalService`: canonical manual/automatic Stock Request writer; it already locks the parent item and writes `stock_request_approvals`, while its coverage methods currently match only `inventory_item_id`.
- `StockRequestApprovalController`: validates manual `requested_color`/`requested_size` and contains the current private size normalizer.
- `UploadInventoryController` and `routes/inventory-api.php`: own inventory item/variant writes and the inventory API middleware/policy boundary.
- `InventoryItemPolicy@update`: requires same-shop ownership and `inventory.edit`; the new settings route must use this same policy.
- `UploadInventory.tsx`: Manage Stock Items table and Edit Stock Entry modal; its current parent-level Automatic Replenishment block will move to an action modal.
- `replenishment_requests`: legacy history/backfill source; preserve it and count it only where its item-only identity is safe.

The existing `StockRequestApproval` `request_source` enum supports `manual|repair`; automatic inventory requests therefore continue to use `request_source=manual` plus `is_auto_generated=true`, whose existing computed source label/reason already identify them as automatic. No new enum value is needed.

## File map

### Create

- `database/migrations/2026_09_08_000001_add_replenishment_settings_to_inventory_variants.php` — add/backfill child settings.
- `database/migrations/2026_09_08_000002_add_target_references_to_inventory_alerts.php` — add nullable color/size alert target references.
- `app/Services/InventoryVariantIdentity.php` — shared color/size normalization used by manual requests, automatic coverage, and target matching.
- `app/Services/InventoryReplenishmentService.php` — effective-target enumeration, target identity, settings validation/update, and display context.
- `tests/Feature/InventoryReplenishmentSettingsTest.php` — endpoint authorization, validation, persistence, target transitions, and bulk settings.
- `tests/Unit/Services/InventoryReplenishmentServiceTest.php` — target enumeration/identity and exact target behavior.

### Modify

- `app/Models/InventoryColorVariant.php` — child setting fillable/casts and effective-target helpers/relations as needed.
- `app/Models/InventorySize.php` — child setting fillable/casts and target context.
- `app/Models/InventoryAlert.php` — target reference fillable/casts/relations.
- `app/Models/InventoryItem.php` — only minimal default/relationship support needed by the service; retain parent settings for no-variant items and creation defaults.
- `app/Http/Controllers/Erp/UploadInventoryController.php` — initialize child settings on creation/additions, add authorized settings endpoint, and return the canonical nested item.
- `routes/inventory-api.php` — register the settings endpoint beside item routes.
- `app/Services/StockRequestApprovalService.php` — accept an optional effective-target context and match all incoming supply by exact variant identity.
- `app/Http/Controllers/Erp/StockRequestApprovalController.php` — use the shared size/color normalizer without changing manual request behavior.
- `app/Jobs/CheckLowStockJob.php` — iterate effective targets, lock/recheck target state, create target-scoped alerts, and create target-scoped automatic requests.
- `app/Events/LowStockAlert.php` and `app/Events/OutOfStockAlert.php` — carry optional target display context while preserving existing callers.
- `app/Listeners/SendLowStockNotification.php` and `app/Listeners/SendOutOfStockNotification.php` — pass target context to existing notification classes.
- `app/Notifications/LowStockNotification.php` and `app/Notifications/OutOfStockNotification.php` — identify color/size when supplied; retain item-level wording otherwise.
- `resources/js/types/inventory.ts` — expose child settings and request payload types.
- `resources/js/services/inventoryAPI.ts` — add the authorized replenishment-settings update method.
- `resources/js/Pages/ERP/inventory/UploadInventory.tsx` — add the action/modal, render actual targets, bulk operations, and remove the visible settings block from Edit Stock Entry.
- `tests/Unit/CheckLowStockJobTest.php` — preserve existing item tests and add variant threshold, alert identity, coverage, recovery, and duplicate tests.
- `tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php` — add exact color/size coverage and generated-request assertions while preserving manual/procurement regressions.
- `tests/Feature/InventoryItemWriteAuthorizationTest.php` — cover the new endpoint’s policy and cross-shop target ownership.
- `resources/js/Pages/ERP/inventory/__tests__/UploadInventory.test.tsx` or the repository’s existing inventory contract-test location after confirming the current Vitest setup — verify action/modal rendering and explicit target payload behavior.

## Task 1: Establish failing regression coverage

**Files:**

- Create `tests/Feature/InventoryReplenishmentSettingsTest.php`.
- Create `tests/Unit/Services/InventoryReplenishmentServiceTest.php`.
- Modify `tests/Unit/CheckLowStockJobTest.php`.
- Modify `tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php`.
- Modify `tests/Feature/InventoryItemWriteAuthorizationTest.php`.
- Inspect existing frontend test location before choosing the final frontend test file.

- [ ] **Step 1: Add endpoint contract tests first.**

Cover a permitted user saving `item`, `color`, and `size` target rows, response nesting, rejected/invalid IDs, invalid booleans/quantities, cross-shop IDs, and all-or-nothing behavior for a mixed valid/invalid payload.

- [ ] **Step 2: Add target-resolution tests first.**

Create no-variant, color-only, color-plus-size, and mixed-shape fixtures. Assert that size rows are independent, colors with children are not effective color targets, and the parent is effective only when no child target exists.

- [ ] **Step 3: Add low-stock and procurement regression cases first.**

Add the required Black/8 versus Black/9 and Black/8 versus White/8 cases, exact incoming coverage, generated request color/size/quantity, disabled automation, duplicate isolation, recovery, and existing manual/procurement workflow assertions.

- [ ] **Step 4: Run the focused tests to prove the new contract fails against the current parent-only implementation.**

Run:

```bash
php artisan test tests/Feature/InventoryReplenishmentSettingsTest.php tests/Unit/Services/InventoryReplenishmentServiceTest.php tests/Unit/CheckLowStockJobTest.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Feature/InventoryItemWriteAuthorizationTest.php
```

Expected: the new variant/settings assertions fail because child columns, endpoint, target resolver, and variant-aware job behavior do not exist yet; existing baseline tests should remain green where they are reached.

## Task 2: Add child setting and alert-target persistence

**Files:**

- Create `database/migrations/2026_09_08_000001_add_replenishment_settings_to_inventory_variants.php`.
- Create `database/migrations/2026_09_08_000002_add_target_references_to_inventory_alerts.php`.
- Modify `app/Models/InventoryColorVariant.php`.
- Modify `app/Models/InventorySize.php`.
- Modify `app/Models/InventoryAlert.php`.

- [ ] **Step 1: Add settings columns with the smallest compatible defaults.**

Add `auto_stock_request_enabled`, `reorder_level`, and `reorder_quantity` to `inventory_sizes`. Add the same concepts to `inventory_color_variants`, allowing nullable values for colors that have child sizes. Backfill size rows from their parent item; backfill colors with no child sizes from the parent; leave color settings non-effective/null for colors that already have child sizes. Keep the existing parent columns unchanged.

- [ ] **Step 2: Add nullable stable alert target references.**

Add nullable `inventory_color_variant_id` and `inventory_size_id` to `inventory_alerts`, with appropriate indexes and null-on-delete foreign keys. Keep `inventory_item_id` required so historical item-level alerts remain valid.

- [ ] **Step 3: Update model mass-assignment and casts.**

Expose child setting fields as fillable/cast values and add alert target relationships. Do not add a persisted aggregate/`ALL` record or a second settings table.

- [ ] **Step 4: Run migrations and persistence tests.**

Run:

```bash
php artisan migrate:fresh --env=testing
php artisan test tests/Feature/InventoryReplenishmentSettingsTest.php tests/Unit/Services/InventoryReplenishmentServiceTest.php
```

Expected: migrations complete, model fields persist, and only behavior requiring the not-yet-created service/controller remains failing.

## Task 3: Centralize variant identity and effective-target resolution

**Files:**

- Create `app/Services/InventoryVariantIdentity.php`.
- Create `app/Services/InventoryReplenishmentService.php`.
- Modify `app/Models/InventoryItem.php` only if a minimal relation/query helper is needed.
- Modify `tests/Unit/Services/InventoryReplenishmentServiceTest.php`.

- [ ] **Step 1: Implement shared normalization.**

Normalize size tokens so `8`, `US 8`, and equivalent whitespace/case forms compare consistently while preserving non-US size systems when present. Normalize colors for matching without changing human-readable display names. Reuse this helper from both manual request validation and automatic coverage; do not copy the current controller-only formatter.

- [ ] **Step 2: Implement deterministic effective-target enumeration.**

Return a small array/collection contract containing target type/id, item/color/size IDs, quantity, effective settings, requested color, normalized requested size, and display text:

```php
[
    'type' => 'size|color|item',
    'id' => 42,
    'inventory_item_id' => 7,
    'inventory_color_variant_id' => 11,
    'inventory_size_id' => 42,
    'quantity' => 3,
    'auto_stock_request_enabled' => true,
    'reorder_level' => 5,
    'reorder_quantity' => 20,
    'requested_color' => 'black',
    'requested_size' => 'US 8',
]
```

Enumerate every size when size rows exist; enumerate a color only when that color has no sizes; enumerate the parent only when there are no color or size targets. Do not fall back from a size to its color or parent at runtime.

- [ ] **Step 3: Implement atomic settings updates.**

Validate target identity against the already authorized item, lock all affected real rows in a deterministic order, validate target shape and values, then update all rows in one transaction. Reject duplicate target identities and mixed invalid payloads without saving any row.

- [ ] **Step 4: Add transition/default rules.**

When creating the first size under a color-only target, initialize the size from the current effective color settings and make the color non-effective. New sizes under an already-sized color use the item’s creation defaults, not a runtime parent fallback. Preserve the existing parent settings for no-variant items and future child initialization. The current write API has no persisted last-size deletion operation; leave reverse transition behavior out of scope.

- [ ] **Step 5: Run the service tests.**

Run:

```bash
php artisan test tests/Unit/Services/InventoryReplenishmentServiceTest.php
```

Expected: target enumeration, identity normalization, settings updates, bulk-target semantics, and transitions pass.

## Task 4: Expose the authorized Manage Stock Items settings endpoint

**Files:**

- Modify `routes/inventory-api.php`.
- Modify `app/Http/Controllers/Erp/UploadInventoryController.php`.
- Modify `tests/Feature/InventoryReplenishmentSettingsTest.php`.
- Modify `tests/Feature/InventoryItemWriteAuthorizationTest.php`.

- [ ] **Step 1: Register `PUT /items/{id}/replenishment-settings`.**

Keep it in the existing `web`, `auth:user`, permission, and `shop.isolation` inventory route group.

- [ ] **Step 2: Enforce the existing item update policy.**

Load the item by authenticated shop, call the same `authorizeInventoryItem($request, 'update', $item)` path used by item edits, and ensure every submitted child ID belongs to that item and has the requested type/parent relationship. Do not make `view-inventory` sufficient for editing.

- [ ] **Step 3: Validate and delegate the payload.**

Validate explicit `targets` rows, allowed types (`item`, `color`, `size`), IDs, boolean settings, `reorder_level >= 0`, and `reorder_quantity >= 1`. Delegate the transaction to `InventoryReplenishmentService` and return the item with `sizes`, `colorVariants.images`, `colorVariants.sizes`, and `images` in the same shape as the existing inventory API.

- [ ] **Step 4: Run authorization and endpoint tests.**

Run:

```bash
php artisan test tests/Feature/InventoryReplenishmentSettingsTest.php tests/Feature/InventoryItemWriteAuthorizationTest.php
```

Expected: permitted same-shop edits pass, missing `inventory.edit`, foreign targets, malformed targets, and cross-shop access fail with no partial writes.

## Task 5: Initialize child settings in existing inventory write paths

**Files:**

- Modify `app/Http/Controllers/Erp/UploadInventoryController.php`.
- Modify `tests/Feature/InventoryReplenishmentSettingsTest.php`.

- [ ] **Step 1: Initialize settings during item creation.**

Use submitted parent defaults for every newly created effective size/color target. For a color with child sizes, keep the color non-effective; for a color without sizes, keep the color target configured. Preserve item-level legacy sizes as actual size targets.

- [ ] **Step 2: Initialize settings when adding colors and sizes.**

Apply the first-size transition rule and ensure parent quantity/settings are not used as a hidden runtime override afterward. Keep existing quantity recomputation, image handling, duplicate-color checks, and authorization unchanged.

- [ ] **Step 3: Verify creation/addition behavior.**

Run the transition and no-variant/color-only tests from the focused feature suite. Expected: new actual targets have explicit settings and no unrelated inventory behavior changes.

## Task 6: Make Stock Request coverage exact and keep Procurement canonical

**Files:**

- Modify `app/Services/StockRequestApprovalService.php`.
- Modify `app/Http/Controllers/Erp/StockRequestApprovalController.php`.
- Modify `tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php`.
- Modify `tests/Unit/CheckLowStockJobTest.php`.

- [ ] **Step 1: Extend the automatic request contract with target context.**

Accept the effective target context while preserving the existing item-only method call compatibility. Generated requests keep `request_source=manual`, set `is_auto_generated=true`, remain pending, and store exact `requested_color`/normalized `requested_size`.

- [ ] **Step 2: Match open Stock Requests exactly.**

Match shop, item, normalized color, and normalized size. Item-only target coverage matches both fields null. Color-only coverage matches color with null size. Size coverage requires both exact color and size. Do not count all-size/null-size records as coverage for one specific size.

- [ ] **Step 3: Match legacy replenishment, Purchase Request, and Purchase Order coverage.**

Preserve existing open/terminal status rules and duplicate backfill exclusion. Legacy item-only replenishment records count only for an item-level target; records with no stored variant identity cannot cover a specific color/size. Purchase order item/header and receiving-aware calculations must use the same exact variant contract.

- [ ] **Step 4: Use one short lock transaction for duplicate safety.**

Lock the item/target context, re-read quantity/settings, calculate uncovered quantity, and persist one Stock Request. Do not hold locks while sending notifications. Preserve existing request-number locking and after-commit notification behavior.

- [ ] **Step 5: Run Procurement regressions.**

Run:

```bash
php artisan test tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Unit/CheckLowStockJobTest.php
```

Expected: old item-level coverage tests remain green and new exact color/size coverage tests pass.

## Task 7: Convert `CheckLowStockJob` to target-level checking

**Files:**

- Modify `app/Jobs/CheckLowStockJob.php`.
- Modify `app/Events/LowStockAlert.php`.
- Modify `app/Events/OutOfStockAlert.php`.
- Modify `app/Models/InventoryAlert.php` if target query helpers are useful.
- Modify `app/Listeners/SendLowStockNotification.php`.
- Modify `app/Listeners/SendOutOfStockNotification.php`.
- Modify `app/Notifications/LowStockNotification.php`.
- Modify `app/Notifications/OutOfStockNotification.php`.
- Modify `tests/Unit/CheckLowStockJobTest.php`.

- [ ] **Step 1: Iterate service-provided effective targets.**

Keep the existing shop and active-item query, but evaluate each target’s own quantity, threshold, and automation flag. Never add quantities from another color/size. Retain priority rules: out-of-stock high, low-stock medium.

- [ ] **Step 2: Make alert creation target-scoped and concurrency-safe.**

For each target, lock/re-read the target row in a short transaction, check unresolved alert identity using item + color target + size target + alert type, create at most one alert, commit, and then dispatch the event. Existing item-level event callers remain compatible through optional target context.

- [ ] **Step 3: Resolve only the recovered target.**

When a target rises above its own threshold, resolve only that target’s unresolved low/out-of-stock alerts. A later drop for that same target can alert again; a different target has an independent alert lifecycle.

- [ ] **Step 4: Add target context to existing notifications.**

Keep recipient permission/shop filtering and inventory destination links. Add product + color + size/current quantity to messages when target context exists; retain current item-level wording for legacy callers.

- [ ] **Step 5: Verify queued-job behavior.**

Run:

```bash
php artisan test tests/Unit/CheckLowStockJobTest.php
```

Expected: item-level baseline cases, variant isolation, no duplicate unresolved alerts/requests, recovery, disabled automation, and exact notification context pass.

## Task 8: Update Manage Stock Items UI and API types

**Files:**

- Modify `resources/js/types/inventory.ts`.
- Modify `resources/js/services/inventoryAPI.ts`.
- Modify `resources/js/Pages/ERP/inventory/UploadInventory.tsx`.
- Add/update the focused frontend test file selected from the existing Vitest setup.

- [ ] **Step 1: Extend types and API client.**

Add optional child setting fields and a typed `ReplenishmentSettingsTarget`/payload. Add `inventoryItemAPI.updateReplenishmentSettings(id, payload)` using the existing CSRF/axios conventions and return the nested canonical item.

- [ ] **Step 2: Add the settings action beside existing row actions.**

Use the existing gear/action styling and show it only when the current user is allowed to update inventory. Do not expose full automation controls in Edit Stock Entry. Keep edit/archive/restore behavior unchanged.

- [ ] **Step 3: Build the modal from actual effective targets.**

Render Color + Size products as color sections with size rows; render color-only products as color rows; render no-variant products as one item row. Include Auto, Reorder Level, and Quantity to Request. Keep the current neutral SoleSpace modal/table/input/button styles and responsive behavior.

- [ ] **Step 4: Implement explicit bulk and override behavior.**

`Apply to All Colors & Sizes` copies one policy into every service-provided target. `Apply settings to all [Color] sizes` copies only that color’s size targets. Row edits change only that target. Save sends all actual target records; there is no `ALL` row, inheritance, or client-side threshold logic.

- [ ] **Step 5: Refresh from the server response.**

After save, replace the edited item with the API response, close the modal, and retain existing loading/error notification conventions.

- [ ] **Step 6: Add a focused frontend regression.**

Follow the existing Vitest style. Prefer a small extracted settings view/model helper only if rendering the 1,800-line page is impractical; otherwise test the page contract directly. Verify the action/modal, server-provided settings, apply-all/per-color targeting, individual override, explicit payload, and absence of the old Edit Stock Entry settings block.

- [ ] **Step 7: Run frontend checks.**

Run:

```bash
pnpm run test:frontend
pnpm run build
```

Expected: all existing frontend tests and the new inventory settings regression pass, and Vite produces a fresh build.

## Task 9: Full regression, review, and documentation

**Files:**

- Modify only changed implementation/test files as needed from review findings.
- Update `docs/ai-learning-log.md` only if a durable repository lesson was discovered.

- [ ] **Step 1: Run the focused backend suite.**

```bash
php artisan test tests/Feature/InventoryReplenishmentSettingsTest.php tests/Unit/Services/InventoryReplenishmentServiceTest.php tests/Unit/CheckLowStockJobTest.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Feature/InventoryItemWriteAuthorizationTest.php
```

- [ ] **Step 2: Run repository quality checks.**

```bash
git diff --check
pnpm run test:frontend
pnpm run build
composer test
```

Record exact pass/fail results; do not claim a check that was not run.

- [ ] **Step 3: Perform sequential review gates.**

Apply the repository-required ponytail simplification pass, standards/spec/correctness review, TypeScript/React checklist, Karpathy assumptions/minimum-diff review, security/authorization/input review, and reuse/dead-code scan. Confirm no unrelated worktree paths (`AGENTS.md`, `storage/framework/cache/`) are staged.

- [ ] **Step 4: Confirm operational acceptance.**

Verify scheduler registration was not duplicated or changed, queued `CheckLowStockJob` remains the daily path, no automatic approval/PO/receiving was introduced, manual Stock Requests still work, and legacy replenishment history remains readable.

- [ ] **Step 5: Commit the coherent implementation.**

Stage only the implementation, migrations, tests, frontend source, and any durable documentation. Use a focused commit message such as:

```bash
git add -- <explicit changed paths>
git commit -m "feat: make automatic replenishment variant aware"
```

Do not stage generated `storage/framework/cache/` or unrelated `AGENTS.md` changes. Push/rebase is a separate integration action and should be done only when explicitly requested after verification.

## Acceptance checklist

- [ ] Settings are opened from a Manage Stock Items action, not primarily from Stock Requests or Edit Stock Entry.
- [ ] Existing `reorder_level` remains the canonical threshold.
- [ ] Real Color + Size, Color-only, and no-variant targets each own effective settings.
- [ ] Bulk actions write actual targets only; individual overrides remain isolated.
- [ ] Low/out-of-stock alerts are target-scoped, deduplicated, recoverable, and tenant/permission scoped.
- [ ] Automatic requests use `stock_request_approvals`, exact color/size identity, configured request quantity minus exact incoming supply, and normal Procurement approval.
- [ ] Different products/colors/sizes never share incoming coverage or unresolved alert state.
- [ ] Manual request/procurement behavior and legacy history remain compatible.
- [ ] New settings writes enforce `InventoryItemPolicy@update` and child ownership.
- [ ] No database `ALL` variant, separate settings table, or unrelated workflow change is introduced.
