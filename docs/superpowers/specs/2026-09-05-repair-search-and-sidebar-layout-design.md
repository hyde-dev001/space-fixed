# Repair Shop Layout, ERP Sidebar, and Shop Search Design

## Goal

Finish the approved customer-facing repair-shop layout and make global shop search useful without changing payment, catalog, or authorization behavior.

## Requirements

- Keep `Shop Information` and `Customer Rating` in one neutral card, with Customer Rating as a full-width landscape section below the shop information details.
- Mark only the exact ERP page active. `/erp/logistics` activates `Logistics Dashboard`; `/erp/logistics/shipments` activates `Logistics`.
- Searching `shop` or `shops` in the customer search surface shows every public approved shop profile, regardless of `business_type` (`retail`, `repair`, or `both`).
- Searching `showroom` shows approved shops with an active showroom entitlement and provides their showroom link.
- Keep product suggestions and the existing `/api/search/suggestions` response contract intact.
- Show shop cards in the landing search modal as well as the existing header suggestion dropdown, with profile image, name, location, profile link, and an optional showroom link.

## Design

The repair-shop information card uses a vertical flow: shared header, shop information rows, then a full-width rating band separated by a neutral border. The rating band becomes a compact horizontal arrangement at wider viewports while remaining readable on mobile.

The ERP sidebar uses exact matching for the logistics dashboard route. Other nested logistics routes keep their current descendant matching behavior, so shipment detail pages remain under `Logistics` without changing access or menu visibility.

The search API already limits public results to approved shops and already distinguishes generic shop searches from showroom searches. The frontend will consume those existing fields and render one consistent monochrome card style in both search surfaces. Product cards remain unchanged.

## Non-goals

- Do not change payment, checkout, product inventory, shop approval, or showroom entitlement rules.
- Do not expose pending/rejected/private shop records.
- Do not add a new dependency or replace the current search endpoint.

## Acceptance criteria

1. Customer Rating appears below the shop information content and spans the container width.
2. The ERP sidebar has exactly one active logistics link on dashboard and shipments URLs.
3. The search API returns all three approved business types for a generic `shop` query and excludes pending shops.
4. The landing search modal renders shop profiles for `shop` and showroom-enabled shop cards for `showroom`.
5. Existing product search behavior remains covered by the current tests.
6. Focused tests, the frontend suite, a fresh Vite build, and diff checks pass before the branch is pushed.
