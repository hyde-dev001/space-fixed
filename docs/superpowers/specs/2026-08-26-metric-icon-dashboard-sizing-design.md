# Metric Icon Dashboard Sizing

## Goal

Make the primary icon treatment on every ERP and shop-owner metric card match the shop-owner dashboard: a 48×48 pixel tile containing a 24×24 pixel icon.

## Root Cause

`EcommerceMetrics` already uses a 48×48 tile and 24×24 icon. Legacy page-local metric cards commonly use a 56×56 tile and 28×28 icon. The previous shared rule reduced stroke width but did not reduce these dimensions, so the legacy icons still looked visually heavier.

## Design

Extend the existing shared metric-card selectors in `resources/css/app.css` instead of editing each page-local component. For legacy primary gradient icon tiles, enforce:

- width and height: 48px;
- border radius: 12px (`rounded-xl` equivalent);
- direct child SVG width and height: 24px;
- existing 1.5 stroke-width treatment remains in place.

The selector stays scoped to primary icon tiles inside existing metric-card patterns. Trend arrows, badges, table icons, filters, and action icons remain unchanged. The canonical `EcommerceMetrics` component already has the target dimensions and requires no component change.

## Scope

- Update `resources/css/app.css` only for source behavior.
- Regenerate `public/build` after verification.
- Preserve and exclude unrelated working-tree changes.

## Verification

- Add a focused source regression test that proves the shared CSS contains the approved tile and SVG dimensions and remains scoped to metric cards.
- Run the focused test, full frontend tests, Vite build, manifest asset validation, and `git diff --check`.
- Confirm the generated CSS contains the 48px/24px metric icon rules.
