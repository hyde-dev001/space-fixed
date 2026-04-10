# Notification Unification Design

## Context

The platform currently has inconsistent notification behavior across roles and modules. Some flows use the custom `NotificationService` and custom `notifications` table, while other flows use Laravel `Notification` database channels or direct model writes. This causes role specific gaps, route handler drift, and inconsistent user experience, especially for shop owner workflows.

This design defines an end-to-end unification approach for in-app notifications only.

## Approved Decisions

1. Scope: all roles end-to-end (customer, shop owner, ERP staff, super admin).
2. Channel policy: in-app only.
3. Owner policy for company registrations: hybrid by event type.
4. Stack decision: unify on custom `NotificationService` plus custom `notifications` table.
5. Rollout mode: phased rollout by role and module.

## Goals

1. Ensure all critical business transitions emit notifications to the correct recipients.
2. Remove route and controller ambiguity in notification APIs.
3. Eliminate stack drift between custom notifications and Laravel database-channel notifications.
4. Add migration safety controls and observability for phased rollout.
5. Preserve backward compatibility during migration.

## Non-Goals

1. Rebuilding the UI notification components from scratch.
2. Introducing push or SMS channels in this phase.
3. Full domain model refactors outside notification concerns.

## Current-State Findings

### 1. Multiple notification stacks

- Custom stack: `NotificationService` writes to `notifications` table with role aware ownership fields.
- Laravel stack: several classes under `app/Notifications/**` use `['mail', 'database']` and `Notifiable`.
- Direct model writes: some status transitions create notification rows directly in model hooks.

Result: duplicated logic, missing coverage, and unpredictable recipient behavior.

### 2. Route contract overlap

- Notification routes are defined across multiple route files and controllers for similar namespaces.
- Endpoint differences such as `read-all` and `mark-all-read` coexist.

Result: endpoint behavior depends on route registration order and controller binding, causing inconsistency.

### 3. Missing module coverage

From the audit, high-impact gaps include:

- Shop owner order status and pickup actions with no explicit notification fan-out.
- Shop owner suspension final review with no notification fan-out.
- Salary change rejection path without requester notification.
- Repair POS and online repair refund workflow services lacking notification dispatch.
- Supplier overdue notify method still placeholder-only.
- Model-level direct writes bypassing recipient resolver and policy handling.

### 4. Company owner suppression

Some owner notifications are skipped when `registration_type != individual`.

Result: governance-critical events can be invisible for company shops unless separately handled.

## Target Architecture

### 1. Unified in-app pipeline

All in-app notifications will pass through one canonical service flow:

1. Domain event trigger from module action.
2. Notification orchestration (`NotificationService` core API).
3. Recipient resolution by policy (role, permission, owner mode, shop scope).
4. Preference gate (in-app policy rules only).
5. Persistence into custom `notifications` table.
6. Retrieval via normalized namespace API.

### 2. Recipient resolution layer

Introduce a centralized resolver for recipient rules:

- Customer events -> `user_id`.
- Shop owner governance events -> owner recipient for both individual and company types.
- Shop operational events -> owner for individual shops, delegated ERP recipients for company shops.
- ERP events -> permission-first targeting with role fallback.
- Super admin events -> all active super admins.

### 3. Hybrid owner policy

- Governance-critical: always create owner-level notifications regardless of registration type.
- Operational: route to delegated ERP role recipients for company shops, with optional owner summary record where required.

## API Contract Normalization

## Canonical namespaces

- Customer: `/api/notifications`
- ERP staff: `/api/staff/notifications`
- ERP HR/finance/manager: `/api/hr/notifications`
- Shop owner: `/api/shop-owner/notifications`
- Super admin: `/api/admin/notifications`

## Canonical endpoints per namespace

1. `GET /`
2. `GET /unread-count`
3. `GET /recent`
4. `GET /stats`
5. `POST /{id}/read`
6. `POST /mark-all-read`
7. `DELETE /{id}`
8. `GET /preferences`
9. `PUT /preferences`

Optional advanced endpoints (bulk, grouped, export) are allowed only if implemented with the same response contract and role scoping behavior.

## Response contract rules

- List endpoints return `data` and `meta`.
- Count endpoints return `count`.
- Mutations return `success` and `message`.
- Field names should remain consistent across namespaces to keep frontend hooks reusable.

## Migration and Compatibility Strategy

### Phase bridge model

1. Bridge-on and unified-shadow mode:
- Legacy emitters continue.
- Unified pipeline runs in shadow for parity checks.

2. Unified-primary mode:
- Canonical service becomes primary writer.
- Legacy database-channel writes disabled per module.

3. Legacy-retirement mode:
- Remove module adapters and deprecated routes after stability window.

### Feature flags

Per module flags:

- `notifications.unified.<module>.enabled`
- `notifications.bridge.<module>.enabled`

This allows granular rollback without disabling the entire notification system.

## Error Handling and Observability

## Fail-soft emission

Notification failures should not break core business transactions. Emission runs in protected blocks with structured error logging.

## Standard failure codes

- `recipient_resolution_failed`
- `unknown_notification_type`
- `notification_persist_failed`
- `scope_violation_blocked`
- `route_contract_mismatch`

## Structured telemetry

Each emission attempt should log:

- source module and action
- notification type
- resolved recipient count
- persisted count
- failure reason
- correlation id

## Safety thresholds

Track and alert on:

- unresolved recipient rate
- duplicate notification rate
- emit success rate by module
- unread growth anomalies by role

## Role and Module Coverage Plan

## Wave A (highest impact)

1. Shop owner order status and pickup related actions.
2. Shop owner suspension final review flow.
3. Salary change rejection notifier for proposer/requester.
4. Repair POS refund and online repair refund workflow notifications.

## Wave B

1. Replace direct model-level notification writes with service dispatch.
2. Implement supplier overdue real notification dispatch.
3. Normalize remaining approval and procurement transitions.

## Wave C

1. Remove static notification placeholders in frontend role utilities.
2. Finalize API parity for all role namespaces.

## Testing Strategy

### Automated tests

1. Route contract tests for each namespace and endpoint shape.
2. Recipient matrix tests for critical transitions.
3. Scope isolation tests to prevent cross-tenant leaks.
4. Bridge parity tests between legacy and unified emitters.
5. Deduplication and idempotency tests.
6. Regression tests for high-value workflows: order, repair, refund, salary, suspension, purchase request.

### Release gates

1. No route collisions for notification namespaces.
2. Critical workflow recipient tests pass.
3. Duplicate and unresolved recipient rates below threshold.
4. Legacy module can be disabled with no regression in staging window.

## Risks and Mitigations

1. Risk: duplicate notifications during bridge phase.
- Mitigation: dedupe keys and temporary parity assertions.

2. Risk: missed recipients due to role naming drift.
- Mitigation: permission-first resolver plus role fallback and warning logs.

3. Risk: hidden behavior changes due to route overlap cleanup.
- Mitigation: route contract test suite and phased alias deprecation.

4. Risk: company owner visibility regressions.
- Mitigation: explicit governance-vs-operational policy matrix and targeted acceptance tests.

## Acceptance Criteria

1. Every critical approval/status transition in scope emits a canonical notification record.
2. Notification APIs per namespace return consistent contract and expected scoping.
3. Company and individual shop policies behave per hybrid rules.
4. Legacy database-channel emitters are retired for migrated modules.
5. No unresolved recipient or duplicate notification spikes after cutover.

## Implementation Handoff

Next step is a detailed implementation plan that decomposes this design into file-level, test-first tasks with phased commits and rollback checkpoints.
