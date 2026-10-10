# Retail POS Stock and Variant Replenishment Hardening

Date: 2026-09-09  
Status: Approved design; implementation plan follows spec review

## Context

The inventory model and variant-level replenishment stack already exist. The
change will repair the boundaries between the Retail POS, canonical inventory
targets, replenishment checks, notifications, and inventory image URLs without
introducing a second inventory or replenishment system.

The inventory hierarchy remains:

- `inventory_item` for products without variants;
- `inventory_color_variants` for color-only products or color targets without
  size rows;
- `inventory_sizes` for every configured color-and-size target.

For a linked product, inventory is authoritative. `ProductVariant.quantity`
and product stock values remain compatibility/fallback data for unlinked or
legacy flows; the POS must not use a stale mirror as the stock source for a
linked target.

The existing POS convention is retained: a cart quantity is separate from the
database-available stock label. The label may therefore show database stock of
10 while the cart contains 3. Checkout remains server-authoritative.

## Design

### 1. One target resolver for POS reads and writes

The existing inventory checkout service will own the target resolution used by
both the product listing and checkout paths. It will prefer stable inventory
identifiers and use normalized color/size matching only for legacy requests
that do not provide identifiers.

For each linked catalog variant, the POS product response will expose the
resolved `inventory_color_variant_id` and `inventory_size_id` together with its
current quantity. A color-and-size variant resolves to its exact size row; a
color-only variant resolves to its color row. An unlinked catalog variant
continues to use its existing `ProductVariant` quantity.

The product-level stock returned for a linked product will come from the same
inventory hierarchy used by checkout. It will not replace the selected
variant's quantity when a color and size are selected.

The checkout payload will carry the resolved inventory identifiers when
available. The backend will validate that the target belongs to the product's
shop-scoped inventory item and, for a size target, that its color parent is the
same requested color target. Invalid or foreign identifiers will be rejected;
they will never be trusted to decrement another tenant's inventory. Legacy
string requests will continue through the shared normalized fallback.

The existing successful-checkout refetch and Refresh action will be retained.
Once the listing is canonical, both paths return the post-sale quantity
without requiring a browser hard refresh. No React-side stock check will
replace the existing transactional backend check.

### 2. Atomic checkout behavior

The current Retail POS payment flow remains the transaction boundary for the
order/receipt, inventory decrement, stock movement, and existing Finance
posting. The resolved target is locked and revalidated inside that boundary.

An insufficient or invalid target fails the checkout and leaves stock and the
other transaction records unchanged. The catalog's compatibility quantity is
not updated as a second source of truth for linked inventory; subsequent POS
reads resolve the live inventory target.

### 3. Variant replenishment targets and settings

`InventoryReplenishmentService::effectiveTargets()` remains the single target
enumerator:

1. If size rows exist, evaluate every `inventory_size` independently.
2. If a color has no size rows, evaluate that `inventory_color_variant`.
3. If the item has no color or size variants, evaluate the parent
   `inventory_item`.

An explicit size target never falls back to its color or parent at runtime.
Parent settings remain defaults/migration compatibility only. Settings writes
continue through the existing authorized endpoint and transaction, using the
target type and ID for each actual target. The service will reject a size
whose color parent does not belong to the same inventory item and will reject
standalone color settings for colors that already have size targets. One
invalid target rolls back the settings batch.

### 4. Alerts, automatic requests, and incoming coverage

`CheckLowStockJob` remains the daily entry point and evaluates each effective
target separately:

- quantity `0`: out-of-stock alert and high-priority automatic request when
  enabled;
- quantity greater than `0` and at or below reorder level: low-stock alert and
  medium-priority automatic request when enabled;
- automation off: alert/notification only;
- quantity above the target reorder level: resolve only that target's active
  alert.

Alert identity is scoped by shop, parent inventory item, color target, size
target, and alert type. Repeated scans leave one unresolved alert for the same
target, while different variants create independent alerts. Recovery resolves
only the target whose quantity is normal.

Automatic requests continue through `StockRequestApprovalService` and the
existing `stock_request_approvals` table. They preserve the exact target color
and normalized size. The requested quantity is the configured target request
quantity minus open incoming supply for that same target only. Coverage keeps
the current sources—open stock requests, legacy replenishment requests,
purchase requests, purchase orders, and received quantities—and applies the
same target identity to each source. Unrelated colors, sizes, and parent-level
records are not subtracted from an explicit variant request.

Alert and request records are committed before queued notifications are
dispatched. Low/out-of-stock alerts notify same-shop users with
`inventory.view`. Automatic stock-request notifications notify the shop's
Procurement Manager, falling back to Finance only when no Procurement Manager
exists. Variant context (product, color, size, remaining quantity) is retained
in the notification payload.

### 5. Inventory image paths

Inventory image URLs will use the existing public storage disk and one
canonical `/storage/...` URL. Legacy path prefixes such as `/storage/`,
`storage/`, or `public/` will be normalized before URL generation and file
existence checks, preventing double prefixes. Missing physical files will be
omitted from inventory responses so the existing application fallback can
render; no fake asset or broad migration will be added. If the reported 404 is
only stale production data rather than a reproducible path-generation defect,
the final report will distinguish that operational cause from the code fix.

## Verification

Focused Laravel coverage will verify:

- linked POS listing quantities and stable target IDs for exact color/size
  selections;
- post-sale listing refresh, insufficient-stock rejection, and full rollback;
- settings save/reload for item, color, and size targets plus malformed-parent
  rejection;
- target enumeration, alert deduplication, separate variant alerts, per-target
  recovery, automation-off behavior, and exact incoming-supply subtraction;
- automatic request target fields and recipient routing;
- normalized inventory image URLs if a reproducible URL/path defect is found.

Focused React coverage will verify that the selected color/size immediately
shows its exact stock, switching variants changes the displayed quantity, and
the existing post-checkout/Refresh fetch uses the current catalog response.

After implementation, run the focused backend tests, the frontend test suite,
frontend build, `git diff --check`, and `php artisan inventory:check-alerts`
against a known low-stock color-and-size target. Record the target stock,
settings, alert result, automatic-request result, incoming coverage, and
recipients. Verify the normal queue worker path for queued notifications. Any
environment limitation (including the inability to reproduce the reported
production image file) will be reported explicitly.

## Scope constraints

- Reuse `InventoryReplenishmentService`, `CheckLowStockJob`,
  `StockRequestApprovalService`, and the existing inventory models.
- Keep tenant/shop scoping, authorization, the daily scheduler, procurement
  workflow, and queued notification conventions.
- Do not add a replenishment table, fake `ALL` variants, a second POS stock
  endpoint, new dependencies, or a broad inventory redesign.
