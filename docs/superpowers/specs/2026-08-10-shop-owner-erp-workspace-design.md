# Shop Owner ERP Workspace Design

**Date:** 2026-08-10  
**Status:** Approved for implementation

## Goal

Give an approved company shop owner an ERP workspace that uses the same operational pages and components available to employees. The owner must be able to perform valid shop operations—not only review records—across every eligible and enabled module: Cashier, CRM, Finance, HR, Inventory, Logistics, Manager, Procurement, Repairer, and STAFF.

The workspace must preserve the employee self-service boundary. Personal employee actions such as time-in, personal leave/overtime requests, personal payslips, and employee profile/password actions remain employee-only.

## User experience

The shop-owner portal keeps its existing settings, approvals, notifications, and account-management navigation. Its Employee Modules section contains one **ERP Workspace** entry rather than duplicating every ERP page in the owner sidebar.

Selecting ERP Workspace opens the common ERP shell and sidebar. The owner sees the same module groups used by employees, filtered by:

- approved company registration;
- retail/repair business eligibility;
- the persisted shop-module toggle state;
- the owner ERP route/action allowlist.

The ERP shell provides a clear **Back to Shop Owner Portal** action. A `both` business owner can use both Retail and Repair groups; retail-only and repair-only owners see only their eligible operational groups.

## Scope by ERP group

| Group | Owner-operable capabilities |
| --- | --- |
| Cashier | Retail and repair POS checkout, receipts, and eligible warranty actions |
| CRM | Dashboard, customer records, support, and reviews |
| Finance | Dashboard, invoices, expenses, approvals, and payroll approvals |
| HR | Employee management, attendance monitoring, leave/overtime approvals, and payroll management |
| Inventory | Inventory dashboard, stock management, product inventory, movements, and stock requests |
| Logistics | Shipments, batches, deliveries, riders, and logistics settings |
| Manager | Dashboard, reports, audit logs, suspension/rejection review, and manager inventory/product controls |
| Procurement | Purchase requests/orders, stock-request approvals, and supplier management |
| Repairer | Repair dashboard/job orders, warranty queue, services, repair materials, pricing, support, and repair POS |
| STAFF | Retail job orders, product upload, shoe pricing, inventory overview, customer/order operations, and repair status |

The route inventory is authoritative. A route is owner-capable only when its catalog entry explicitly includes the `shop_owner` actor guard and an eligible module gate. Customer/public, SuperAdmin, system/queue, and employee self-service routes remain excluded.

## Actor and authorization model

### Shared ERP actor context

Introduce one request-scoped `ErpActorContext` (name may follow repository conventions) that resolves:

- `user` guard: existing employee role/permission behavior and its linked `shop_owner_id`;
- `shop_owner` guard: the authenticated approved company owner;
- `actor`, `guard`, `shopOwner`, `ownerMode`, and the current module/action capability.

The context never creates a synthetic employee and never changes the active employee session. Existing employee requests retain their current authorization and behavior.

### Owner authorization

Owner ERP access is an explicit route/action allowlist, not a blanket bypass of `permission` middleware. For owner requests, the shared ERP authorization middleware verifies:

1. authenticated `shop_owner` context;
2. approved, non-suspended company registration;
3. valid retail/repair business type for the route;
4. enabled and eligible module state;
5. owner-capable route/action classification.

The middleware sets the resolved context on the request so closures, controllers, services, and API endpoints use one shop scope. Existing employee permission checks still apply to employee requests.

For owner mode, the source of truth for account access is `ShopOwnerStatus::APPROVED`; `PENDING`, `REJECTED`, and `SUSPENDED` are denied. Employee suspension remains governed by the existing employee/user suspension middleware and is not conflated with the shop-owner status.

### Route/API migration strategy

The existing route files are not assumed to become owner-capable merely because a context service exists. The implementation will enumerate every page and API route in `routes/web.php`, `routes/api.php`, `routes/finance-api.php`, `routes/hr-api.php`, `routes/inventory-api.php`, `routes/procurement-api.php`, `routes/permission-audit-api.php`, and `routes/shop-owner-api.php`, plus any route file loaded by `bootstrap/app.php`. The route-coverage test discovers loaded routes from Laravel's route collection and fails on an unclassified internal owner/employee route, so adding a future route file cannot silently bypass the inventory.

For each route that belongs to an owner-operable ERP capability:

1. its `config/shop_modules.php` route entry declares `actor_guards: ['shop_owner', 'user']`, the module gate, and an explicit `owner_actions` allowlist;
2. its `auth:user` middleware is replaced by the shared ERP actor authentication middleware;
3. its `permission:*`, `role:*`, or `role_or_permission:*` middleware is replaced by the shared ERP capability middleware, which delegates to the existing employee permission rule for `user` actors and checks the catalog allowlist for owners;
4. its `check.user.business.type` or shop-owner-only business middleware is replaced by an actor-aware business-type check that preserves the current employee semantics and normalizes the owner business type;
5. its closure/controller/service resolves `ErpActorContext` instead of calling `Auth::guard('user')` directly;
6. its matching API route receives the same actor/module/action contract.

Employee self-service routes retain their existing `auth:user` and permission middleware. No global modification to Spatie's permission middleware is allowed. A route-coverage test fails if an owner-capable catalog entry still has an employee-only middleware chain or if an internal route is unclassified.

### Shop isolation

Every owner read and mutation derives `shop_owner_id` from the authenticated context. Client-supplied owner/shop IDs are ignored for authorization; if a legacy request includes a shop ID, the context middleware normalizes it to the authenticated owner or rejects a mismatch without consulting a null `User::shop_owner_id`. Route model binding and resource queries are scoped to the resolved owner. Cross-shop IDs return 404/403 according to the existing resource convention.

## Route and API flow

1. Owner selects ERP Workspace.
2. A core owner-capable workspace route renders the common ERP shell and passes `ownerMode` plus module state through Inertia.
3. Existing `erp.*` page routes are reused; the route catalog is updated only for explicitly owner-capable ERP pages.
4. Shared actor authentication runs before module/action authorization.
5. Page closures/controllers resolve `ErpActorContext` instead of calling `Auth::guard('user')` directly.
6. Inertia page data and API endpoints use the same context and shop scope.
7. A successful write commits its data change and audit entry in one transaction.

The API route inventory receives the same actor classification as the page route it supports. Existing owner-specific APIs may be reused where they already implement correct shop isolation; otherwise the canonical ERP endpoint is adapted to the shared context instead of creating a parallel payload contract.

## Audit logging

Every owner create, update, approve, reject, checkout, receipt, assignment, status transition, upload, delete, and other state-changing ERP action writes one Spatie activity entry in the same database transaction.

Each activity includes:

- `caused_by`: the authenticated `ShopOwner` model;
- `actor_type: shop_owner` and `actor_guard: shop_owner`;
- `shop_owner_id`;
- module key, route name, action name, and target model/type/id;
- safe old/new status or allowlisted changed fields;
- correlation ID, IP address, and user agent.

Raw request bodies, passwords, tokens, private document bytes/paths, payment secrets, and unrelated personal data are excluded. Employee activity logging remains unchanged. Existing audit-log views can filter owner actions by actor, module, route, or correlation ID.

If a data mutation rolls back, its activity rolls back too. A committed mutation must never silently lose its audit record.

Owner mutations use one explicit `ErpAuditService`/operation wrapper. It assigns an operation ID and correlation ID, executes the mutation and audit in one transaction, and records exactly one owner operation entry. For models with automatic Spatie model-event logging, the owner operation temporarily suppresses that automatic event and records the explicit entry instead; existing employee mutations and their current logs are unchanged. Existing ad-hoc owner ERP logs are migrated to this wrapper rather than layered on top of it.

## Error behavior

- Anonymous or invalid actor context: existing authentication response.
- Unapproved/suspended owner: safe 403 or owner-portal redirect.
- Unknown/unclassified route or module: 403 with a stable authorization code.
- Disabled/ineligible module: 403/redirect with a user-safe explanation and no mutation.
- Cross-shop resource: existing 404/403 behavior without leaking another shop's data.
- Employee self-service route requested by an owner: 403; never reinterpret it as an owner action.

JSON clients receive stable `code`, `message`, and `module_keys` fields while retaining the existing `error`/`message` keys for backward compatibility. Browser navigation uses the existing safe redirect conventions. The response contract is covered by middleware and API feature tests.

## Testing and acceptance criteria

### Backend

- Unit tests cover actor resolution, owner mode, employee mode, business type, approval/suspension, module state, and unknown routes.
- Data-driven feature tests cover every ERP group with an owner page request and at least one representative owner mutation.
- Tests prove disabled modules, wrong business types, pending/rejected owners, and cross-shop resources are denied.
- API tests prove owner session context, shop isolation, validation, and write behavior.
- Audit tests prove exactly one activity per owner mutation, correct `ShopOwner` causer/guard, safe properties, correlation IDs, and rollback behavior.
- Existing employee role/permission and self-service tests remain green.

### Frontend

- Owner portal renders exactly one ERP Workspace entry.
- ERP sidebar renders all eligible/enabled groups and filters toggled or ineligible groups.
- Owner links resolve to the canonical ERP URLs and render the actual ERP page components.
- Owner mutation success/error states are visible and do not expose internal exception details.

### Quality gates

Run focused Laravel/Vitest tests first, then the full relevant suites, `pnpm run build` for a fresh `public/build` (use the repository-approved fallback only when pnpm is unavailable), and `git diff --check`.

## Rollout

Keep `SHOP_MODULE_ENFORCEMENT_ENABLED=false` until migrations/backfill and owner-module verification succeed. Deploy backend route/context changes and the fresh frontend build together. Verify an approved `both` owner, a retail-only owner, a repair-only owner, and an employee before enabling enforcement. Rollback is a flag/route-access rollback and does not delete shop data or audit history.
