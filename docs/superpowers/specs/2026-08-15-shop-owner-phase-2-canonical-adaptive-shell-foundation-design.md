# Shop Owner Phase 2 — Canonical Adaptive Shell Foundation Design

**Date:** 2026-08-15

**Status:** Approved focused design; implementation plan pending user review

**Program:** Shop Owner Canonical Adaptive Shell

**Phase:** 2 of 6

## 1. Purpose

Establish one canonical Shop Owner shell and permanent shell-destination URL topology without rewriting mature domain pages, changing authorization, or prematurely implementing the Phase 3 Home and Action Center.

Phase 2 replaces competing navigation logic for an approved rollout cohort. It does not remove the existing Shop Owner presentation or Shop Owner ERP workspace. Existing controllers, components, APIs, domain services, and compatibility routes remain reusable and authoritative throughout the rollout.

The implementation principle is:

> **Compose presentation from authoritative sources; do not create another permission system.**

The core product principle remains:

> **One owner shell, adaptive emphasis by operating context.**

## 2. Relationship to Existing Designs

This specification implements Phase 2 of [`2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md`](./2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md).

It assumes the contracts in [`2026-08-15-shop-owner-phase-1-state-responsibility-correctness-design.md`](./2026-08-15-shop-owner-phase-1-state-responsibility-correctness-design.md) are implemented and verified before canonical-shell rollout expands.

It preserves the following existing contracts except where this specification explicitly changes owner-facing topology:

- [`2026-08-10-shop-owner-erp-workspace-design.md`](./2026-08-10-shop-owner-erp-workspace-design.md) remains authoritative for ERP actor context, route-audience classification, tenant isolation, actor persistence, and existing ERP authorization;
- [`2026-08-10-shop-owner-erp-module-scoped-navigation-design.md`](./2026-08-10-shop-owner-erp-module-scoped-navigation-design.md) remains authoritative for transitional ERP module navigation and route-catalog behavior;
- [`2026-08-10-business-scaling-module-access-design.md`](./2026-08-10-business-scaling-module-access-design.md) remains authoritative for registration type, business type, module eligibility, enabled state, and scaling transitions;
- [`2026-08-11-owner-erp-operational-parity-fixes-design.md`](./2026-08-11-owner-erp-operational-parity-fixes-design.md) and later focused domain designs remain authoritative for owner ERP behavior;
- Finance, HR, Inventory, Procurement, Logistics, CRM, Retail, Repair, payment, Refund, Payroll, compliance, and audit designs remain authoritative for their respective domain behavior.

This specification supersedes the current Shop Owner sidebar as the navigation authority only when the canonical presentation is selected successfully for the request. It does not supersede employee ERP navigation or authorization.

## 3. Scope

### In scope

- a canonical owner shell and eligibility-aware navigation;
- a server-controlled rollout policy with a global kill switch and explicit shop allowlist;
- a server-owned shell adapter that composes existing authoritative decisions;
- presentation context derived from existing registration type;
- stable canonical owner-facing URLs;
- route and page reuse behind canonical destinations;
- individual/owner-operated and company presentation emphasis;
- canonical Home framing around the existing Shop Owner dashboard;
- informational placeholders for Phase 3 Required Actions and Exceptions;
- distinct Reports and Audit capabilities;
- a grouped Business Settings destination and canonical setting sub-routes;
- authorized deep-link access to de-emphasized destinations;
- an ERP-workspace compatibility fallback during controlled rollout;
- presentation selection and fallback observability;
- complete-presentation fallback and UI-topology rollback;
- accessibility, responsive behavior, performance, and route/UI parity verification.

### Out of scope

- Action Center aggregation or an `OwnerAttentionItem` model;
- approval, exception, notification, or domain-status aggregation for Home;
- approval-policy simplification;
- owner-facing ERP workspace retirement;
- legacy route retirement or broad compatibility redirects;
- wholesale canonicalization of domain detail, record-specific, workflow-step, or mutation URLs;
- domain controller, service, table, or state-machine rewrites;
- changes to module eligibility, capabilities, tenant authorization, or domain policy;
- a generic navigation, feature-flag, workflow, or authorization engine;
- a persisted `microbusiness` type or behavior-derived operating profile;
- an owner-selectable presentation mode;
- percentage rollout or owner self-opt-in;
- employee ERP shell changes;
- primary consolidation work reserved for Phase 5.

## 4. Selected Approach

Phase 2 uses **Approach 1 — Server-owned shell adapter**.

```text
Existing authoritative sources
├─ Shop Owner registration type
├─ ShopModuleAccessService
├─ ErpRouteCatalog
└─ ErpWorkspaceNavigationService
             |
             v
Canonical owner shell adapter
             |
             v
Presentation metadata only
             |
             v
React canonical shell
```

The adapter may compose, order, label, group, and map existing capabilities. It may not invent a capability decision that does not already exist in an authoritative source.

Alternatives were rejected because:

- expanding `config/shop_modules.php` into a rollout, routing, module, and information-architecture mega-catalog would mix unrelated responsibilities;
- composing navigation and eligibility in React would reproduce the current client/server drift and create a second practical capability catalog.

## 5. Responsibility Boundaries

Phase 2 introduces two small server-side responsibilities.

### 5.1 Canonical Owner Shell Rollout Policy

The rollout policy determines which complete presentation the request receives.

It:

- reads a global canonical-shell flag;
- checks a stable Shop Owner shop identifier against an explicit server-controlled allowlist;
- returns the canonical or existing presentation with a stable selection category;
- falls back to the existing presentation on evaluation failure.

It does not:

- modify module state;
- grant or revoke a capability;
- change route registration;
- inspect owner email or mutable profile fields;
- infer behavior;
- expose allowlist membership as a business setting.

### 5.2 Canonical Owner Shell Service

The shell service composes the selected canonical presentation.

It:

- derives presentation context from registration type;
- reads existing module, route, and workspace-navigation decisions;
- maps eligible capabilities to stable canonical destinations;
- orders groups and determines default expansion;
- omits empty groups;
- provides stable unavailable reasons where an unavailable destination is intentionally represented;
- decides whether the secondary ERP fallback is present;
- returns bounded presentation metadata.

It does not:

- authorize a route or mutation;
- query approval, exception, notification, or domain work queues;
- duplicate module or route decisions;
- perform domain mutations;
- become a generic navigation system for other actor types.

The service may reuse module, route, audience, and capability decisions exposed by ERP services, but it must not treat the Shop Owner ERP workspace feature flag as capability eligibility. That flag controls only the transitional ERP workspace and fallback presentation. Disabling the ERP workspace must not independently remove canonical Finance, Workforce, Inventory, Procurement, Logistics, or other eligible shell destinations.

### 5.3 Existing authoritative layers

Existing middleware, policies, module services, route-audience checks, tenant checks, controllers, requests, and domain services remain authoritative for access and behavior.

> **The canonical shell adapter composes presentation from existing authorization and module sources; it does not redefine them.**

> **A shell item references an existing capability or module decision wherever one exists. The shell decides where and how that capability is presented, not whether the actor may perform it.**

## 6. Request and Data Flow

```text
Authenticated Shop Owner request
        |
        v
Rollout policy evaluates global flag and stable shop allowlist
        |
        ├─ existing selected ───────────────────────┐
        |                                           |
        └─ canonical selected                       |
                    |                               |
                    v                               |
          Shell service composes metadata           |
                    |                               |
                    ├─ failure ─────────────────────┤
                    |                               |
                    v                               v
          Complete canonical presentation   Complete existing presentation
                    |
                    v
          Canonical route re-evaluates normal server authorization
                    |
                    v
          Existing controller/component/service handles the capability
```

Canonical metadata is composed and contract-validated on the server before the Inertia response is committed. If composition or validation fails after canonical rollout selection, the server selects the entire existing presentation. The application must not mix canonical Home with the existing sidebar or canonical navigation with an ERP workspace frame.

## 7. Presentation Metadata Contract

The shell receives server-provided metadata shaped conceptually as:

```text
presentation: canonical | existing
selection_reason: stable enum
context: individual | company | null
groups[]
  key
  label
  order
  default_expanded
  items[]
    key
    label
    canonical_url
    available
    unavailable_reason
    active_matching
compatibility
  show_erp_fallback
  erp_workspace_url
```

Rules:

1. `available` is a presentation result derived from existing authoritative decisions. It is not authorization granted by the shell.
2. `selection_reason` uses a fixed category, never free text.
3. `active_matching` is item-local presentation metadata. It does not become a second route registry.
4. Canonical matching is authoritative. A compatibility URL may highlight its canonical destination through the same item metadata but cannot create an equal active-navigation map.
5. React renders the supplied structure and does not reproduce company-only route lists, business-type rules, module decisions, or permissions.
6. `context` is null only when the existing presentation is selected; canonical presentation always has `individual` or `company` context.
7. Unknown or malformed metadata causes the server to select the complete existing presentation before committing the Inertia response.
8. React receives one already-selected valid presentation. It does not inspect malformed canonical metadata and independently decide whether fallback is necessary.
9. Metadata contains stable keys and bounded values suitable for typing and testing.

## 8. Controlled Rollout

### 8.1 Selection contract

```text
Global shell flag OFF
└─ every owner receives existing presentation

Global shell flag ON
├─ shop is allowlisted
│  ├─ shell composition succeeds -> canonical presentation
│  └─ shell composition fails    -> existing presentation
└─ shop is not allowlisted       -> existing presentation
```

> **Phase 2 rollout eligibility is presentation-only and server-controlled.**

> **The rollout flag selects presentation, never route existence or capability availability.**

### 8.2 Stable selection categories

At minimum:

```text
global_disabled
shop_not_allowlisted
shop_allowlisted
invalid_registration_context
cohort_evaluation_failed
shell_composition_failed
```

`invalid_registration_context` selects the existing presentation and is observable, but is not an authorization failure.

### 8.3 Cache and identity rules

- The allowlist uses immutable shop identity, not owner email, user ID, or mutable profile data.
- Cohort evaluation occurs on the server.
- Rollout/cohort decisions are uncached or use a short, invalidation-aware cache.
- The global kill switch must revert default presentation promptly.
- Static destination-label mappings may be cached independently.
- Allowlist state is not exposed as a permission, module, subscription, or owner setting.

## 9. Presentation Context

Phase 2 derives presentation context solely from existing registration type:

```text
individual -> owner-operated emphasis
company    -> oversight emphasis
```

Registration type affects:

- group ordering;
- default expansion;
- prominence;
- density where the shell design supports it.

Registration type does not:

- grant or revoke authorization;
- override module eligibility;
- determine whether a server action succeeds;
- create an `individual` or `company` permission model.

There is no `microbusiness` field, inferred behavior profile, or owner-selectable mode in Phase 2.

If registration type is missing, invalid, or unavailable, the rollout policy selects the complete existing presentation and records `invalid_registration_context`. It does not infer context from behavior or permissions.

## 10. Canonical Information Architecture

The maximum illustrative structure is:

```text
Shop Owner
├─ Home
├─ Operate
│  ├─ Retail
│  ├─ Repair
│  ├─ Customers
│  └─ Transactional Payments
├─ Oversee
│  ├─ Finance
│  ├─ Workforce
│  ├─ Inventory
│  ├─ Procurement
│  └─ Logistics
├─ Reports & Audit
│  ├─ Reports
│  └─ Audit
└─ Business Settings
   ├─ Profile
   ├─ Modules and Team
   ├─ Payments and Approvals
   ├─ Operations
   ├─ Policies and Compliance
   └─ Subscription
```

This is a maximum, not a promise that every owner sees every item.

### 10.1 Eligibility-aware visibility

- Existing module and capability decisions determine which destinations exist.
- An individual Retail-only owner does not see Repair.
- Company-only areas such as Workforce do not appear for an ineligible individual owner.
- If a group has no eligible destinations, the entire group is omitted.
- The shell does not show irrelevant items merely to preserve visual symmetry.

Visibility follows this deterministic contract:

```text
Ineligible
-> omit the item

Eligible and enabled
-> show a normal destination

Eligible, disabled, and owner-manageable
-> may show unavailable
   + stable reason
   + canonical management destination

Eligible but unavailable with no useful owner action
-> omit the item rather than clutter navigation
```

An unavailable item is never a substitute for authorization. Its reason and management destination are presentation derived from existing authoritative module decisions.

> **Eligibility determines whether an item exists; operating context determines prominence and ordering.**

### 10.2 Individual or owner-operated presentation

```text
Home
Operate          first and expanded
Oversee          shown only when eligible items exist
Reports & Audit
Business Settings
```

Operate contains eligible hands-on Retail, Repair, Customer, and transactional payment destinations. Oversee contains only eligible business areas such as Finance, Inventory, Procurement where applicable, and Logistics.

### 10.3 Company presentation

```text
Home
Oversee          first and expanded
Operate          shown when direct operational capabilities exist
Reports & Audit
Business Settings
```

Company presentation emphasizes Finance, Workforce, Inventory, Procurement, and Logistics. Eligible direct Retail, Repair, Customer, and transactional payment destinations remain visible and accessible.

### 10.4 Canonical labels

Navigation labels describe business capabilities, not permission level. Use `Finance`, not `Finance Summary`, and `Logistics`, not `Logistics Monitoring`, when both contexts represent the same canonical destination.

The destination may adapt available controls through existing authoritative capability decisions.

### 10.5 Reports, Audit, and Business Settings

Reports and Audit are separate canonical capabilities beneath one information-architecture area. Audit remains evidentiary and is not an operational queue.

Business Settings remains one primary shell entry leading to a structured settings area. Its six sections do not all need to occupy the primary sidebar.

## 11. Canonical URL Contract

Phase 2 establishes stable URLs based on business capability rather than implementation history.

```text
/shop-owner/home

/shop-owner/operate/retail
/shop-owner/operate/repair
/shop-owner/operate/customers
/shop-owner/operate/payments

/shop-owner/oversee/finance
/shop-owner/oversee/workforce
/shop-owner/oversee/inventory
/shop-owner/oversee/procurement
/shop-owner/oversee/logistics

/shop-owner/reports
/shop-owner/audit

/shop-owner/settings/profile
/shop-owner/settings/modules-team
/shop-owner/settings/payments-approvals
/shop-owner/settings/operations
/shop-owner/settings/policies-compliance
/shop-owner/settings/subscription
```

Final route names follow repository conventions, but each Phase 2 shell destination has exactly one canonical URL.

Domain detail, record-specific, workflow-step, and action routes remain authoritative compatibility routes unless a route is explicitly included in the Phase 2 canonical-route inventory. Phase 2 does not need to replace every Product, Order, Refund, Purchase Order, Repair, Shipment, employee, or approval record URL.

> **Canonical routes represent capabilities, not implementation history.**

Avoid `/erp`, `/legacy`, controller names, or internal architecture in canonical URLs.

## 12. Route Aliasing and Existing Page Reuse

The preferred implementation is:

```text
canonical route
-> same authoritative controller/action where practical
-> same domain services and APIs
-> existing component inside trusted canonical shell context
```

Browser redirects to old URLs are a temporary fallback, not the preferred architecture.

Rules:

1. Route aliasing preserves the same named middleware stack and route-model binding behavior as the existing route.
2. A similar-looking route registered under a weaker or different middleware group is not parity.
3. If an action must know its presentation, the server passes a trusted presentation context. React does not infer shell mode from the URL.
4. Canonical deep links preserve record IDs, safe supported filters, and validated return destinations where the workflow already uses them.
5. Canonical requests re-evaluate normal tenant, module, capability, route-audience, and source-state checks. Source-state checks apply only to detail or mutation behavior that actually depends on source state; a navigation GET does not invent mutation validation.
6. Existing page forms, tables, requests, APIs, mutations, events, and domain services remain unchanged unless shell framing requires a focused compatibility adjustment.
7. A thin page-frame bridge may render an existing component inside the canonical owner shell without copying the page.
8. A compatibility failure cannot send an owner into an employee-only route audience.

## 13. Compatibility Entry Points

Existing `/shop-owner/*` and `/shop-owner/erp/*` routes remain functional during Phase 2.

> **Existing URLs remain compatibility entry points, but they are not equal canonical destinations. All new Phase 2 shell and navigation work points to canonical routes.**

Phase 2 does not broadly redirect or retire compatibility routes. Notification, bookmark, test, and internal-link consolidation belongs to later phases except where a new Phase 2 shell link is introduced.

All new shell-level navigation links use canonical shell destinations. Existing domain-level links may continue using authoritative detail, record, workflow, and action routes until a later phase explicitly migrates them.

Compatibility and canonical routes enforce equivalent:

- tenant decisions;
- module and capability decisions;
- route-audience decisions;
- validation and route-model binding;
- domain side effects;
- error behavior.

## 14. Phase 2 Home

`/shop-owner/home` remains registered and usable regardless of rollout state. The rollout flag decides framing and default presentation, not route existence.

Phase 2 Home contains:

```text
Home
├─ Existing Shop Owner dashboard content
├─ Required Actions — coming in Phase 3
└─ Exceptions — coming in Phase 3
```

Rules:

- Existing dashboard data and behavior remain authoritative.
- Phase 2 does not introduce `OwnerAttentionItem` or temporary attention aggregation.
- Required Actions and Exceptions perform no approval, exception, notification, or domain queue queries.
- Placeholders show no misleading zero count.
- Placeholders are visually subordinate and clearly intentional, not styled as failed widgets.
- Placeholder copy states that existing approval workflows and relevant operational modules remain the current action surfaces.
- Placeholders do not add new links directly to legacy approval pages.
- A placeholder may link only to an already-defined canonical shell destination, such as Procurement or Finance, when that general module destination is genuinely useful.
- Existing deep links and domain pages remain the way to act until Phase 3.

## 15. ERP Workspace Fallback

During the controlled rollout, the canonical shell may show **Open existing ERP Workspace** as a secondary compatibility link.

It is visible only when:

1. canonical presentation was selected successfully; and
2. the Shop Owner remains eligible for the ERP workspace.

Placement is secondary, such as a profile menu, help/compatibility area, or subtle sidebar footer. It is not a primary navigation group and does not duplicate ERP navigation inside the canonical shell.

The fallback:

- opens the unchanged existing ERP workspace;
- preserves current ERP authorization;
- does not serve as permission evidence;
- is removed only after Phase 5 proves capability coverage and workspace retirement readiness.

The Shop Owner ERP workspace feature flag controls this transitional workspace and fallback only. It does not independently gate canonical-shell destination eligibility.

### 15.1 Fallback telemetry

Fallback usage records stable values only:

```text
reason
  missing_destination
  missing_action
  verification
  user_preference

source_capability
source_canonical_page
shop_id
correlation/session identifier
```

Reason, capability, and source page use fixed keys, not client-provided free text.

Repeated `missing_destination` or `missing_action` for a capability means that capability is not migration-complete.

## 16. Migration Completeness

Migration completeness is evaluated per capability, not only for the shell as a whole.

```text
canonical destination exists
+ authorization parity
+ behavior parity
+ context/deep-link parity
+ canonical owner-shell rendering
+ no required use of ERP fallback for that capability
= capability migration-complete
```

One unresolved capability does not obscure which other capabilities have met the definition. It does block retirement of any entry point that still supplies the unresolved capability.

## 17. Rollback

Turning off the global shell flag restores the complete existing presentation.

> **Rollback is UI-topology rollback only; it does not require reverting route aliases, normalized state, or domain behavior.**

Rules:

- Canonical routes remain registered after rollback.
- Canonical bookmarks continue to reach the authorized underlying capability.
- When existing presentation is selected, canonical routes use the trusted existing frame where necessary.
- Rollback does not change module state, capability, tenant authorization, or domain data.
- Rollback does not invalidate completed Phase 1 normalization.
- Canonical and compatibility routes cannot redirect into a loop.

## 18. Failure Handling

### Rollout evaluation

- Evaluation failure selects the existing presentation.
- `invalid_registration_context` is not an authorization failure.
- Stable selection categories are observable.
- Allowlist failure does not expose cohort details to the owner.

### Shell composition

- The server constructs and validates canonical metadata before committing the Inertia response.
- Unknown or malformed metadata causes the server to abandon the entire canonical shell for that request/session.
- Partial canonical navigation is never displayed.
- React renders the selected presentation and does not own fallback safety decisions.
- No domain data changes when composition fails.
- User-facing fallback does not expose internal exceptions.

### Canonical routes

- Manually entered URLs never bypass eligibility or authorization.
- Ineligible modules use existing stable unavailable/denied behavior and management destinations.
- Wrong-tenant and wrong-audience access follows current secure denial conventions.
- Safe filters and return destinations are validated.

### Page reuse

- A reused component cannot infer authorization from being inside the canonical shell.
- Mutation endpoints re-evaluate all existing policy and source-state rules.
- A shell failure cannot alter a domain transaction.

## 19. Observability

At minimum, record or derive:

- presentation selected by stable reason category;
- cohort and composition failures by shop scope;
- invalid registration contexts;
- canonical destination requests and failures;
- ERP fallback usage by fixed reason and source capability;
- canonical-versus-compatibility authorization mismatches found by tests or runtime monitoring;
- navigation composition duration and payload size where practical.

Presentation selection telemetry is bounded—once per session or presentation transition where practical rather than noisy logging on every request.

Logs contain stable shop identity, presentation, reason, capability key, and correlation/session identifiers. They do not contain credentials, email, mutable profile values, arbitrary client text, or sensitive domain payloads.

## 20. Accessibility and Responsive Behavior

The canonical shell supports:

- keyboard navigation through groups and destinations;
- correct `aria-expanded` state;
- visible focus indicators;
- focus restoration when a mobile drawer closes;
- active-page announcement and styling;
- sidebar collapse without ambiguous icon-only navigation;
- mobile drawer and backdrop behavior;
- unavailable reasons that do not rely on color alone;
- reduced-motion preferences;
- business-oriented, context-neutral labels.

Empty groups are removed before rendering so keyboard users do not encounter non-functional controls.

Phase 3 placeholders must be perceivable as informational future functionality, not disabled controls or failed data widgets.

## 21. Performance

- Compose shell metadata once per owner request through the shared server boundary.
- Reuse already-loaded module states and route metadata.
- Avoid one query per navigation item.
- Do not query domain records, approvals, exceptions, notifications, or attention counts.
- Keep the metadata payload stable and bounded.
- Cache static presentation mappings independently from rapidly reversible rollout decisions.
- Avoid remounting reused domain pages solely because shell framing changes.
- Avoid speculative prefetching of every domain destination.

## 22. Security and Authorization Invariants

1. Rollout membership is presentation-only.
2. Shell visibility is not permission evidence.
3. Canonical routes repeat normal server authorization.
4. The shell service cannot invent a capability decision.
5. Client rollout, context, group, capability, fallback-source, and active-route values are untrusted.
6. Compatibility and canonical routes are tenant- and audience-equivalent.
7. Safe return destinations and filters are validated.
8. Fallback telemetry uses fixed keys and contains no sensitive payload.
9. Registration type affects presentation only.
10. Navigation GET routes do not invent mutation-specific source-state checks.

## 23. Verification Strategy

### 23.1 Rollout policy

Test:

- global flag off and on;
- allowlisted and non-allowlisted shops;
- stable shop identity;
- cohort evaluation failure;
- shell composition failure;
- invalid registration context;
- full-presentation fallback;
- rapid kill-switch rollback;
- canonical bookmark behavior after rollback;
- fixed selection categories.

### 23.2 Shell composition

Test at least:

- individual Retail owner;
- individual Repair owner;
- combined individual owner;
- company Retail owner;
- company Repair owner;
- combined company owner;
- disabled and ineligible modules;
- empty-group omission;
- owner-operated ordering and expansion;
- company ordering and expansion;
- invalid registration context selecting the complete existing presentation;
- unavailable reasons and management destinations;
- ERP fallback eligibility.

### 23.3 Canonical routes and parity

Test:

- one canonical route per Phase 2 shell destination;
- an explicit Phase 2 canonical-route inventory that excludes unselected domain detail and action routes;
- identical middleware and binding behavior;
- canonical and compatibility authorization parity;
- canonical and compatibility domain behavior parity;
- safe record IDs, filters, and return destinations;
- de-emphasized authorized deep links;
- denied manually entered URLs;
- simultaneous owner and employee sessions where relevant;
- canonical routes never enter an employee-only audience;
- old URLs remain functional;
- all new shell-level links use canonical URLs while existing domain-level links remain authoritative until explicitly migrated.

### 23.4 Home

Test:

- existing dashboard data and behavior remain intact;
- Required Actions and Exceptions are informational placeholders;
- no attention, approval, exception, or notification aggregation queries are introduced;
- placeholders show no misleading counts;
- `/shop-owner/home` remains usable when the shell flag is off.

### 23.5 Fallback

Test:

- fallback appears only for canonical-cohort and ERP-eligible owners;
- fallback remains visually secondary;
- existing ERP authorization is preserved;
- fixed reason and source-capability telemetry;
- arbitrary client reason/source values are rejected;
- repeated missing-capability reasons block that capability's migration-complete status.

### 23.6 Frontend and browser behavior

Test:

- individual and company information architecture;
- canonical labels;
- Reports and Audit as distinct capabilities;
- Business Settings landing and sections;
- canonical active matching;
- compatibility URL highlighting through canonical item metadata;
- desktop expansion and collapse;
- mobile drawer and backdrop;
- keyboard and focus behavior;
- responsive layout;
- reduced motion;
- informational Phase 3 placeholder styling;
- no mixed-shell rendering.

### 23.7 Regression coverage

Verify no regression to:

- existing Shop Owner presentation outside the cohort;
- existing Shop Owner ERP workspace;
- employee ERP navigation and audience checks;
- module toggling and business-scaling behavior;
- domain authorization and mutations;
- Phase 1 state and responsibility contracts.

## 24. Rollout Expansion Gate

The allowlist expands only when:

1. canonical destinations are route- and authorization-equivalent;
2. intended capability behavior is equivalent or intentionally revised;
3. no migrated capability requires the ERP fallback;
4. repeated `missing_destination` and `missing_action` usage is resolved for that capability;
5. individual and company browser verification passes;
6. accessibility and responsive checks pass;
7. kill-switch rollback has been exercised;
8. no regression is found in existing owner or employee ERP access;
9. shell composition is bounded and introduces no navigation N+1 queries;
10. no Phase 3 aggregation dependency was introduced.

Migration-complete status is recorded per capability so unresolved areas remain explicit.

## 25. Acceptance Criteria

Phase 2 is accepted when:

1. A global kill switch and explicit stable-shop allowlist control default canonical presentation.
2. Cohort selection is server-owned, presentation-only, and fail-safe.
3. A shell composition failure restores the complete existing presentation.
4. Registration type is the only Phase 2 presentation-context signal.
5. Invalid registration context falls back safely without becoming an authorization denial.
6. The canonical shell service composes existing module, route, and workspace decisions without redefining them.
7. React renders server metadata without duplicating eligibility or authorization rules.
8. Individual owners receive operation-first emphasis.
9. Company owners receive oversight-first emphasis while retaining eligible direct operations.
10. Ineligible items and empty groups do not render.
11. Canonical labels describe business capabilities rather than permission levels.
12. Reports and Audit are distinct capabilities and Audit remains non-operational evidence.
13. Business Settings is one primary area with canonical section destinations.
14. Every Phase 2 shell destination has exactly one stable canonical URL; nested domain routes remain authoritative unless explicitly included in the Phase 2 route inventory.
15. Canonical URLs do not expose ERP or legacy implementation topology.
16. Canonical routes preserve existing middleware, binding, tenancy, capability, and audience behavior.
17. Existing pages are reused without duplicating domain implementations.
18. New Phase 2 shell-level navigation links use canonical destinations without forcing migration of every nested domain link.
19. Existing routes remain compatibility entry points throughout Phase 2.
20. Authorized de-emphasized tools remain reachable through canonical deep links.
21. `/shop-owner/home` reuses the existing dashboard and remains available regardless of rollout state.
22. Required Actions and Exceptions are clearly informational Phase 3 placeholders with no partial aggregation.
23. The ERP workspace remains a secondary, eligibility-aware, measurable fallback.
24. Fallback reasons and source capabilities use fixed stable keys.
25. Migration completeness is evaluated per capability and requires no necessary ERP fallback.
26. Kill-switch rollback changes presentation topology only and preserves canonical bookmarks.
27. The canonical shell is keyboard-accessible, responsive, and does not render empty controls.
28. Shell composition is bounded and introduces no domain queue queries or navigation N+1 behavior.
29. Existing owner and employee ERP experiences remain functional and authorized.
30. Phase 2 does not retire routes, remove the ERP workspace, simplify approvals, or build Phase 3 attention aggregation.

The final stopping invariant is:

> **Phase 2 is complete when the canonical shell can safely become the default presentation for the approved cohort without changing authorization, domain behavior, or requiring Phase 3 functionality.**

## 26. Implementation Planning Gate

This document is a focused design specification, not an implementation plan.

After user review and approval of the written specification, a separate TDD implementation plan must identify:

- exact rollout policy, shell service, metadata type, route, controller, middleware, layout, sidebar, page-frame, configuration, and test files;
- the Phase 2 shell-destination inventory and its mapping to existing authoritative actions;
- characterization tests for existing sidebar, workspace, routes, and page behavior;
- controlled rollout and rollback steps;
- route middleware and binding parity checks;
- browser and accessibility verification;
- per-capability migration evidence;
- narrow verification commands after each coherent change;
- sequential Standards, Spec, risk, reuse, and dead-code reviews;
- final evidence required before allowlist expansion.

Implementation must not begin until that plan is approved.
