# Logistics role navigation design

## Goal

Keep logistics employees in a clearly labeled `LOGISTICS` sidebar section while preserving the existing legacy `STAFF` value used by employee records.

## User-facing behavior

- Logistics Dispatcher keeps access to the logistics dashboard and dispatcher tools.
- Logistics Rider sees logistics delivery tools, including `My Deliveries`, but never sees a `Logistics Dashboard` sidebar entry.
- Logistics Rider requests to `/erp/logistics` redirect to `/erp/logistics/deliveries`.
- Logistics dashboard stats are not available to riders through the dashboard stats endpoint.
- Neither logistics role is grouped under a `STAFF` sidebar heading.

## Implementation boundaries

- Use the existing Spatie role names and permission payload; do not change the legacy employee `role` column or role seeder mappings.
- Split logistics items out of the shared staff item collection in `AppSidebar_ERP`.
- Keep the existing logistics controller permissions and tenant authorization, adding only the rider-specific dashboard boundary.
- Reuse the existing `My Deliveries` page and route.

## Acceptance criteria

1. Dispatcher sidebar renders `LOGISTICS`, includes `Logistics Dashboard`, and does not render `STAFF` for the logistics section.
2. Rider sidebar renders `LOGISTICS`, includes `My Deliveries`, and does not render `Logistics Dashboard` or `STAFF`.
3. A rider visiting `/erp/logistics` is redirected to `/erp/logistics/deliveries`.
4. Existing dispatcher logistics access tests and delivery flows remain passing.
5. Frontend tests, focused Laravel tests, frontend build, and diff checks pass; generated `public/build` is refreshed.
