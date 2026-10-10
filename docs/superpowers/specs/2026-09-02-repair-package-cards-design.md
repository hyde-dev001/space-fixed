# Repair Package Analytics Cards Design

**Date:** 2026-09-02
**Status:** Approved

## Goal

Replace the repair dashboard's package analytics cards with the same shared metric-card treatment used by the other ERP dashboards, make the complete package analytics section readable in dark mode, and remove the generic `ERP module` eyebrow from account dashboard headers.

## Approved approach

Reuse `DashboardMetricCard` for the five existing package metrics: Packages, Bookings, Net Revenue, Avg Order, and Add-on Attach Rate. Keep the existing analytics endpoint, values, labels, tables, and chart data unchanged. Add dark-mode variants only to the package analytics surfaces, borders, table headers, text, and secondary rows.

The shared `DashboardShell` will not render an `ERP module` eyebrow by default. The standalone Procurement dashboard header and generic ERP module landing header will also omit that wording so no dashboard surface displays it.

## Interaction and accessibility

- Keep the five-card grid responsive: one column on small screens, two on medium screens, and five on extra-large screens.
- Keep each metric's server-provided value visible without relying on color alone.
- Preserve the existing package performance table, monthly trend list, recent booking list, and chart behavior.
- Keep the existing empty states and package analytics fetch behavior unchanged.
- Keep the dashboard title as the first text heading; do not replace the removed eyebrow with another generic label.

## Testing and delivery

Add a page-level regression test that resolves the analytics response and verifies the five shared metric cards plus the dark-mode package analytics surface. Run the focused repairer suite, the full frontend suite, a production Vite build, `git diff --check`, and push the fix to `feature/account-dashboards`.
