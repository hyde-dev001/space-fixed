# Approved Clarifications

These clarifications supplement `2026-09-08-automatic-replenishment-variant-settings-design.md` and are part of the approved implementation direction.

## Runtime authority

Backfill from `inventory_items` is a one-time initialization only. After migration, effective variant targets are explicit. Runtime replenishment checks must not fall back from size to color or from color to item. Parent settings are defaults for future targets and remain authoritative only for true no-variant items.

When a color-only target gains its first size, initialize that size from the color's current rule. The color rule may remain stored as history/default metadata, but it becomes non-effective and `CheckLowStockJob` must not read it while the color has size rows.

The current inventory flow has no persisted size-deletion endpoint. Last-size removal is therefore outside this change. A future deletion endpoint must explicitly decide whether the color remains stock-bearing and, if so, reactivate it from the last effective/default rule before deletion completes.

## Effective targets and bulk actions

`InventoryReplenishmentService` returns actual effective targets:

- every size under a color that has sizes;
- the color itself when it has no sizes;
- the parent item only when the item has no color or size targets.

This supports mixed-shape products. `Apply to All Colors & Sizes` writes to every returned effective target, not blindly to size rows. Per-color bulk writes to that color's size targets when sizes exist, otherwise to the color target.

## Alert target shape

Alert identity uses stable references with predictable combinations:

```text
size target:
inventory_color_variant_id = color id
inventory_size_id = size id

color-only target:
inventory_color_variant_id = color id
inventory_size_id = null

item target:
inventory_color_variant_id = null
inventory_size_id = null
```

The unresolved identity is shop + item + target references + alert type.

## Canonical variant identity

Color and size normalization must live in one shared helper used by both automatic replenishment and the manual Stock Request path. Equivalent size inputs such as `8`, `US 8`, and equivalent spacing/formatting must resolve to the same stored `requested_size` identity.

## Transaction boundary

The low-stock job resolves a candidate before entering a short transaction. Inside the transaction it locks and re-reads the target, re-checks the threshold and unresolved alert/request state, writes, and commits. Notifications are dispatched only after commit; locks are not held during notification or other expensive work.

## Automatic request source

Use the existing `is_auto_generated` marker on `stock_request_approvals` for automatic replenishment. No new source column is required.

## Target-shape transition test

Tests must cover color-only → first-size transition: the new size inherits the color rule, the color rule becomes non-effective, and the job checks the size only. The existing absence of persisted last-size deletion means the reverse transition is documented but not implemented here.
