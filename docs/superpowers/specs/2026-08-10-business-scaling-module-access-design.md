# Business Scaling and Shop Module Access Design

**Date:** 2026-08-10

**Status:** Pending written-spec review

## Goal

Allow an Individual shop owner to request a Business account upgrade and, when eligible, expand the shop from Retail or Repair capability to Both. After Super Admin approval, the shop owner can control which eligible modules are open for the shop from Shop Settings.

Business shop owners can open every page inside an enabled module. Employees remain subject to their existing role and permission assignments, while the shop-level module switch acts as the master gate for everyone in that shop.

## Current context

- The shop owner record already stores registration_type with the values individual and company. The customer-facing label for company will be Business; the stored value will not be renamed.
- The shop owner record already stores business_type with the values retail, repair, and both. This describes business capabilities and is separate from account status.
- ShopOwner::canManageStaff() and BusinessAccessControlService currently treat company registrations as eligible for staff and ERP access.
- UserAccessControlController already blocks Individual staff-management access and returns an upgrade prompt.
- Shop-owner pages use the shop_owner guard, while employee/ERP APIs commonly use the user guard. Owner access must be added explicitly without impersonating an employee or weakening the user guard.
- Shop Settings already has an Inertia page and ShopSettingsController for account, business, approval, payroll, and operational settings.

## Approved product decisions

### Account and business upgrades

The upgrade flow is available from Shop Settings.

Allowed account transition:

- individual -> company
- company -> company, with no repeat upgrade
- no downgrade in this release

Allowed business capability transitions:

- retail -> both
- repair -> both
- both -> both, with no change
- retail <-> repair is not an upgrade and is out of scope
- no downgrade from both in this release

An owner may request the account transition, the business capability expansion, or both in one request. The existing capability is preserved while the request is pending.

Every upgrade request requires Super Admin approval. A pending request must not unlock Business-only access, employee pages, or newly requested business modules. Approval updates the requested values atomically. Rejection leaves the current values unchanged and records the reason.

After rejection, the owner may submit a new request. A new request must capture the then-current account and business values rather than reusing the rejected request.

The request flow reuses the existing shop-owner document and notification conventions. The exact required documents remain governed by the current registration/document rules; an upgrade cannot bypass existing document validation or Super Admin review.

### Module access

Add a Shop Settings section named Modules or Feature Access. It shows the shop's eligible modules, current state, and the reason an ineligible module cannot be enabled.

The initial module catalog should cover the existing application boundaries:

- Retail Operations
- Repair Operations
- HR and Employees
- Finance
- CRM
- Inventory
- Procurement
- Logistics

Dashboard, Shop Profile, Shop Settings, authentication, and notifications remain core access and cannot be disabled.

Eligibility is determined by the approved account and business values:

- Company-only modules require registration_type = company.
- Retail modules require business_type = retail or both.
- Repair modules require business_type = repair or both.
- Shared modules declare their own account and business eligibility in the module catalog.

On approval of a new Business account or business capability, all newly eligible modules default to enabled so existing functionality is not unexpectedly lost. The owner may disable any eligible non-core module afterward.

Disabling a module:

- preserves all module data;
- blocks its page routes and API endpoints for the shop owner and employees;
- hides its navigation items;
- leaves employee role and permission assignments intact;
- restores access when the module is enabled again.

Module toggle updates are validated, persisted atomically, and audited with the acting shop owner and old/new state.

The shop-level switch is not an employee permission editor. Employee access remains the intersection of the shop module being enabled and the employee's existing role/direct permissions.

Existing product and repair-service records remain attached to the same shop_owner_id throughout an upgrade. After Super Admin approval, authorized Inventory employees can access existing products when Retail Operations or Inventory is enabled, and authorized Repair employees can access existing repair services when Repair Operations is enabled. No re-upload or data migration is required. Pending or rejected upgrades do not grant new employee access.

### Owner page access

A Business shop owner can open every page belonging to an enabled module, including employee and HR pages. Owner actions execute as the shop owner against the selected shop/employee context; they do not create an employee session or impersonate an employee.

Employee self-service actions remain identity-sensitive. For example, an employee check-in or personal leave submission must continue to use the authenticated employee's user identity. Owner-facing administrative actions should be used for owner changes to employee records, attendance, leave, payroll, documents, and permissions.

## Proposed architecture

### Upgrade requests

Introduce a dedicated shop-owner upgrade request record rather than overwriting the current shop owner values before approval. The record should contain:

- shop_owner_id;
- current registration and business values captured at submission;
- requested registration and business values;
- status: pending, approved, or rejected;
- rejection reason;
- reviewing Super Admin and review timestamp;
- created and updated timestamps.

Only one pending request may exist for a shop owner. Approval and rejection must use a transaction and row locking so duplicate reviews cannot apply conflicting changes.

Approval should:

1. lock the shop owner and pending request;
2. verify the request is still valid and the target transition is allowed;
3. update registration_type and/or business_type;
4. initialize newly eligible module rows as enabled;
5. mark the request approved and record the reviewer;
6. write an auditable account/module change;
7. notify the shop owner.

Rejection should mark the request rejected, store the reviewer reason, and notify the shop owner without changing account capabilities.

### Module persistence and access service

Persist shop-level module state in a dedicated shop-owner module table with a unique shop_owner_id/module_key pair, an enabled flag, and timestamps. This keeps module state queryable, avoids overloading unrelated settings JSON, and supports adding modules without another schema change.

Use a single module catalog as the source of truth for:

- module key and display label;
- account/business eligibility;
- core/non-core status;
- navigation grouping;
- route/API middleware key.

Add a focused module-access service that:

- resolves the current shop owner from the shop_owner or user guard;
- checks account and business eligibility using the existing BusinessAccessControlService;
- checks the persisted shop-level enabled state;
- returns the reason for denied access;
- resolves owner versus employee actor context.

Keep account/business eligibility and module-enabled state separate. A module cannot be enabled when the account is not entitled to it.

### Backend enforcement

Add module-aware middleware or an equivalent route guard to the existing owner and employee route groups. Every protected page and API endpoint must enforce the module gate server-side. Frontend navigation is only a presentation layer.

Owner-authenticated requests must be supported explicitly for pages and actions intended for the shop owner. Employee-authenticated requests must retain the current Spatie permission checks. Shared controllers/services may resolve a shop context, but must not treat a ShopOwner model as a User model or grant employee identity actions implicitly.

Every employee/module query and mutation must remain scoped to the authenticated shop_owner_id. A valid owner or employee from one shop must not read or change another shop's data.

Disabled or ineligible modules should return:

- a 403 response with a stable message for JSON/API requests;
- a redirect to Shop Settings or the shop dashboard with a user-facing explanation for Inertia/page requests.

### Frontend behavior

Extend the existing Shop Settings payload with:

- upgrade request status and available transition options;
- module catalog entries;
- eligibility, enabled state, and disabled reason.

The Shop Settings page should provide:

- the current account and business status;
- an upgrade request form;
- pending, approved, and rejected request feedback;
- a resubmission path after rejection;
- module toggles for eligible non-core modules;
- clear disabled explanations for modules that require Business, Retail, or Repair capability.

Update shared auth/page props and the shop-owner and ERP sidebars to use the same enabled-module payload or helper. Do not duplicate eligibility rules separately in each component.

After a successful toggle, the UI should refresh its module state before enabling navigation. The backend remains authoritative for all route access.

## Error handling and safety

- Duplicate pending upgrade requests are rejected without changing current access.
- A stale or already-reviewed request cannot be approved twice.
- Invalid transitions, such as Retail to Repair or Both to Retail, return validation errors.
- A module toggle cannot enable an ineligible module.
- Disabling a module never deletes employees, permissions, transactions, or historical records.
- Approval and module changes are logged with actor, shop, old value, new value, and timestamp.
- Sensitive employee and payroll actions remain subject to existing authorization and tenant-isolation rules.

## Verification contract

### Upgrade workflow

- An Individual can submit an allowed Business upgrade request from Shop Settings.
- Retail/Repair to Both requests are accepted; Retail/Repair swaps and downgrades are rejected.
- Pending requests do not change registration, business type, module eligibility, or employee access.
- Super Admin approval atomically applies the requested values and seeds newly eligible modules.
- Super Admin rejection preserves the current values and exposes the rejection reason.
- A rejected request can be resubmitted using the then-current account and business values.
- Duplicate and stale review attempts are safe and do not create conflicting state.

### Module access

- Only eligible modules can be enabled.
- Core settings and account pages remain available.
- A disabled module is blocked at both page and API layers for the shop.
- Re-enabling a module restores access without restoring or changing employee permissions.
- Module toggle changes are atomic and auditable.
- Employee access requires both an enabled shop module and the employee's existing permission.
- Business shop owners can open every page in enabled modules through an explicit owner-authenticated path.
- Existing products and repair services remain available to authorized employees after approval without re-upload or migration.
- Pending upgrades do not expose existing shop data to newly intended employee roles.
- Cross-shop access is rejected for owners and employees.

### Regression coverage

- Existing company shop-owner staff management continues to work.
- Existing Individual restrictions continue to work until approval is completed.
- Existing retail/repair/both business-type restrictions continue to work.
- Existing shop settings, approval-page settings, payroll settings, and document behavior remain intact.
- Frontend tests cover upgrade status, module toggle states, disabled navigation, and pending/rejected feedback.
- Feature tests cover authorization, tenant isolation, approval transactions, and module middleware.

## Out of scope

- Billing, subscription pricing, or payment collection for the upgrade.
- Arbitrary Retail-to-Repair switching.
- Downgrading Business accounts or Both capability.
- Deleting or migrating module data when a module is disabled.
- Employee impersonation.
- Replacing the existing role/permission system.
- New business branches or multi-shop location management.

## Likely implementation areas

- Shop owner upgrade request model, migration, validation, controller, notifications, and Super Admin review surface.
- Shop-owner module state model/migration, module catalog, access service, and middleware.
- ShopSettingsController, existing Shop Settings Inertia page, shared auth props, and sidebars.
- Owner/employee HR and ERP route groups and controllers that currently assume only the user guard.
- Feature and frontend regression tests around BusinessAccessControlService, staff management, module access, and tenant isolation.
