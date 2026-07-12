# Logistics Settings Dispatcher Access Design

## Goal

Make Logistics Settings discoverable and accessible to the seeded `Logistics Dispatcher` role.

## Design

- Add `configure-logistics-settings` to the `Logistics Dispatcher` permission list in `RolesAndPermissionsSeeder`.
- Add an ERP sidebar item named `Settings` using route `erp.logistics.settings` and path `/erp/logistics/settings`.
- Display that item only when the signed-in ERP user has `configure-logistics-settings`.
- Keep the controller and API authorization checks unchanged; they remain the enforcement boundary.

## Verification

- Extend the logistics seeder test to assert the dispatcher role receives the permission.
- Add or extend the smallest suitable frontend/sidebar test to assert the Settings item is permission-gated.
- Run the focused tests, the logistics feature suite, and the frontend build.

