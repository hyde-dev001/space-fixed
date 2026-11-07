# Logistics Employee Seeder Design

## Goal

Seed one logistics dispatcher and one logistics rider for every shop owner when `EmployeeSeeder` runs.

## Design

Add two records to the existing `$commonEmployees` array in `EmployeeSeeder`:

- A logistics dispatcher using `logistics.dispatcher.{shop_owner_id}@solespace.com`, department `Logistics Dispatcher`, and position `Logistics Dispatcher`.
- A logistics rider using `logistics.rider.{shop_owner_id}@solespace.com`, department `Logistics Rider`, and position `Logistics Rider`.

Extend the existing department-to-role map so these departments receive the already-seeded Spatie roles `Logistics Dispatcher` and `Logistics Rider`. Their preferred legacy `users.role` values will be `LOGISTICS_DISPATCHER` and `LOGISTICS_RIDER`; the existing enum compatibility resolver will fall back to `STAFF` when those values are unavailable.

The records use the seeder's existing `updateOrCreate`, password, attendance, leave, salary override, and shop-owner association flow. No new roles, permissions, models, or migrations are required.

## Verification

Add one focused feature test that runs the relevant seeders for a shop owner and verifies both users and employees exist with the expected departments and Spatie roles. Run that test, then the existing logistics employee-role tests.
