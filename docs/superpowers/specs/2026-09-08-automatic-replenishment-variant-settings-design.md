# Automatic Replenishment Variant Settings

## Goal

Move Automatic Replenishment configuration to an action in Manage Stock Items and make monitoring, incoming-supply coverage, duplicate prevention, alerts, and generated Stock Requests follow the same inventory granularity as Stock Requests.

The implementation stays within the existing Laravel + Inertia/React inventory and procurement architecture. It does not create a separate replenishment settings table or a fake `ALL` variant.

## Authoritative configuration

The effective configuration is stored on the most specific real stock unit:

| Inventory shape | Authoritative record |
| --- | --- |
| Product with Color + Size | `inventory_sizes` |
| Product with Color only | `inventory_color_variants` |
| Product without variants | `inventory_items` |

The existing parent field names remain canonical:

- `auto_stock_request_enabled`
- `reorder_level`
- `reorder_quantity` (displayed as Quantity to Request)

Color rows that have child sizes are not an additional source of truth. Their replenishment fields are nullable/non-effective in that shape; the size rows own the configuration. Color rows are effective only when they have no size rows.

Existing parent settings initialize existing actual targets during the migration. Later edits to a size affect that size only and do not update its color or parent.

## Persistence changes

Add the three existing setting concepts to `inventory_sizes` and `inventory_color_variants`:

- `auto_stock_request_enabled`
- `reorder_level`
- `reorder_quantity`

For `inventory_sizes`, existing rows are backfilled from their parent `inventory_items` settings. For color variants without sizes, color settings are backfilled from the parent. Color variants that already have sizes do not receive an effective color-level rule.

New inventory creation and color/size additions initialize the effective target from the parent defaults. When a color-only target gains its first size, the size receives the color rule and the color rule is no longer used.

Add nullable stable target references to `inventory_alerts`:

- `inventory_color_variant_id`
- `inventory_size_id`

The existing `inventory_item_id` remains required. Foreign keys are tenant-safe through the job/controller queries and nullable on target deletion so historical alerts do not break if a child record is removed.

No new persisted `ALL` variant or separate settings table is introduced.

## Server API and authorization

Add a focused endpoint alongside the existing inventory item routes:

```text
PUT /api/erp/inventory/items/{id}/replenishment-settings
```

The request contains explicit actual targets, for example:

```json
{
  "targets": [
    {
      "type": "size",
      "id": 42,
      "auto_stock_request_enabled": true,
      "reorder_level": 5,
      "reorder_quantity": 20
    }
  ]
}
```

Allowed target types are `item`, `color`, and `size`. The controller loads the item inside the authenticated shop, applies `InventoryItemPolicy@update`, verifies every submitted child ID belongs to that item, validates booleans and non-negative/positive integer rules, then updates all targets in one transaction with row locks.

The endpoint returns the item with its existing nested color and size relations so the current page can refresh from the canonical response.

## Manage Stock Items UI

Remove the Automatic Replenishment block from Edit Stock Entry. Add a settings/gear action beside Edit and Archive for users allowed to update inventory.

The action opens a modal matching the current SoleSpace table, form, button, border, spacing, and neutral color patterns:

- Color + Size products show one section per color and one row per actual size.
- Each size row has Auto, Reorder Level, and Quantity to Request.
- `Apply to All Colors & Sizes` writes one selected policy into every actual size target.
- `Apply settings to all [Color] sizes` writes into only that color's actual size targets.
- Color-only products show one row per actual color target.
- Products without variants show one Stock Item row.
- Individual changes update only the selected target in local state.
- Save sends explicit target rows to the authorized endpoint.

Bulk actions only populate/write multiple actual records. They do not create inheritance or runtime precedence rules.

## Target resolution and low-stock checking

Add one small `InventoryReplenishmentService` to centralize actual target enumeration, target identity, and settings writes used by the controller and job.

Target enumeration rules:

1. Evaluate every `inventory_sizes` row when size rows exist.
2. Evaluate a color variant only when it has no sizes.
3. Evaluate the parent item only when it has no color or size targets.

For each target, use its own quantity and settings. Never add quantities from another color or size when deciding whether the target is low or out of stock.

`CheckLowStockJob` keeps the existing daily scheduler/queue flow and priority behavior:

- quantity `0` → out-of-stock alert and high-priority request when enabled;
- quantity `> 0` and `<= reorder_level` → low-stock alert and medium-priority request when enabled;
- automation disabled → alert/notification only;
- quantity above the target threshold → resolve only that target's active low/out-of-stock alerts.

Alert creation locks the actual target row and checks unresolved alerts using item + color target + size target + alert type. This prevents duplicate alerts from overlapping runs while allowing a different variant to create its own alert.

## Stock Request and incoming-supply contract

Automatic requests continue through `StockRequestApprovalService` and `stock_request_approvals`.

The service receives the actual target context and uses the existing request identity:

- size target → `requested_color` plus normalized `requested_size` such as `US 8`;
- color-only target → `requested_color`, `requested_size = null`;
- item target → both fields `null`.

Incoming coverage queries for open Stock Requests, legacy replenishment records where identity is available, Purchase Requests, and Purchase Order items/headers match the same color and size context. A different color, size, or product never reduces the target's uncovered quantity.

Existing open/terminal status rules remain unchanged. Legacy item-only replenishment records remain readable; they are used for item-level targets and are not treated as exact coverage for a variant whose identity was not stored.

The configured `reorder_quantity` is the target quantity. The generated request quantity is:

```text
max(0, configured request quantity - exact incoming coverage)
```

The generated request stores the exact color/size and remains pending for normal Procurement review. No automatic approval, supplier choice, PO, receiving, or stock increase is added.

## Notifications

Existing inventory permission and shop scoping remain in place. Low-stock and out-of-stock events gain optional target context so queued notifications can display, for example:

```text
Low Stock
Air Max 270 — Black — Size 8
3 remaining
```

Existing item-level event callers remain compatible. The database alert carries stable child references, while the notification uses the resolved display context.

## Error and compatibility behavior

- Invalid or cross-shop target IDs return validation/authorization errors and no partial settings are saved.
- A failed settings transaction leaves every target unchanged.
- Parent item settings remain available for no-variant items and legacy compatibility.
- Existing manual Stock Request creation and Procurement approval paths are unchanged.
- Existing `replenishment_requests` history is preserved and is not deleted.

## Tests

Add focused coverage for:

- size-specific low/out-of-stock checks and alert identity;
- color isolation and same-size/different-color isolation;
- exact incoming Stock Request, Purchase Request, and Purchase Order coverage;
- generated request color/size and uncovered quantity;
- automation disabled/enabled behavior;
- duplicate prevention and per-variant recovery;
- apply-all, per-color bulk, and individual override persistence;
- no-variant and color-only settings;
- settings endpoint policy, tenant ownership, validation, and atomicity;
- manual Stock Request and Procurement regression behavior.

Frontend tests should verify the action opens the settings modal, renders server-provided target settings, applies bulk values to the intended rows, and saves explicit target records without reproducing backend stock classification logic.

## Files/areas expected to change

- inventory variant/alert models and migrations;
- `InventoryReplenishmentService`;
- `CheckLowStockJob`;
- `StockRequestApprovalService`;
- low-stock/out-of-stock events, listeners, and notifications;
- `UploadInventoryController` and `routes/inventory-api.php`;
- inventory API/types and `UploadInventory.tsx` action/modal;
- focused Inventory and Procurement tests.

The implementation will not change unrelated logistics, payment, delivery, or product workflows.
