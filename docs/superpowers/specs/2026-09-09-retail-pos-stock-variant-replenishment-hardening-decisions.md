# Retail POS and Replenishment Design Decisions

This addendum is part of the approved design in
`2026-09-09-retail-pos-stock-variant-replenishment-hardening-design.md` and
freezes the remaining implementation boundaries.

## Frozen decisions

1. **Catalog variant IDs are cross-checked, not trusted.** The backend derives
   the authoritative inventory target from the selected catalog variant and
   shop-scoped inventory. Client-supplied
   `inventory_color_variant_id`/`inventory_size_id` values must exactly match
   that derived target. A valid ID for another size or color is rejected, even
   when it belongs to the same product. An unresolvable linked variant is
   unavailable with zero sellable stock and cannot fall back to
   `ProductVariant.quantity`.

2. **Target enumeration is per color.** For each color target, evaluate every
   size when that color has size rows; otherwise evaluate the color row. Only
   when the item has no color targets at all is the parent item evaluated. A
   mixed item such as Black-with-sizes plus White-color-only therefore produces
   Black size targets and a White color target.

3. **Only one stock-condition alert class is active per target.** A target at
   zero resolves its active low-stock alert before creating or retaining
   out-of-stock. A target that rises above zero but remains at or below reorder
   resolves out-of-stock before creating or retaining low-stock. A target above
   reorder resolves both condition classes for that target. Other variants are
   never changed.

4. **Automatic-request dedupe is independent of alert dedupe.** The target
   lock is held while stock/settings are reread, exact incoming coverage is
   recomputed, and the existing uncovered request condition is checked. The
   resulting automatic request uses `quantity_needed`,
   `status = 'pending'`, `is_auto_generated = true`, the existing compatible
   `request_source`, and the existing automatic-replenishment note/source
   label. Overlapping scans must not create duplicate uncovered requests.

5. **Product stock is a display-only aggregate.** For a linked product it is
   the sum of the effective inventory target quantities (all sizes, plus
   color-only targets; or the parent quantity when no variants exist). The
   selected target quantity is shown whenever color/size is selected. The
   aggregate is never used to authorize a variant checkout or to subtract
   variant incoming supply.

6. **Required regression cases** include exact same-product wrong-size ID
   rejection, mixed color shapes, low-to-out-of-stock and out-to-low
   transitions, overlapping replenishment scans, and exact selected-variant
   POS refresh/display behavior, in addition to the cases in the parent
   design.

## Implementation boundary

Keep the existing `InventoryCheckoutService` as the shared home for the small
target-resolution logic because it already owns linked inventory reads and
writes. Do not add a second resolver service unless the existing class cannot
share the logic without unrelated coupling. No new tables, dependencies, or
POS endpoints are required.
