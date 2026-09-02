# Logistics role navigation implementation plan

## Context

The legacy employee `role` field is `STAFF` for logistics employees, while the Spatie role identifies `Logistics Dispatcher` and `Logistics Rider`. The ERP sidebar currently uses shared staff permissions/items, so both roles are labeled `STAFF`; the rider also inherits the logistics dashboard permission and can reach the dashboard route directly.

## Plan

1. Add failing frontend sidebar tests for dispatcher and rider role payloads.
2. Update the existing rider feature test to require a dashboard redirect to `My Deliveries`.
3. Add a dedicated logistics navigation group and role-aware filtering to `AppSidebar_ERP`.
4. Add a controller boundary that redirects riders away from the dashboard and denies dashboard stats access.
5. Run focused tests, the complete frontend suite, the frontend build, and review/diff checks.
6. Commit and push the source, tests, documentation, and fresh `public/build` output.

## Constraints

- Preserve the legacy `STAFF` database value and existing role seeder mappings.
- Do not change dispatcher permissions or delivery workflows.
- Use existing routes, components, and styling tokens.
- Keep the change limited to logistics role navigation and dashboard access.
