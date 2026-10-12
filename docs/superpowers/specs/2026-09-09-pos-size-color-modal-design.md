# Cashier POS Size and Color Modal Design

## Requested outcome

Improve the retail cashier POS so size and color selection uses an ERP-style modal, while every retail product card keeps the same internal alignment even when a product name is long.

## Scope

- Change the retail catalog size and color controls in `resources/js/Pages/ERP/cashier/POS.tsx` to open one product-specific size/color modal.
- Use the same modal interaction for changing size and color on items already in the current order.
- Reserve a consistent two-line title area in each product card so the option controls, divider, price, and Add button remain aligned.
- Preserve the existing variant resolution, stock checks, duplicate-line merging, cart quantities, pagination, checkout flow, and unrelated ERP UI.

## UX and visual design

- The catalog card remains a fixed-height flex card. The product name gets a fixed two-line area with wrapping/clamping, so short and long names consume the same vertical space.
- Size and color remain visible as compact trigger buttons in the card/cart line. Their labels show the current values and both triggers open the same modal for that product or cart line.
- The modal uses the existing shared ERP `Modal` component, with a white/dark surface, ERP spacing/radius, visible close button, backdrop dismissal, Escape dismissal, and body-scroll locking.
- The modal header identifies the action and product. Separate labelled sections present Size and Color options as keyboard-accessible buttons rather than native browser dropdown menus.
- The active values have a clear selected state. Options follow the current stock-aware option filtering and invalid/out-of-stock combinations cannot be applied. The modal shows the resulting availability before confirmation.
- `Cancel`, Escape, and backdrop dismissal discard the draft. `Apply` commits the selected pair through the existing catalog selection or cart-variant update path.
- All controls keep visible focus states, descriptive accessible names, selected/disabled semantics, and touch-friendly hit areas. Motion is limited to the shared modal behavior and respects reduced-motion through existing styles.

## State and data flow

1. A catalog or cart trigger opens the modal with the current normalized size/color pair.
2. The modal owns a temporary draft pair while it is open.
3. Choosing one option keeps the existing compatibility behavior by selecting a valid companion option when the current pair is unavailable.
4. Applying a catalog draft calls `updateRetailSelection`.
5. Applying a cart draft calls `updateRetailCartVariant`, preserving stock validation and duplicate-line merging.
6. Closing without applying leaves the source selection unchanged.

No new API, database field, dependency, or global state is required.

## Acceptance criteria

- A long product name does not move the size/color controls or footer relative to neighboring cards.
- Catalog size and color triggers open the modal and show the current values.
- Cart size and color triggers open the modal and show the current values.
- Apply updates the intended selection; Cancel, Escape, and backdrop close do not.
- Stock and duplicate-cart behavior remains governed by the existing helpers.
- Existing POS pagination, checkout, repair mode, and other ERP modal behavior remain unchanged.
- Relevant frontend tests and the production build pass.

## Verification plan

- Add focused frontend tests for modal opening, apply/cancel behavior, and stable card title layout hooks/classes.
- Run the focused POS test file(s), then the complete frontend test command.
- Run the production frontend build.
- Inspect `git diff --check`, the final diff, and working-tree status to confirm only scoped files changed and existing user work remains intact.
