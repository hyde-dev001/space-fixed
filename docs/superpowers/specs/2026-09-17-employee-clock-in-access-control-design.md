# Employee Clock-In Access Control Design

## Goal

Ensure every ERP employee account can view the system before clocking in but cannot perform business mutations until an active attendance record exists for the current shop day, while fixing the clock-in boundary so configured shop hours work through the configured closing minute.

## Scope

This applies to employee `User` accounts identified by a non-null `shop_owner_id`, including Staff, Manager, Cashier, Repairer, Finance, and Logistics accounts.

It does not apply to customer accounts or the shop-owner guard/portal.

## Approved behavior

- Safe read methods (`GET`, `HEAD`, and `OPTIONS`) remain available to employees.
- Employee business mutations using `POST`, `PUT`, `PATCH`, or `DELETE` are rejected when the employee has no active attendance record for the current shop timezone.
- An active attendance record has `check_in_time` and no `check_out_time`.
- Clock-in remains available before an active record exists.
- Clock-out, lunch actions, and attendance reason updates are available only after clock-in and are still validated by their existing controllers.
- Logout, password, and MFA/security maintenance remain available so employees cannot be trapped in an account workflow.
- Leave requests, overtime requests, orders, POS actions, inventory changes, repairs, approvals, uploads, and other business processing remain locked until clock-in.
- A blocked mutation returns HTTP `423 Locked` with `code: EMPLOYEE_NOT_CLOCKED_IN` and a message directing the employee to the Time In page.
- The ERP layout displays a read-only notice for an employee who is not actively clocked in, except on the Time In page itself.

## Clock-in boundary

Shop-hours settings are read from the authenticated shop owner's weekday fields
(`monday_open` through `sunday_close`), so the attendance page never assumes a
fixed schedule. The API returns the latest settings for the current shop
timezone day, and the Time In page refreshes them on load, window focus, and a
short polling interval while it is open.

Shop-hours settings are stored and displayed at minute precision. Both backend validation and the Time In page will compare normalized minutes rather than raw seconds.

For a configured close of `11:45 PM`, clock-in is allowed through `11:45:59 PM` and rejected from `11:46:00 PM`. The existing 30-minute early check-in window and overnight schedule behavior remain unchanged.

## Architecture

1. Add one employee clock-in middleware to the web/API middleware stacks. It exits immediately for non-employee users and safe read methods, allows only the explicitly approved account and attendance routes, and otherwise checks the current employee's attendance record scoped by employee email, shop owner, and shop timezone.
2. Reuse the existing `User::isEmployeeAccount()` classification and `AttendanceRecord` model; no migration or dependency is needed.
3. Share the current employee attendance state through Inertia props so `AppLayout_ERP` can render the read-only notice without duplicating page-level authorization logic.
4. Update the existing `TimeIn.tsx` clock-window helper and the controller's existing shop-hours comparisons to use minute precision.
5. Keep shop-hour reads dynamic: backend check-in validation reads the latest database values, while the page refreshes today's displayed schedule and eligibility when the user returns to the tab or the polling interval elapses.

## Request flow

```text
employee request
  -> employee clock-in middleware
      -> safe read / approved account or attendance action: continue
      -> active attendance record: continue
      -> otherwise: 423 EMPLOYEE_NOT_CLOCKED_IN
```

Frontend reads remain available so employees can inspect dashboards, records, and history. The server middleware is the enforcement boundary; the layout notice is the user-facing explanation and navigation aid.

## Error handling

- JSON/API requests receive a structured `423` response.
- Existing controller validation remains responsible for invalid attendance transitions, shop closure, leave conflicts, geofence failures, and other domain errors.
- The middleware must not run for customer accounts, shop-owner sessions, unauthenticated requests before authentication, or external/webhook requests that do not represent employee sessions.

## Verification

- Feature test: employee mutation is blocked before clock-in with the exact status and error code.
- Feature test: the same employee mutation is not blocked while actively clocked in.
- Feature test: customer authentication is not gated by the employee middleware.
- Feature test: configured closing minute accepts the final minute's seconds and rejects the next minute.
- Feature test: a shop with a different weekday schedule uses that day's configured hours, including closed-day behavior.
- Frontend test: the clock-in helper accepts the configured closing minute through second `59` and rejects the following minute.
- Frontend test: the attendance page refreshes shop hours without hardcoded opening or closing values.
- Run the focused Laravel and frontend tests, then the frontend build and final diff checks.

## Out of scope

- Redesigning individual ERP modules or manually editing every module button.
- Changing customer checkout behavior.
- Changing shop-owner permissions.
- Adding a new attendance table, dependency, or feature flag.
