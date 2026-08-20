# Logistics Mobile and Tablet Layout Design

**Date:** 2026-08-20
**Status:** Approved for implementation

## Goal

Improve the ERP Logistics Shipments page for phone and tablet users while preserving the existing desktop layout and shipment behavior.

## Scope

- Target `resources/js/Pages/ERP/Logistics/Shipments.tsx`, represented by the supplied Shipments screenshot.
- Treat widths below Tailwind `xl` as the compact ERP experience, matching the attendance page's responsive shell.
- Refresh only the responsive presentation: page heading, search and filters, shipment cards, and pagination.
- Convert the existing right-side mobile/tablet application-menu hamburger into an accessible modal sheet containing notifications, theme toggle, and account actions.
- Keep shipment state, Inertia navigation, API calls, delivery modal behavior, permissions, and all desktop classes/behavior intact.

## Visual direction

Use the repository's `DESIGN.md` principles that fit the ERP surface: neutral white/gray surfaces, strong readable typography, 8px spacing rhythm, restrained borders/shadows, and blue only for primary interactive emphasis. Reuse the attendance page's rounded controls, mobile-first stacking, and comfortable touch targets rather than introducing a new component system or dependency.

## Responsive behavior

### Phone

- Keep the compact ERP header full-width and stable while scrolling.
- Present the page title and supporting copy as a readable block.
- Place search input and action on one responsive row without forcing overflow; filters use a two-column grid and wrap when a module filter is present.
- Keep each shipment as a single-column card: identity/status, order/product summary, recipient/address/schedule, operational indicators, then a full-width Open delivery action.
- Wrap pagination content and links, with at least 44px touch targets.

### Tablet

- Continue the same compact shell and card composition as phone, with more breathing room and two-column detail/filter arrangements where space allows.
- Keep filters readable and evenly sized instead of compressing them into a dense desktop row.
- Keep the card's action aligned to the content without creating a horizontal scroll region.

### Desktop

- At `xl` and above, preserve the current ERP sidebar/header shell, header action row, shipment card three-column grid, search/filter arrangement, and pagination presentation.
- Do not change shipment data flow or desktop delivery/detail modals.

## Application-menu modal

- The right-side hamburger remains visible only below `xl`.
- Clicking it opens a modal overlay with a dimmed/backdrop surface and a compact rounded panel below the header.
- The panel contains the existing notification bell, theme toggle, and role-specific account dropdown without changing their props or routes.
- Add dialog semantics, a labelled close button, Escape handling, backdrop close, focus return to the trigger, body scroll locking, and safe viewport padding.
- The desktop action row remains rendered inline at `xl` and above; no mobile modal markup is shown there.

## Constraints and verification

- No backend, route, database, dependency, or business-rule changes.
- Preserve unrelated working-tree edits.
- Use existing React, Tailwind, SVG/icon, and modal patterns.
- Run focused frontend tests, full frontend tests, production build, and `git diff --check`.
- Verify at 390px, 768px, 1024px, and 1280px that compact layouts are readable, desktop remains unchanged, and no page-level horizontal overflow is introduced.

## Acceptance criteria

1. The Shipments page is comfortable and readable at phone and tablet widths, with no accidental page-level horizontal overflow.
2. Phone/tablet search, filters, cards, shipment actions, and pagination remain usable with touch-friendly controls.
3. The right hamburger opens a real accessible modal overlay on phone/tablet and closes through the close button, backdrop, or Escape while restoring focus.
4. Desktop at `xl` and above retains the current header action row and shipment presentation.
5. Existing shipment tests and behavior contracts remain passing; no API or navigation behavior changes.

## Follow-up detail UX pass

The second supplied set of screenshots extends the same compact experience to the shipment detail dialog:

- The mobile/tablet application-menu modal uses labelled action cards for alerts, appearance, and account actions, while retaining the existing role-specific components and desktop action row.
- The shipment detail dialog becomes a full-height phone surface and a spacious tablet surface, with order items, delivery metadata, and assignment controls grouped into readable cards.
- Shipment scheduling reuses the Batches `DeliveryDatePicker`, including its calendar dialog, minimum-date handling, clear action, and touch-sized controls. Each shipment leg receives a unique calendar id.
- The native browser date input is removed from the shipment detail scheduling form; no API payload or scheduling behavior changes.
