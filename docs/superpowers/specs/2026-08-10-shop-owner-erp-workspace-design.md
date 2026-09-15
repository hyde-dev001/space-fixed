# Shop Owner ERP Workspace Design

**Date:** 2026-08-10

**Status:** Approved; ready for implementation
**Implementation prerequisite:** Complete the route catalog and review its generated capability matrix before making any operational route owner-capable.

## Goal

Give an approved company shop owner an ERP workspace that reuses the operational controllers, services, payloads, and React page components available to employees. The owner can perform valid shop-level operations—not only review records—across eligible and enabled business modules.

Cashier, CRM, Finance, HR, Inventory, Logistics, Manager, Procurement, Repairer, and STAFF are ERP navigation groups. They are not shop-module keys. Every page and action inside those groups maps separately to one or more existing shop-module keys such as `retail_operations`, `repair_operations`, `hr_employees`, `finance`, `crm`, `inventory`, `procurement`, or `logistics`.

The workspace preserves the employee self-service boundary. Time-in/out, personal leave or overtime submission, personal payslips, employee profile/password changes, rider-assigned delivery execution, and other actions whose subject is the authenticated employee remain employee-only.

## User experience

The shop-owner portal keeps its existing settings, approvals, notifications, and account-management navigation. Its Employee Modules section contains one **ERP Workspace** entry rather than duplicating every ERP page in the owner sidebar.

Selecting ERP Workspace opens an owner-specific ERP route namespace using the common ERP shell and page components. The shell displays:

- the authenticated owner identity and an **Owner mode** indicator;
- one clear **Back to Shop Owner Portal** action;
- owner-aware notifications, profile, and logout behavior;
- a workspace landing page showing enabled modules and unavailable modules with their reasons;
- a **Manage modules** link back to Shop Settings.

The sidebar hides routes the owner cannot use, but the workspace landing page keeps disabled and ineligible modules discoverable. The active route remains visibly selected, browser back behavior preserves state where practical, and navigation remains keyboard and mobile accessible.

A `both` business owner can use both Retail and Repair capabilities. Retail-only and repair-only owners see only their eligible operations. UI visibility is derived from server-provided capabilities and never acts as authorization.

## Route namespace and actor selection

### Unambiguous route families

Owner workspace pages use a dedicated route name and URI namespace, for example `shop-owner.erp.*` under `/shop-owner/erp/*`. Owner workspace APIs use an equivalent owner namespace where an existing API can otherwise be reached by both guards. State-changing owner APIs use the existing session and CSRF protections and retain applicable throttles.

Owner workspace routes are always registered so route topology and Laravel's route cache do not depend on a mutable feature flag. A dedicated workspace-feature middleware fails closed with a safe 404 while `SHOP_OWNER_ERP_WORKSPACE_ENABLED` is false, and the owner portal hides the entry point. Enabling or disabling the flag in an environment that uses cached configuration requires a configuration-cache refresh, but never a route-cache rebuild.

Employee operational routes retain their current names, URLs, `auth:user`, Spatie role/permission middleware, and behavior. Finance and CRM routes are included even though their current names use `finance.*` and `crm.*` rather than `erp.*`.

Owner routes delegate to the same controllers/actions and render the same Inertia components as their employee counterparts. The namespace duplicates only route declarations and actor-specific middleware; it must not duplicate business logic or introduce a second payload contract. A shared operation resolves `ErpActorContext` rather than reading `Auth::guard('user')`, `Auth::user()`, or the default request user directly. Direct employee-guard access remains only in employee-only behavior.

The route family selects the actor:

- an employee route resolves only the `user` guard;
- an owner ERP route resolves only the `shop_owner` guard;
- a client-supplied guard, owner ID, referrer, query parameter, or request body value never selects the actor;
- simultaneous employee and owner sessions do not change either route family's actor.

This design intentionally does not use `auth:user,shop_owner` for new owner workspace routes. Existing multi-guard routes may remain for unrelated behavior, but the owner workspace must not depend on ambiguous guard precedence.

### Shared ERP actor context

Introduce one Laravel request-scoped `ErpActorContext` using repository naming conventions. Bind it with request-scoped lifetime, not as a process-wide singleton. It contains:

- the selected `actor` and `guard`;
- the resolved `ShopOwner` tenant;
- `ownerMode`;
- route name, HTTP method, action classification, module keys, and gate mode;
- the evaluated route capability.

The context reuses `ShopModuleAccessService` for account, business, eligibility, and enabled-state decisions. It must not introduce a second module or business-type rules engine. Existing business-type middleware can remain on employee routes; owner routes use the catalog-driven capability decision.

Its public API distinguishes the authenticated actor from the tenant record:

- `actor()` returns the selected `User` or `ShopOwner`;
- `employeeActor()` returns the authenticated employee or null;
- `ownerActor()` returns the authenticated shop owner only in owner mode, otherwise null;
- `tenantOwner()` returns the `ShopOwner` that owns the current shop tenant for either route family;
- `isOwnerMode()` identifies owner mode without inferring from model types elsewhere.

All tenant queries use `tenantOwner()`. The ambiguous accessor name `owner()` is not used because it could mean either authenticated owner actor or tenant owner on employee routes.

The context never creates a synthetic employee, changes an employee session, or presents a `ShopOwner` model as a `User` model.

## Route capability catalog

`config/shop_modules.php` remains the only editable source of truth. Before route migration begins, the implementation must complete its catalog entries and produce a generated route-matrix review view from the catalog plus Laravel's loaded route collection. The generated view is not a second hand-maintained policy source. It is reviewed against every operational component's API dependencies and covers `routes/web.php`, `routes/api.php`, every additional route file loaded by `bootstrap/app.php`, and future loaded application route files.

The source hierarchy is:

1. `config/shop_modules.php` is the machine-readable route policy and review-metadata source;
2. the generated owner ERP route matrix is the only route-by-route review artifact;
3. other architecture documents explain concepts and link to the generated matrix, but contain no manually maintained route inventories.

Every internal route is classified as one of:

- `core`: authentication, the owner ERP landing page, general company oversight, or other access that is intentionally independent of a module toggle;
- `module`: operational access governed by one or more module keys;
- `excluded`: customer/public, SuperAdmin, system/queue, owner-portal-only, or employee self-service behavior.

Operational pages currently classified as `core` must be reviewed. The workspace/company dashboard and audit logs may remain company-owner core capabilities. Reports and module-specific manager controls use their underlying module gates. CRM, HR, inventory, finance, procurement, logistics, retail, and repair operations must not remain core merely because their routes predate the catalog.

### Catalog entry contract

Each named route entry declares:

- route name and HTTP method;
- classification and audience;
- module key(s) and gate mode: `single`, `all_of`, or `any_of`;
- allowed registration and business types when narrower than the module default;
- actor guard for that concrete route;
- a singular action classification such as `view`, `create`, `update`, `approve`, `reject`, `checkout`, `assign`, `upload`, or `delete`;
- `owner_access` as `allowed` or `denied`, plus `owner_denial_reason` when denied;
- descriptive `domain_rule` metadata naming the canonical policy/service/workflow rule; it never contains executable thresholds or duplicates domain authorization;
- review-only `risk_tier` metadata as `normal`, `sensitive`, or `financial`;
- the paired employee or owner route when both routes share an operation;
- navigation group and whether the route is employee self-service;
- supporting route names for owner-capable components;
- `actor_persistence` as `not_applicable`, `existing_owner_ref`, `paired_owner_ref`, or `polymorphic_actor`.

Owner policy and actor persistence are separate decisions. An employee-subject action uses `owner_access = denied`, a reason such as `employee_subject_required`, and `actor_persistence = not_applicable`; actor persistence is never overloaded to mean owner denial.

The generated matrix derives the employee rule from the loaded route's middleware and derives owner exposure from whether the declared owner pair is present in Laravel's loaded route collection. Neither is copied into hand-maintained catalog fields. This distinguishes an approved future owner policy from a route that is currently exposed in code.

Route name plus HTTP method is the authorization capability. A separate list of arbitrary `owner_actions` is not added when the route already identifies one action. An endpoint that accepts materially different domain actions through a payload must be split or explicitly catalog those payload actions.

Every owner-capable route and API must have a unique name. Unnamed operational routes are named before owner exposure.

Paired employee and owner routes use compatible parameter names, nesting, constraints, and binding semantics. A deliberate mismatch requires an explicit adapter recorded in the catalog and contract tests; shared components never guess how to translate route parameters.

The coverage test treats named application routes as catalog candidates. Public/customer, SuperAdmin, owner-portal-only, and system routes are cataloged as `excluded` or matched by a narrow explicit exclusion rule. Framework/vendor health or tooling routes may be exempted by exact name or URI. An unnamed owner/employee operational route or a broad prefix exclusion fails coverage.

### Required route matrix columns

The implementation artifact contains at least:

| Method | Employee route | Owner policy | Owner exposure/route | Component/controller | Supporting APIs | ERP group | Module gate | Business type | Employee rule | Domain rule | Risk | Actor persistence | Self-service |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `GET` | existing named route | allowed/denied + reason | derived absent/exposed + `shop-owner.erp.*` alias | reused implementation | named dependencies | navigation only | explicit keys/mode | explicit or inherited | derived middleware | descriptive reference | normal/sensitive/financial | N/A for read | yes/no |

The matrix, not the broad navigation table, decides whether an operation is owner-capable.

## Scope by ERP group

| Group | Owner-operable capabilities | Gate guidance |
| --- | --- | --- |
| Cashier | Retail and repair POS checkout, receipts, and eligible warranty actions | Retail POS uses `retail_operations`; repair POS uses `repair_operations` |
| CRM | Dashboard, customer records, support, and reviews | `crm`, plus source-module constraints where required |
| Finance | Dashboard, invoices, expenses, approvals, and payroll approvals | `finance`; source approvals may require `all_of` Finance and their source module |
| HR | Employee management, attendance monitoring, leave/overtime approvals, and payroll management | `hr_employees`; payroll approvals may also require `finance` |
| Inventory | Inventory dashboard, stock management, product inventory, movements, and stock requests | `inventory`; retail/repair-specific actions also declare the source capability |
| Logistics | Shipments, batches, dispatch, riders, and logistics settings | `logistics`; personal rider execution remains excluded |
| Manager | Company dashboard, reports, audit logs, suspension/rejection review, and manager controls | Dashboard/audit may be core; each report and operational control uses its underlying module |
| Procurement | Purchase requests/orders, stock-request approvals, receiving, and supplier management | `procurement`; inventory-derived actions may require `all_of` Procurement and Inventory |
| Repairer | Repair dashboard/job orders, warranty queue, services, materials, pricing, support, and repair POS | `repair_operations`; inventory-backed material actions may also require `inventory` |
| STAFF | Retail job orders, product upload, shoe pricing, inventory overview, customer/order operations, and repair status | Navigation group only; every child uses its underlying business module |

## Authorization model

### Owner route authorization

The following order is a tested invariant for classified ERP requests:

1. enforce the owner-workspace feature boundary before audience/authentication, actor resolution, or resource binding so disabled routes fail with the same safe 404;
2. resolve route/catalog audience without loading a tenant resource;
3. authenticate the one guard declared by the concrete route;
4. resolve the request-scoped `ErpActorContext` and `tenantOwner()`;
5. enforce owner policy, company registration, and `ShopOwnerStatus::APPROVED` for owner routes;
6. evaluate route business eligibility and module eligibility, then persisted module state when enforcement is enabled;
7. resolve route-bound or requested resources through the tenant context, returning 404 before revealing cross-shop existence;
8. run actor-aware Form Request authorization, canonical domain policies, and workflow/maker-checker/threshold/self-approval/idempotency/payment/upload rules;
9. perform the mutation;
10. persist the real actor and write the explicit owner-operation activity in the same database transaction and connection;
11. run external effects after commit or through an existing durable mechanism with defined retry/compensation behavior.

Authentication, company approval, owner allowlisting, and tenant isolation are permanent security controls. They are never bypassed when module enforcement or workspace rollout flags are disabled.

### Domain authorization

The route capability permits entry to an operation; it is not blanket authorization over all records or state transitions. Controllers, Form Requests, policies, and services continue to enforce:

- tenant ownership of every subject and related record;
- valid workflow state and transition;
- approval thresholds and assignment rules;
- maker/checker and self-approval restrictions;
- validation, upload limits, rate limits, and payment safeguards;
- existing business invariants.

Preserving a Form Request's payload and validation rules does not mean preserving employee-only authorization assumptions. Every owner-capable Form Request audits `authorize()`, `$this->user()`, explicit guards, route-model access, and custom validation callbacks. It resolves `ErpActorContext` or calls the canonical domain policy; it never selects an employee from a simultaneous session through the default guard.

For Finance, Procurement, payroll, refunds, pricing, suspension, and other approval workflows, the route matrix records whether an owner may create, review, approve, reject, or execute each stage. An owner may not create and approve the same record unless the existing domain policy explicitly permits owner self-approval.

Employee routes retain their current authorization behavior. No global modification to Spatie middleware is allowed.

A lightweight audience check runs before route authentication for classified ERP routes. If the route's allowed guard is authenticated, processing continues even when another guard also has a session. If the allowed guard is absent but another internal guard is authenticated, the request receives the route's explicit audience denial instead of being reinterpreted or redirected through the wrong login flow. This is how owner-only requests to employee self-service routes receive a stable 403 without breaking valid employee requests in dual-session browsers.

Missing owner authentication still uses Laravel's `auth:shop_owner` boundary, but owner ERP unauthenticated handling is customized: JSON requests receive the stable `ERP_AUTH_REQUIRED` body and status, while browser requests redirect to the shop-owner login rather than the generic employee login.

## Actor persistence

Audit attribution does not solve domain columns that currently reference `users.id`. Before enabling an owner mutation, the route matrix classifies each employee foreign key used by that operation:

- **Employee subject/participant:** fields such as requester, repairer, assignee, or personal approver that semantically require an employee remain employee-only. The owner route is excluded from that action.
- **Operation performer:** fields such as created by, updated by, confirmed by, or approved by may support an owner only after an explicit schema and model change records the real actor without inventing a user ID.
- **Legacy nullable performer:** a nullable employee column may remain null for an owner only when a separate durable owner actor reference exists and consumers do not interpret null as “unknown.” The activity log alone is insufficient when the domain record must retain the performer.

Prefer the smallest schema change consistent with the table's semantics: reuse an existing owner reference, add a paired `*_shop_owner_id`, or add a polymorphic actor reference only when both actor types are first-class domain participants. Do not retrofit one generic polymorphic abstraction across unrelated tables without a proven shared contract.

Non-null employee foreign keys are never filled with the owner's numeric ID. Any action that lacks a valid persistence strategy remains excluded until its migration and compatibility behavior are specified and tested.

For new paired employee/owner performer references, the canonical service or model boundary enforces valid new writes: exactly one actor reference when an actor is required, or the explicitly documented system/null state when the domain permits it. Tests reject both populated references and reject both null references when attribution is required. A database `CHECK` constraint is added only after a per-table legacy-data audit proves the invariant applies to all rows and the migration is safe on the supported SQLite test and MariaDB production versions; it is not imposed universally on nullable legacy/system fields.

## Shop isolation and route binding

Every owner read and mutation derives `shop_owner_id` only from `ErpActorContext::tenantOwner()`. Owner workspace endpoints do not accept `shop_owner_id`, `shop_id`, or equivalent client fields as authority-bearing input. When an existing shared payload retains such a field for backward compatibility, authorization ignores it and the server either normalizes it to `tenantOwner()->id` or rejects a semantic mismatch. It never loads the tenant with `ShopOwner::findOrFail($request->shop_owner_id)`.

Owner ERP routes replace the existing employee-oriented `shop.isolation` behavior with context-based isolation. Tenant-aware route model binding must execute only after the context is available, through middleware priority or an explicit scoped binding mechanism. Nested resources use scoped bindings where the relationships support them; other resources are queried through a tenant scope.

Cross-shop resource identifiers return `404 RESOURCE_NOT_FOUND`, including for records that exist in another shop. Route or action capability denial returns 403. Tests cover every route-bound resource family introduced into owner mode.

## Route and API flow

1. The owner selects ERP Workspace and enters the `shop-owner.erp.*` route family.
2. The workspace feature boundary fails closed before authentication or resource work while disabled.
3. Owner authentication resolves one request-scoped actor and tenant context.
4. Catalog capability middleware evaluates company status, route audience, business eligibility, and module state.
5. Tenant-scoped binding and domain authorization resolve the requested resources and transition.
6. The owner route calls the same controller/action or service used by the paired employee operation.
7. Inertia receives `auth.erpActor`, `ownerMode`, module states, and server-derived `erpCapabilities`.
8. Shared components use a capability URL resolver for page links and API endpoints instead of assuming employee route names.
9. A successful database mutation and its owner operation activity commit in one database transaction.
10. External work runs after commit or through the existing durable job/outbox convention.

Existing owner-specific APIs may be reused when they already use only the `shop_owner` guard, have correct tenant isolation, and share the same payload contract. A multi-guard API is not reused by the owner workspace until its actor selection is unambiguous. Otherwise an owner-namespaced alias delegates to the canonical action. Parallel controller logic or divergent payload shapes are not allowed.

## Frontend actor and capability contract

Shared Inertia props expose one resolved ERP actor shape for the current route:

- `erpActor.type`, `erpActor.id`, `erpActor.name`, and `erpActor.ownerMode`;
- `shopModules` and the enforcement state;
- `erpCapabilities`, keyed by catalog capability, with allowed state and server-generated URL;
- owner-aware notification, profile/logout, portal-return, and module-management URLs.

`erpCapabilities[*].allowed` means the current route actor, account, business type, and module state permit attempting that route. It does not promise that a particular record or transition is authorized. Resource ownership, record state, maker/checker, thresholds, validation, and other domain policies may still deny the request.

Owner mode never receives `permissions: ['*']` and never masquerades as a Shop Owner employee role. Existing employee props remain available for employee pages, but shared ERP components use `erpActor` for display and capability checks.

Every reused owner page must inventory its initial data and all network-producing behavior: direct fetch/axios calls, service helpers, polling and `setInterval`, React Query or equivalent refetch callbacks, WebSocket/event-triggered refetches, exports, downloads, print URLs, and image/file previews. A page is not owner-ready until every call either uses an owner-capable URL or intentionally handles a denied optional capability. Employee self-service buttons, tabs, links, and background requests are absent in owner mode.

## Audit logging

Every committed owner create, update, approve, reject, checkout, receipt, assignment, status transition, upload, delete, or other state-changing ERP operation writes exactly one explicit Spatie owner-operation activity. This is an operation-log invariant, not a claim that only one activity row may exist in the entire transaction; unrelated domain audit/event records remain intact.

Each activity includes:

- the authenticated `ShopOwner` causer;
- `actor_type: shop_owner` and `actor_guard: shop_owner`;
- `shop_owner_id`;
- module keys, route name, HTTP method, and action classification;
- a server-generated operation ID and correlation ID;
- the primary subject or aggregate type/id;
- safe old/new status or allowlisted changed fields;
- bounded related-record IDs/counts for bulk operations;
- IP address and a length-limited user agent.

Raw request bodies, passwords, tokens, private document bytes/paths, payment secrets, and unrelated personal data are excluded. Incoming correlation headers are accepted only from trusted infrastructure; otherwise the server generates the value.

The database mutation and activity use the same database transaction and connection. If the database mutation rolls back, its owner-operation activity rolls back. A retry that causes no new mutation creates no new owner-operation activity. Payment, checkout, approval, and other retry-sensitive operations retain or add their domain idempotency rules; a correlation ID is not an idempotency key.

An `ErpAuditService` or equivalently focused operation wrapper may coordinate the database transaction and explicit activity. Automatic Spatie model-event logging is suppressed only for events proven to duplicate the explicit owner-operation record, inside a callback scope that restores state even after an exception. Unrelated model/domain activity and all existing employee logs remain unchanged. External provider calls, file storage, mail, and queued work are not claimed to be atomic with the database; they run after commit or through an existing durable mechanism. Each affected operation defines its retry or compensation behavior so an external failure cannot silently misrepresent the committed database state.

Existing activity-log APIs and views are updated to filter the new safe properties by actor, module, route, action, and correlation ID while preserving tenant scoping.

## Error behavior

The stable authorization contract applies to ERP access errors only. Validation and domain errors keep their existing 422 or domain-specific contracts.

| Condition | JSON response | Owner browser response |
| --- | --- | --- |
| Workspace feature disabled | Safe generic 404 | Existing not-found behavior; no actor or resource resolution |
| Missing owner session | `401 ERP_AUTH_REQUIRED` | Redirect to the shop-owner login |
| Individual, pending, rejected, or suspended owner | `403 OWNER_ERP_ACCOUNT_INELIGIBLE` | Redirect to the owner portal with a safe explanation |
| Unclassified or owner-denied route/action | `403 ERP_ROUTE_NOT_ALLOWED` | Redirect to the ERP workspace landing page |
| Malformed or unknown module key in the runtime route catalog | `403 ERP_ROUTE_NOT_ALLOWED` | Redirect to the workspace landing page with a safe generic explanation |
| Known eligible module with missing required persisted state | `403 MODULE_STATE_MISSING` | Redirect to the workspace landing page with safe configuration guidance |
| Disabled module | `403 MODULE_DISABLED` | Redirect to the workspace landing page with module guidance |
| Ineligible module/business type | `403 MODULE_INELIGIBLE` | Redirect to the workspace landing page with eligibility guidance |
| Domain policy denial | `403 DOMAIN_ACTION_FORBIDDEN` | Return to the current page with a safe explanation |
| Cross-shop or missing resource | `404 RESOURCE_NOT_FOUND` | Render the existing safe not-found behavior |

JSON authorization errors contain `code`, `message`, `module_keys`, and `error`, where `error` repeats the stable code for backward compatibility. `module_keys` is always an array. Internal exception messages and stack traces are never returned.

The ERP boundary preserves the module service's known-state distinctions: missing required persistence returns `MODULE_STATE_MISSING`, ineligibility returns `MODULE_INELIGIBLE`, and a disabled row returns `MODULE_DISABLED`. An internal `UNKNOWN_MODULE` result indicates a malformed runtime catalog or route configuration and is normalized to public `ERP_ROUTE_NOT_ALLOWED` for owner ERP responses; existing non-workspace module-service contracts are unchanged.

An owner requesting an employee self-service route receives `403 ERP_ROUTE_NOT_ALLOWED` when the owner session is present; the request is never reinterpreted as an owner operation. No owner alias exists for self-service actions.

## Testing and acceptance criteria

### Route and catalog contract

- Every named internal route loaded by Laravel has a `core`, `module`, or `excluded` classification.
- Every route has an explicit owner policy; denied routes have a stable denial reason, and owner policy is not inferred from actor persistence.
- Every owner-capable route has a unique owner route name, one allowed guard, an action classification, and a paired operation when applicable.
- The matrix distinguishes owner policy from derived current exposure, and paired routes have compatible parameter names, constraints, nesting, and binding semantics or a tested explicit adapter.
- Every owner-capable component lists and classifies its supporting API routes.
- Operational routes incorrectly classified as core are reclassified before enforcement.
- The route-coverage test validates both directions: every catalog route is loaded and every loaded internal route is classified.
- No owner-capable route retains employee-only auth, permission, business-type, or isolation middleware.

### Backend behavior

- Unit tests cover scoped actor resolution, owner and employee route families, business type, company registration, status, module state, unknown routes, and simultaneous guard sessions.
- Owner ERP authentication tests prove JSON requests receive `ERP_AUTH_REQUIRED` and browser requests use the shop-owner login redirect.
- Every owner-capable page route receives an owner smoke request and renders its real Inertia component.
- Every materially different mutation pattern has a successful owner test; generated contract tests cover the remaining cataloged mutation routes.
- Tests deny approved Individual owners, pending/rejected/suspended company owners, wrong business types, disabled modules, owner-denied actions, and cross-shop resources.
- Tests distinguish missing persisted module state, known ineligible modules, known disabled modules, and malformed/unknown catalog module keys using the stable ERP mappings above.
- Every employee self-service route is denied to an owner by direct request.
- Actor-persistence tests prove owner IDs are never written into employee foreign keys, required durable owner references are populated, and invalid both-set/both-null performer states are rejected when the domain requires exactly one actor.
- Every owner-capable Form Request has tests for its actor-aware `authorize()`, guard selection, tenant-aware route data, and custom validation callbacks.
- Domain tests cover maker/checker rules, self-approval, invalid transitions, thresholds, validation, upload limits, and idempotent retries where applicable.
- Audit tests prove one explicit activity per committed owner operation, correct causer/tenant, safe properties, callback-scoped suppression, correlation/operation IDs, and rollback behavior.
- Existing employee role/permission, suspension, tenant, and self-service tests remain green.

### Frontend behavior

- The owner portal renders exactly one ERP Workspace entry.
- The workspace header shows the owner identity, owner-aware actions, and a clear portal return path.
- The landing page shows enabled and unavailable modules with reasons and a Manage modules action.
- The sidebar renders only server-allowed routes and highlights the current location.
- Owner links and API calls use server-derived capability URLs and render the actual shared ERP components.
- Owner mode exposes no wildcard permissions, employee self-service controls, or employee-only background requests.
- Polling, scheduled/refetch callbacks, event-triggered refreshes, exports, downloads, print links, and file/image previews use owner-capable URLs or remain disabled in owner mode.
- Mutation loading, success, validation, authorization, timeout, and safe error states are visible and accessible.
- Keyboard navigation, focus after route changes, mobile sidebar/back behavior, and disabled-state semantics are covered where applicable.

### Quality gates

Run focused Laravel and Vitest tests first, then the full relevant suites, `pnpm run build` for a fresh `public/build`, and `git diff --check`. Do not report TypeScript lint or type-check success unless that tooling exists and is run.

## Rollout

Use two independent flags:

- `SHOP_OWNER_ERP_WORKSPACE_ENABLED` controls owner workspace navigation and runtime access through fail-closed middleware;
- `SHOP_MODULE_ENFORCEMENT_ENABLED` continues to control persisted module-toggle enforcement.

Neither flag can bypass authentication, approved-company checks, route allowlisting, domain authorization, or tenant isolation to grant access. The disabled workspace boundary stops the request earlier with a safe 404.

Owner ERP routes are present in the route collection and route cache in both flag states. When the workspace flag is false, feature middleware returns safe not-found behavior before actor/resource work and the portal renders no entry. A cached production environment refreshes configuration with `php artisan config:cache` after either flag changes; changing the workspace flag never requires `route:clear` or `route:cache`.

Production must not enable `SHOP_OWNER_ERP_WORKSPACE_ENABLED` while `SHOP_MODULE_ENFORCEMENT_ENABLED` is false. The deployment check fails closed when that invariant is violated.

Roll out in this order:

1. complete and review the route capability matrix and actor-persistence decisions;
2. deploy reversible schema migrations and backfill required module/actor data;
3. deploy the always-registered backend routes, feature middleware, context, policies, and frontend build with the workspace flag disabled;
4. run the catalog and regression suites;
5. enable both flags in staging and verify an approved `both` owner, retail-only owner, repair-only owner, approved Individual owner denial, dual guard sessions, and an employee;
6. enable module enforcement in production after module-state verification and refresh cached configuration;
7. activate owner mutations in bounded, separately reviewed domain waves ordered by generated risk tier: `normal`, then `sensitive`, then `financial`;
8. enable the owner workspace flag and refresh cached configuration after the intended waves pass Gate D evidence.

Rollback disables only `SHOP_OWNER_ERP_WORKSPACE_ENABLED`, refreshes cached configuration, hides the owner entry point, and makes the always-registered routes fail closed. It does not rebuild route cache, weaken employee module enforcement, delete shop data, reverse committed audit history, or require synthetic user cleanup.

## Out of scope

- Employee impersonation or synthetic employee records.
- Replacing Spatie employee roles and permissions.
- A global polymorphic actor framework for unrelated tables.
- New business branches or multi-shop location switching.
- New payload contracts or duplicated owner business logic.
- Making employee self-service actions owner-capable.
