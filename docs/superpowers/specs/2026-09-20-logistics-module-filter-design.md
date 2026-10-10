# Logistics Module Filter Design

## Goal

Place the existing logistics module filter in the Available deliveries filter row so dispatchers can choose All modules, Retail, or Repair where they are filtering delivery records.

## Design

- Reuse the existing `module` state, `changeModule` handler, route query, and server-side filtering already owned by `Batches.tsx`.
- Move the module `<select>` from the page header into `AvailableDeliveriesPanel` beside the delivery window and schedule status controls.
- Keep `All modules` as the default option, followed by `Retail` and `Repair` when those modules are available.
- Remove the header copy so there is one source of truth and no duplicate controls.
- Keep the existing behavior when the value changes: clear selected deliveries, reset pending local batch selections, and reload filtered batch data while preserving scroll/state.
- Make `Clear filters` reset the module to `All modules` along with the existing search/status/date/window filters.
- Use the existing responsive grid and select styling with four desktop columns so the controls fill the available row without an empty gap and wrap cleanly on narrow screens.

## Data flow

`AvailableDeliveriesPanel` emits the selected module through a new callback. `Batches.tsx` continues to call `changeModule`, which requests the current batches endpoint with `module`, `date`, and `window`. No new API or filtering abstraction is needed.

## Verification

- Test that the module filter is rendered in the Available deliveries panel with All modules, Retail, and Repair options.
- Test that selecting Repair sends the existing backend request with `module: 'repair'`.
- Test that clearing filters resets the module to `all`.
- Run the focused Batches Vitest file and `git diff --check`.

## Non-goals

- No changes to delivery classification, API contracts, batch compatibility rules, or unrelated layout sections.
