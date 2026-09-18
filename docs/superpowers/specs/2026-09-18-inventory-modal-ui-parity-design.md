# Inventory Modal UI Parity Design

## Goal

Make the inventory account's `Add Stock Entry` and `Edit Stock Entry` modals visually consistent with the existing shop-owner/retail product modal shown in the reference screenshot.

## Scope

- Use the existing `ColorVariantManager` visual structure as the source of truth for color cards, image galleries, size stock, spacing, and modal hierarchy.
- Add the same searchable named-color picker to both product and inventory flows; searching `indigo` must return a selectable Indigo swatch.
- Keep inventory persistence behavior intact: image upload/delete, size creation, quantity correction, new color creation, confirmation dialogs, permissions, validation, and stock refreshes.
- Preserve the repair-material flow and the existing product-management page behavior.
- Align the inventory modal shell, header, scroll area, section spacing, and fixed footer with the product modal.

## Non-goals

- No database, migration, route, controller, or authorization changes.
- No new dependency.
- No redesign of the inventory table or unrelated ERP pages.
- No inventory action will be added unless an existing backend operation supports it.
- The searchable catalog will cover the browser's named-color vocabulary plus the repository's common design-palette names; arbitrary names remain available through the custom color field.

## Design

`UploadInventory.tsx` will continue to own inventory state and API calls. The shared variant UI components will gain only optional persistence hooks, with their current local behavior remaining the default for existing product-management callers.

For inventory edit mode, existing color variants will render through the shared visual card structure while delegating supported actions to the existing inventory handlers. New color variants will continue to use the existing `inventoryItemAPI.addColor` flow on submit. Add mode will use the same shared variant UI without changing its current validation or payload behavior.

The color picker will use one shared catalog and one normalized search path. Search is case-insensitive and matches the full color name; selecting a result uses its stored swatch value and existing duplicate-color checks.

The modal will use the same responsive max width, full-height scrollable body, neutral card treatment, and two-column fixed footer as the reference product modal. Existing inventory-only controls that do not have a matching backend operation will remain unavailable rather than becoming misleading UI actions.

## Data and error handling

- Existing inventory API requests remain the source of truth.
- Local variant state updates only after successful persisted operations where the current flow already does so.
- Existing SweetAlert confirmation and error messages remain in place.
- Existing permission and owner-mode guards remain unchanged.
- Existing create/edit validation remains unchanged.

## Verification

- Add focused frontend coverage for the shared variant UI's optional inventory behavior where practical.
- Run `pnpm run test:frontend`.
- Run `pnpm run build`.
- Inspect `git diff`, `git diff --check`, and the final working-tree status.
- Use browser verification for the rendered add/edit modal if the local application can be run safely.
