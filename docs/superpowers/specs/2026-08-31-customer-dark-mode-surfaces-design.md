# Customer Dark-Mode Surfaces Design

**Date:** 2026-08-31

## Goal

Make the customer-facing side menu/cart drawer, checkout page, and repair-shop package selection readable and visibly themed when the saved customer theme is dark, without changing ERP or shop-owner themes.

## Root Cause

The customer theme is applied through `html.userside-dark` and already remaps several standard Tailwind gray, white, and black utility classes. The affected surfaces also use arbitrary color utilities and hard-coded black state styles that are not covered by those shared mappings:

- `Navigation.tsx` gives the drawers an explicit `text-[#111111]` color and uses arbitrary muted text/border colors.
- `Checkout.tsx` uses a white-to-slate empty-cart gradient and several light-only checkout surfaces.
- `repairShow.tsx` renders a selected package with a black border and black indicator, which has insufficient contrast against the dark customer surface.

## Chosen Approach

Use a hybrid, scoped fix:

1. Add semantic customer-surface markers and explicit dark utility variants to the shared navigation drawers so their text, muted labels, surfaces, borders, and actions remain readable.
2. Add a `userside-checkout-page` marker and narrowly scoped dark CSS for checkout-specific gradients and surfaces that cannot be corrected by the existing generic token mappings alone. Keep all selectors beneath `html.userside-dark #app`.
3. Add dark variants to repair package cards and selected indicators, using a lighter accent border and tinted selected surface so selection is visible by both shape/state and color.

This keeps the diff local to the three affected customer surfaces, reuses the existing dark palette, and avoids broad selectors that could alter ERP or shop-owner pages.

## Visual and Interaction Requirements

- Primary customer text in dark mode uses `#f8fafc` or an equivalent existing light token.
- Secondary text remains visibly distinct from the dark surface and meets the existing customer palette intent.
- Drawer and checkout borders/dividers remain visible in dark mode.
- Checkout empty states and summary surfaces use dark surfaces rather than white/light gradients.
- A selected repair package has a high-contrast border, a tinted surface, and a visible selected indicator.
- Light mode keeps its current appearance.
- Existing drawer motion, focus behavior, and click interactions remain unchanged.

## Files and Responsibilities

- `resources/js/Pages/UserSide/Shared/Navigation.tsx`: shared drawer surface classes and dark variants.
- `resources/js/Pages/UserSide/Orders/Checkout.tsx`: checkout root marker and any directly-owned dark utility variants needed for checkout controls.
- `resources/js/Pages/UserSide/Repairs/repairShow.tsx`: package selected-state dark variants.
- `resources/css/app.css`: scoped checkout/drawer fallback rules for arbitrary utility classes and gradients that need theme-specific treatment.
- `resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts`: regression contracts for the shared drawer dark states.
- `resources/js/Pages/UserSide/Orders/__tests__/Checkout.dark-mode.test.ts`: checkout dark-surface contract.
- `resources/js/Pages/UserSide/Repairs/__tests__/repairShow.dark-mode.test.ts`: repair package selected-state contract.

## Verification

Run the focused Vitest contracts first, then the full frontend suite and Vite build. Use Playwright against the local application when the required server/auth data are available to inspect the three rendered dark-mode surfaces and confirm light mode remains unchanged.

