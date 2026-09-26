# SoleSpace Admin Page-Based RBAC Design

## Goal

Allow regular `admin` accounts to receive explicit access to approved admin pages while keeping `super_admin` unrestricted, without replacing existing MFA, account-state, capability, validation, transaction, or audit safeguards.

## Existing architecture

- Privileged authentication uses the existing `super_admin` guard and `SuperAdmin` model.
- Account state is enforced by `super_admin.auth`, `privileged.active`, and `privileged.mfa`; sensitive mutations additionally use `privileged.recent`.
- Existing capability middleware remains the action/business-rule boundary.
- Privileged activity is recorded through `PrivilegedAudit` and displayed through the existing audit visibility service.
- “Shop Management” is the shop-registration/review workflow. “Registered Shops” is the approved-shop directory and lifecycle workflow.

## Authorization model

The backend owns page authorization through a central page catalog and middleware. The catalog contains these assignable keys:

`dashboard`, `user_management`, `shop_management`, `document_renewals`, `business_upgrade_requests`, `shop_reports`, `suspension_appeals`, `platform_fees`, `audit_history`, `registered_shops`, `subscription_management`.

`admin_management` and `system_maintenance` are intentionally non-delegable and are never stored as regular-admin permissions. A `super_admin` bypasses page rows but still passes existing account-state, MFA, reauthentication, capability, and business checks.

The mapping is stored in `admin_page_permissions` using `super_admin_id` (the actual privileged identity table), `page_key`, timestamps, a foreign key, and a unique `(super_admin_id, page_key)` constraint.

## Request and lifecycle behavior

- New regular-admin invitations accept a validated page-access array. Empty optional access is valid; the Dashboard remains available.
- Existing regular admins are backfilled with every assignable key, preserving their former unrestricted access. Super-Admin-only pages are excluded.
- Page-access updates run in a transaction and replace the complete set atomically.
- Unknown and non-delegable keys are rejected. Page-access changes cannot alter role, status, MFA, password, or security version.
- Promoting an admin to `super_admin` removes stored page rows; demoting to `admin` starts with no optional page rows until explicitly assigned.
- Only a Super Admin may assign access. Self-management restrictions remain in force.

## Route boundary

Page middleware wraps the full route family, including page GETs, actions, downloads, identity-verification media, subscription operations, platform-fee operations, and admin notification APIs where the notification links to an assignable page. Existing capability middleware stays on each route.

- `dashboard`: `/admin` and the current monitoring page, with server-filtered dashboard data.
- `user_management`: customer management, identity-verification review, customer ID media, and related actions.
- `shop_management`: registration review and registration-related document review links.
- `document_renewals`: renewal page and renewal actions.
- `business_upgrade_requests`: upgrade request page, actions, and documents.
- `shop_reports`: shop reports, flagged-account moderation, and report actions.
- `suspension_appeals`: appeal page and decisions.
- `platform_fees`: fee page and all fee settings, recommendation, shop-balance, and limit actions.
- `audit_history`: privileged audit page and compatibility redirect.
- `registered_shops`: approved-shop directory, details, and lifecycle actions.
- `subscription_management`: subscription page/history, plan management, and subscription intervention routes.
- `admin_management`: administrator management routes remain Super Admin-only.
- `system_maintenance`: maintenance page and all maintenance actions remain Super Admin-only.

## Presentation and data safety

Inertia shares only the authenticated role and safe page-key list. The sidebar filters pages and removes empty groups. The backend filters dashboard metrics/links so an admin cannot learn restricted module data by opening Dashboard. Notification serialization removes restricted actionable links and restricted admin notifications for unauthorized pages. Existing admin search is not a separate route; no new search surface is introduced.

## Audit

Page-access replacement records an existing privileged audit event containing target admin, actor, old access, new access, added keys, and removed keys. No credentials, tokens, MFA secrets, or recovery data are stored.

## Verification

Feature tests cover the page middleware and route families, backfill, assignment validation/atomicity, immediate revocation, account-state/MFA preservation, dashboard filtering, notification links, sidebar visibility, Super Admin bypass, and self-escalation protections. Existing privileged tests remain part of the regression run.
