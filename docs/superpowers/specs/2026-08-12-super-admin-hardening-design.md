# Super Admin Hardening and Business Document Lifecycle Design

**Date:** 2026-08-12

**Status:** Final authoritative input for phase-level implementation planning

**Scope:** SME multi-shop platform administration, privileged security, operational correctness, and business-document renewal

## 1. Summary

This design hardens the existing Laravel Super Admin module without rewriting it or turning it into an enterprise back-office platform. It retains the existing `super_admin` guard, the `SuperAdmin` account model, and the two privileged roles, then adds the missing server-side authorization, active-account and MFA enforcement, private document handling, guarded state transitions, transactional workflows, authoritative audit history, safe billing intervention, and bounded operational queues.

The design also adds one approved operational capability: expiration and renewal of shop business documents. Documents remain private and immutable, Shop Owners provide date or no-expiration declarations, authorized reviewers verify them, renewals preserve history, and expiration never suspends a shop automatically.

Delivery is divided into nine phases. Existing security, integrity, operational, and billing defects are addressed before the new document lifecycle.

## 2. Goals

- Enforce a clear Admin versus Super Admin boundary on the backend.
- Require active status and MFA for every privileged operational session.
- Replace predictable privileged account seeding with a safe first-account bootstrap.
- Keep IDs, permits, and registration evidence private and access-controlled.
- Replace routine hard deletion with reversible shop and user archival.
- Enforce valid source-state transitions for registrations, shops, users, reports, flagged accounts, appeals, administrators, and subscriptions.
- Make multi-record privileged workflows atomic and resilient to duplicate or concurrent requests.
- Establish one authoritative privileged-action ledger.
- Remove fake, disconnected, and duplicate Super Admin functionality.
- Reuse the authoritative shop-side billing lifecycle for exceptional Super Admin intervention.
- Add immutable business-document versions, expiration reminders, and renewal review.
- Keep operational pages bounded and usable at SME production scale.

## 3. Non-Goals

- Microservices or distributed workflow architecture
- Configurable RBAC or a permission-management UI
- Enterprise IAM or a general policy engine
- A workflow/approval builder
- Enterprise compliance management
- OCR, document AI, regulatory validation, or inferred validity periods
- Configurable reminder rules
- Automatic shop suspension because a document expired
- Generic communications, support-ticket, export, or report-generation platforms
- A second billing system
- Routine irreversible deletion
- Broad refactoring outside the Super Admin domain
- A generic repository, command bus, or manager/service stack for simple CRUD

## 4. Current-State Evidence

The design responds to confirmed implementation behavior:

- Privileged routes currently enforce authentication but not the `admin` versus `super_admin` distinction.
- Suspended and inactive privileged accounts are not rejected by login or request middleware.
- A migration creates `admin@thesis.com` with the known password `admin123`.
- Admin login is not rate-limited.
- IDs and business documents are stored on the public disk and exposed through `/storage/*` URLs.
- Shop, user, and administrator hard-delete actions lack recovery and safety controls; shop deletion cascades across major operational records.
- Registration, moderation, appeal, suspension, and subscription actions allow unsafe source states or partial mutation.
- Admin subscription cancellation claims a refund without provider-backed money movement, and direct upgrade/downgrade bypasses the stronger shop-side lifecycle.
- Privileged actions are split between the custom `audit_logs` table and Spatie `activity_log`; the custom actor ID is ambiguous.
- User-management, communications, export, and reporting interfaces contain missing endpoints or simulated success.
- Duplicate `/superAdmin` route groups and overlapping controller paths obscure ownership.
- Operational queues generally load complete datasets, and shop-report pattern detection expands per shop.
- `shop_documents` currently has only owner, type, path, review status, and timestamps. Some resubmission paths overwrite rows and delete old files.
- The current `dti_registration` label ambiguously covers DTI and SEC records.
- Laravel scheduling, database queues, failed-job storage, notification infrastructure, and after-commit patterns already exist. The HR document-expiry command is separate, unscheduled, and incomplete and is not an implementation for shop compliance.

## 5. Design Principles

1. **Surgical hardening.** Preserve working structures and change only what the validated risks require.
2. **Fixed capabilities in code.** Map deterministic capabilities from `admin` and `super_admin`; do not add configurable RBAC.
3. **Backend enforcement first.** UI visibility never grants or removes authority.
4. **One authoritative workflow.** Registration, user intervention, subscriptions, routes, and audit each receive one runtime owner.
5. **Transactions for invariants.** State and mandatory success audit commit together; delivery occurs after commit.
6. **Short locks and no distributed transactions.** External provider, email, queue, and storage operations do not run while database locks are held.
7. **History over destructive replacement.** Archive accounts and preserve documents, decisions, and financial records.
8. **Derived state where practical.** Do not persist values that can drift from authoritative dates and review state.
9. **SME scope.** No feature exists merely because a placeholder UI already exists.
10. **Every phase is coherent.** A phase may not knowingly introduce a security or integrity violation for a later phase to repair.

## 6. Target Architecture

```text
Super Admin / Admin UI
        ↓
super_admin authentication guard
        ↓
active-account check
        ↓
MFA-complete privileged-session check
        ↓
fixed capability + object-scope authorization
        ↓
focused domain controller
        ↓
simple model operation
OR focused workflow service/action for substantial invariants
        ↓
short database transaction
        ↓
models + mandatory privileged audit
        ↓
commit
        ↓
operational notification / email / session cleanup
```

A workflow may justify a service even when only one controller calls it if it owns substantial transactional or business invariants. Simple request orchestration and CRUD stay in focused controllers.

## 7. Final Capability Map

### Keep and fix

- System monitoring
- Platform administrator management
- Shop registration review
- Registered-shop management
- Shop reports and flagged-account moderation
- Suspension appeals
- Platform-level user intervention
- Subscription-plan management
- Exceptional subscription intervention
- Administrative notifications
- Privileged audit history
- Profile, password, MFA, and recovery-code management
- Private business-document access
- Business-document expiration and renewal

### Hide or remove

- Simulated announcements and alerts
- Empty support tickets
- Fake exports and report generation
- Simulated user deactivation and password reset
- Customer approve/reject controls without a domain workflow
- Dead monitoring controls
- Direct admin subscription upgrade/downgrade
- Duplicate route groups and mutation endpoints
- Legacy registration and user-management handlers after reference verification

### Ownership moved or constrained

- Routine plan changes remain Shop Owner-controlled through the authoritative billing lifecycle.
- Customer password recovery remains self-service.
- Broad data exports require a separately approved privacy/compliance need.
- Support tickets belong to a future support module if justified.
- Irreversible erasure is unavailable unless a separately defined legal/operational process is approved.

## 8. Authorization Matrix

| Capability | Admin | Super Admin |
|---|---:|---:|
| View operational monitoring | Yes | Yes |
| Review authorized registrations and private documents | Yes | Yes |
| Approve/reject pending registrations | Yes | Yes |
| View registered shops and users | Yes | Yes |
| Moderate reports and flagged accounts | Yes | Yes |
| Suspend/reactivate shops or users | Yes | Yes |
| View appeal queue where operationally useful | Yes | Yes |
| Resolve suspension appeals | No | Yes |
| Manage administrator accounts or roles | No | Yes |
| Manage subscription plans | No | Yes |
| Perform exceptional subscription intervention | No | Yes |
| View privileged audit history | Own/authorized operational scope | Full |
| Manage own password, MFA, and recovery codes | Yes | Yes |
| Manage platform security or another admin's MFA | No | Yes |
| Irreversible data erasure | No | Separate exceptional process only |

Capabilities are methods or deterministic mappings such as `canReviewRegistrations`, `canManageAdministrators`, and `canResolveAppeals`. Capability checks are combined with scoped backend queries and source-state requirements.

## 9. Privileged Authentication

### Session stages

```text
unauthenticated
      ↓ password verified
setup-authenticated
      ↓ password/MFA/recovery setup complete and account active
MFA-authenticated privileged session
```

Setup-authenticated sessions can access setup routes only. Operational routes require an active, non-setup account and an MFA-complete privileged session.

### Login and session rules

- Validate password, account status, setup condition, and MFA server-side.
- Use generic failures for invalid credentials, inactive status, or suspension.
- Rate-limit login, setup-link, MFA, reset, and recovery attempts.
- Regenerate the session after successful authentication.
- Record successful login time and IP.
- Recheck database-backed status and security condition on every privileged request.
- Suspension or deactivation removes authority on the next request even if physical session cleanup is delayed.
- Password reset invalidates all sessions.
- Password change invalidates other sessions; the current session may remain after recent reauthentication.
- MFA reset invalidates all privileged sessions and sets setup-required security state.
- Recovery-code regeneration invalidates all previous recovery codes and other sessions as defined by the security flow.

TOTP secrets are encrypted at rest. Recovery codes are hashed, individually single-use, and replaced as a complete set when regenerated. Secrets and recovery codes never enter audit metadata.

### First account bootstrap

A one-time interactive Artisan command creates the first Super Admin in `pending_setup`, refuses ordinary execution after bootstrap, and sends an expiring single-use setup link. The recipient creates a strong password, enrolls TOTP, verifies a code, and acknowledges recovery codes before activation. No known password is stored in migrations, source, command arguments, or deployment variables.

Subsequent administrators use the same invitation/setup lifecycle.

### Recent reauthentication

High-risk operations require a recent password plus fresh TOTP challenge recorded centrally in the session for a short fixed window, expected to be 10–15 minutes. Recovery codes are for account recovery, not routine confirmation.

After bootstrap, every operation that could remove the final active, MFA-enrolled Super Admin is rejected, including suspension, deactivation, role demotion, MFA reset into setup-required state, or any future archival operation.

## 10. Private Document Access

IDs, permits, DTI/SEC, BIR, and supporting evidence use a private Laravel disk. Direct `/storage/*` URLs are removed.

Every access request independently verifies:

```text
authenticated privileged identity
+ active status
+ MFA completion
+ fixed capability
+ object scope
+ document-to-case/shop relationship
+ successful access audit
= authorized private stream
```

Knowing or guessing a document ID grants no authority. Admin may view evidence for authorized registration/compliance cases. Super Admin may additionally view registered-shop evidence for legitimate platform administration or investigation.

Responses use `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`. Safe PDFs/images may render inline; HTML, SVG, executable-like, unknown, or risky content downloads as an attachment. Access audit events mean the server authorized and initiated the response, not that the browser consumed every byte.

Existing public files migrate copy-first: copy to private storage, verify size/hash/existence, update the database reference, verify it, then remove the public source. The command is resumable and produces reconciliation counts without guessing missing files.

## 11. Account and Workflow Lifecycles

### Privileged administrators

```text
pending_setup → active
active → suspended
active → inactive
suspended → active
suspended → inactive
inactive → pending_setup
```

MFA setup-required is a security condition separate from business status. Administrators are not routinely archived or deleted; historical actor identity is retained. An actor cannot suspend, deactivate, change the role of, or reset MFA for their own administrator account through management endpoints.

### Shop registration and operation

```text
pending → approved
pending → rejected
rejected → pending       only through valid resubmission

approved → suspended
suspended → approved
```

General reactivation cannot approve pending or rejected registrations.

### Users

```text
active → suspended
suspended → active
```

Users and shops have a separate record lifecycle through reversible archival (`deleted_at` or equivalent). Archival preserves business status and relations, disables authentication/normal participation, excludes normal queries, and remains restorable.

### Shop reports

```text
submitted → under_review
under_review → dismissed | warned | suspended
```

Terminal outcomes cannot reopen. One grouped moderation decision operates on the locked open-report set for one shop and creates at most one warning strike.

### Flagged accounts

```text
pending_review → under_investigation
under_investigation → dismissed | account_suspended
```

The persisted `banned` value may remain temporarily with an accurate domain label if schema migration would cause disproportionate churn.

### Suspension appeals

```text
eligible → submitted | expired
submitted → approved | rejected | expired | superseded
```

Each suspension has a stable identity. The account records its current suspension reference. An appeal can affect the account only when it belongs to that current suspension. Manual reactivation closes any non-terminal appeal as superseded.

### Subscriptions

The shop-side billing lifecycle is authoritative. Confirmed schema states include `pending`, `active`, `expired`, `cancelled`, and `failed`; the contradictory admin-only `deactivated` behavior is reconciled.

```text
pending → active | failed
active → cancelled
active | cancelled → expired
```

Cancelled paid subscriptions retain entitlement through `ends_at`. Super Admin cannot directly swap plans.

## 12. Destructive Actions

| Entity | Routine operation | Irreversible deletion |
|---|---|---|
| Administrator | Suspend or deactivate | Not available |
| User | Archive/restore | Not built without verified requirement |
| Shop | Archive/restore | Not built without verified requirement |
| Reports, appeals, payments, audits | Retain | Not available in routine UI |
| Registration documents | Immutable versions; controlled retention later | Not part of routine workflow |

Archive and restore require authorization, reason, recent reauthentication, transaction, and audit. Shop/user hard-delete routes and UI controls are removed.

## 13. Critical Workflow Contracts

### Registration decision

- Common precondition: application exists and is pending.
- Approval additionally requires complete required documents and review conditions.
- Rejection requires a reason but may resolve an incomplete application.
- Lock application; revalidate; apply decision; create one setup token for approval; audit; commit; notify after commit.
- Identical retry returns current state without duplicate token or email; conflicting decision returns `409`.

### Shop/user suspension and reactivation

- Require valid source state and reason.
- Lock account, suspension event, and appeal in canonical order.
- Create one current suspension and at most one non-terminal appeal.
- Synchronize an applicable linked employee in the same transaction; ambiguous linkage is rejected.
- Reactivation verifies the current suspension identity and closes outstanding appeal state.

### Report and flagged-account moderation

- Lock root account/shop first, then reports in deterministic ID order, then suspension state.
- Revalidate state under lock.
- Apply terminal outcomes, warning strike, threshold suspension, appeal creation, and audit atomically.
- Retrying the same outcome does not duplicate a strike, suspension, appeal, or notification.

### Appeal decision

- Super Admin only.
- Lock account, suspension, and appeal in canonical order.
- Require submitted, unexpired appeal tied to the current suspension.
- Approval restores the account and closes suspension; rejection leaves it suspended.

### Administrator lifecycle

- Super Admin only, target differs from actor, recent reauthentication required.
- Lock target and relevant active Super Admin records.
- Prevent final-Super-Admin removal.
- Commit state/security version and audit, then invalidate physical sessions after commit.

### Subscription intervention

- Local cancellation uses the authoritative billing operation and preserves paid history.
- If provider interaction is required, persist a pending intent and idempotency key in Transaction A, call the provider outside locks, then finalize outcome and audit in Transaction B.
- Refund exists only when provider-backed money movement and payment records are implemented and verified.
- Administrative correction is non-financial metadata/state correction; it cannot edit paid amount or revenue history.

### Private document access

- Authorize capability and scope for every request.
- Access-audit failure prevents delivery.
- Stream after audit without a long database transaction.

## 14. Transactions, Failures, and Concurrency

Every committed local privileged transition and its mandatory success audit commit in the same local transaction. External-provider workflows separately audit initiation and final provider-backed outcome.

On transaction failure:

```text
rollback business mutation
+ rollback success audit
+ optionally write sanitized failure audit after rollback
+ return generic error with correlation ID
```

Security-relevant failures include failed login/MFA, denied MFA reset, stale privileged decisions, private-document access denial where useful, and provider intervention failure. Routine validation is not turned into audit noise.

Transactions contain database work only and remain short. Workflows use one canonical lock order. Exact duplicate outcomes are idempotent and return current state; conflicting/stale outcomes return `409`.

Targeted concurrency tests cover simultaneous registration approval, suspension, warning decisions, appeal approval versus manual reactivation, final-Super-Admin changes, renewal promotion, and provider retries.

## 15. Privileged Audit

Spatie `activity_log` is the authoritative Super Admin ledger because it already supports polymorphic actors and subjects. A small `PrivilegedAudit` normalization helper records consistent events without becoming an event framework.

Mandatory fields:

- actor type and ID
- actor role at action time
- normalized action
- target type and ID
- previous and resulting state where relevant
- reason
- safe allowlisted metadata
- IP address
- request/correlation ID
- original timestamp

Never record credentials, MFA material, reset/setup tokens, document content, private paths, sensitive filenames, full provider payloads, or raw request bodies.

New hardened paths write directly to the authoritative ledger from Phase 0. Later audit cutover migrates only reliably mappable legacy records, preserves original timestamps, stores unique legacy source/ID, reports ambiguous entries, and never invents actor identity. Existing source tables remain preserved.

Admin sees their own actions and authorized operational-case history. Super Admin sees the full privileged ledger.

## 16. Routes and Controller Ownership

`/admin` becomes the canonical privileged prefix. Setup and MFA routes use stage-specific middleware; operational routes use authentication, active status, MFA, capability, and object scope.

```text
/admin/login
/admin/setup/*
/admin/mfa/*
/admin/monitoring
/admin/administrators
/admin/registrations
/admin/shops
/admin/users
/admin/reports
/admin/flagged-accounts
/admin/appeals
/admin/plans
/admin/subscriptions
/admin/notifications
/admin/audit
/admin/security
```

Safe legacy GET routes may redirect temporarily after callers and stored notification links migrate. Duplicate POST/PUT/PATCH/DELETE routes are removed as soon as the canonical replacement is live.

Canonical owners:

| Area | Owner |
|---|---|
| Authentication/setup/MFA | Privileged authentication controller(s) |
| Profile/password/recovery | Privileged security controller |
| Administrators | Administrator management controller |
| Registration/documents | Registration review/private document controllers |
| Registered shops | Registered-shop controller |
| Users | User-intervention controller |
| Reports/flags/appeals | Existing specialized controllers |
| Plans | Premium-plan controller |
| Subscription intervention | Focused controller using authoritative billing logic |
| Monitoring | Existing monitoring controller |
| Notifications | Existing notification API controller |
| Audit history | Privileged audit controller |

The monolithic `SuperAdminController` is split one domain at a time only after behavior is secured and covered.

## 17. Operational Data and Notifications

Registrations, shops, users, administrators, subscriptions, reports, appeals, renewals, and audit history use server pagination, allowlisted filters, indexed deterministic sorting, and an ID tie-breaker. Default page size is modest (for example 25) and maximum is capped (for example 100). Monitoring uses aggregates. Per-shop report detection becomes bounded aggregate querying.

Notifications are an operational inbox, not audit history. They are created/fanned out after business commit, deduplicated by business event, recipient, and type, and sent only to active, MFA-complete recipients with relevant capabilities. Clearing a notification never removes audit evidence.

Only real measurable health conditions may generate monitoring notifications. This design does not create an alert-rule engine.

## 18. Business Document Expiration and Renewal

### Model

`shop_documents` remains the single document table. Each upload is an immutable version row. Add the smallest fields needed for:

- logical slot
- version number
- predecessor reference
- nullable current marker
- superseded timestamp
- optional issue date
- expiration mode (`dated`, `none`, legacy-only `unknown`)
- optional expiration date
- reviewer identity/time
- rejection reason

Current-version and version-number uniqueness operate on `shop_owner_id + logical_slot`, not concrete type. DTI and SEC share the `business_registration` slot. Singleton rules apply only to singleton requirements. Each independent supporting document receives a stable slot identity that does not depend on array order, filename, display position, or version number.

### Submission policy

| Type | Rule |
|---|---|
| Mayor's Permit | Expiration date required |
| DTI Registration | Date or explicit no expiration |
| SEC Registration | Date or explicit no expiration |
| BIR Certificate | Date or explicit no expiration |
| Valid ID | Date or explicit no expiration |
| Supporting document | Date or explicit no expiration |

For new submissions, exactly one DTI or SEC document satisfies business registration. Reliably preserved legacy DTI/SEC evidence may temporarily satisfy an approved shop until safely classified or replaced through renewal. `unknown` cannot be selected for new submissions.

The owner declares issue/expiration information. Reviewer approval verifies or corrects it from the specific document. The system does not infer regulatory periods. After verification, the owner cannot edit metadata in place; they submit a new version. Reviewer corrections require privileged audit and create a new reminder identity when the expiration date changes.

### Derived validity

Only current, approved, reviewer-verified documents participate:

```text
none → valid/no expiration
dated and expires_on > today + 30 days → valid
dated and today <= expires_on <= today + 30 days → expiring soon
dated and expires_on < today → expired
unknown or unverified → metadata unverified
```

A document remains valid through its stated expiration date and becomes expired on the following local calendar date in the confirmed platform timezone. Expiration never changes shop status.

### Renewal

```text
current approved v1
      ↓ owner submits private v2
v1 remains current; v2 pending
      ↓ reviewer decision under lock
approve: v1 historical/superseded; v2 approved/current
reject: v1 unchanged; v2 rejected with reason
```

The transaction locks the shop and slot versions, validates predecessor/state, promotes exactly one current version, and records audit. Failure leaves v1 current. Duplicate submissions/decisions are guarded and idempotent where outcomes match.

### Expiry detection and reminders

A dedicated shop-compliance scheduled command runs daily with overlap protection and bounded chunks. Use `onOneServer()` only when shared atomic locking is verified; otherwise use the verified single-scheduler deployment model. It is separate from the incomplete HR expiry command.

Query only current, approved, reviewer-verified, dated documents. Send fixed reminders at 30 days, 7 days, and the expiration date. Deduplication identity includes document/version, expiration date, threshold, and recipient. Routine reminders go to the Shop Owner; reviewer notifications are reserved for submitted renewals or genuine action requirements.

### Legacy reconciliation

Backfill logical slots and deterministic versions without guessing DTI versus SEC. Promote current evidence only where reliable. Preserve approved-shop compliance when legacy evidence previously satisfied the requirement. Report ambiguous groups, unknown metadata, missing files, and unresolved current candidates.

## 19. Core Data Invariants

1. After bootstrap, at least one active, MFA-enrolled Super Admin remains.
2. Setup sessions cannot access operational routes.
3. Business status and archival state are separate.
4. Archived users/shops cannot authenticate or participate normally.
5. Every current suspension has one stable identity and at most one non-terminal appeal.
6. Closing a suspension leaves no non-terminal appeal.
7. A stale appeal cannot change an account.
8. Terminal moderation outcomes cannot reopen normally.
9. One grouped warning decision creates at most one strike.
10. Subscription payment history is never rewritten to simulate a refund.
11. Cancelled paid entitlement remains through `ends_at`.
12. Every committed local privileged state transition and success audit are atomic.
13. Private documents are not served if access auditing fails.
14. Notifications can be cleared without deleting audit history.
15. At most one approved/current document exists per singleton logical slot.
16. Historical documents are never silently overwritten.
17. A pending/rejected renewal never replaces current evidence.
18. Expiry reminders refer to the correct current version and expiration identity.
19. Expiration never mutates shop status automatically.
20. A preserved legacy DTI/SEC document continues satisfying an approved shop until classified or renewed.

## 20. Security Rules

- Enforce authentication, status, MFA, capability, scope, and source state independently.
- Require CSRF protection on privileged mutations.
- Use hashed single-use tokens and centralized throttling.
- Require recent password plus TOTP reauthentication for high-risk actions.
- Prevent self-management through administrator-management endpoints.
- Keep documents private through every upload/review/renewal stage.
- Use allowlisted audit metadata and generic error responses.
- Never expose provider, database, storage, or credential internals.
- Use provider idempotency and reconcilable local attempts for money movement.
- Treat notification links and legacy redirects as navigation only, never authority.

## 21. Verification Strategy

### Authorization and authentication

- Admin allowed operational duties and denied Super-Admin-only mutations.
- Admin may manage their own password/MFA but not platform security.
- Suspended, inactive, setup-incomplete, and non-MFA accounts denied.
- Setup-only route isolation, bootstrap single-use behavior, throttling, recovery-code consumption, and final-Super-Admin protection.

### State and transactions

- Every valid and invalid transition.
- Duplicate and conflicting outcomes.
- Stale appeals and manual reactivation.
- Failure injection after each meaningful mutation and mandatory audit write.
- Targeted concurrency for locks protecting business invariants.

### Documents

- Public URLs unavailable and IDOR denied.
- Audit failure prevents private streaming.
- Date/no-expiration validation and reviewer authority.
- Immutable version creation, promotion, rejection, and history.
- DTI-or-SEC one-of rule and legacy compatibility.
- Supporting-document stable slot identity.
- Reminder thresholds, retries, date changes, and deduplication.
- Expiration does not change shop status.
- Scheduler query bounds, timezone boundaries, storage headers, and audit exclusions.

### Billing and audit

- No plan swap or pseudo-refund.
- Cancellation entitlement, provider success/failure/retry, and reconciliation.
- Normalized privileged events, rollback semantics, legacy backfill idempotency, and sensitive-data exclusion.

### UI, routes, and scale

- Removed controls absent; retained controls use registered endpoints and persisted results.
- One canonical mutation route per action.
- Deterministic pagination, bounded hydration, scoped filters, and measured query improvements.
- Browser verification for both roles and Shop Owner renewal flows.

Each phase runs focused tests first, then relevant Laravel/frontend suites, route/storage/database inspection, browser flows, `git diff --check`, and production build where applicable. No phase completes on UI behavior or happy paths alone.

## 22. Phased Implementation Strategy

### Phase 0 — Immediate Containment

**Objective:** Close direct compromise, privacy, and irreversible deletion paths.

**Addresses:** SA-001, SA-003, SA-005, SA-006.

**Scope:** Critical subset of final capabilities, new privileged audit path, default-credential remediation, private storage/new streaming, existing-file migration, hard-delete removal, safe legacy GET handling.

**Acceptance:** Lower Admin denied restricted mutations; known credential cannot remain operational; sensitive documents are not public; document access is scoped/audited; routine hard delete is unavailable.

**Risk:** High. Preserve one recoverable Super Admin and use copy-first document migration.

### Phase 1 — Privileged Identity and MFA

**Objective:** Complete the role boundary and privileged identity lifecycle.

**Addresses:** SA-001, SA-002, SA-003, SA-004, SA-013.

**Scope:** Full fixed capabilities, active/setup/MFA middleware, bootstrap/invitations, password/reset/recovery, recent reauthentication, session invalidation, final-Super-Admin protection.

**Acceptance:** Both roles require active status and MFA; setup sessions are isolated; security changes remove authority immediately; first account has no known password.

**Risk:** High due to takeover and lockout consequences.

### Phase 2 — State and Workflow Correctness

**Objective:** Enforce state machines, suspension identity, archival, and minimum transactions required for new invariants.

**Addresses:** SA-007, SA-011, state-related SA-008.

**Scope:** Registration/shop/user/report/flag/appeal/admin transitions, suspension references, reconciliation before constraints, soft deletion, archive/restore, real controls, duplicate semantics.

**Acceptance:** Invalid transitions fail; stale appeals cannot restore; one suspension/appeal lifecycle exists; archival preserves relations and disables access; linked employee failure rolls back the whole transition.

**Risk:** High due to legacy-state reconciliation.

### Phase 3 — Transactions, Audit, and Delivery

**Objective:** Standardize atomic workflows, canonical locks, authoritative audit, and after-commit delivery.

**Addresses:** SA-008, SA-010, failure-related SA-007.

**Scope:** Remaining workflow transactions, success/failure audit semantics, legacy writer cutover/backfill, notification deduplication, queue/failed-job visibility, sanitized errors.

**Acceptance:** No partial business state or false success audit; committed transitions have audit; delivery failure does not roll back decisions; backfill preserves reliable history without guessing.

**Risk:** High.

### Phase 4 — Remove Fake and Broken Functionality

**Objective:** Ensure every visible action is real or removed.

**Addresses:** SA-011, SA-012, SA-013, relevant SA-015.

**Scope:** Remove fake communications/reports/exports/tickets/user actions, fix retained credential and monitoring controls, migrate links/callers, verify dependencies before deletion.

**Acceptance:** No UI reports success without persistence; no retained control calls an unregistered route; removed surfaces are absent from navigation.

**Risk:** Medium.

### Phase 5 — Billing Consolidation

**Objective:** Remove duplicate billing behavior and make intervention consistent with authoritative payments.

**Addresses:** SA-009 and billing portions of SA-008/SA-010.

**Scope:** Remove direct plan swap, reconcile `deactivated`, canonical cancellation, non-financial administrative correction, provider-backed refund only when supported, payment reconciliation.

**Acceptance:** No pseudo-refund or paid-history rewrite; cancellation preserves entitlement; provider attempts are idempotent/reconcilable; shop-side billing remains authoritative.

**Risk:** High.

### Phase 6 — Business Document Expiration and Renewal

**Objective:** Add immutable expiration, reminder, renewal, and review behavior.

**Requirement:** New approved operational requirement — business document lifecycle/compliance.

**Scope:** Immutable `shop_documents` versions, DTI/SEC separation, owner declarations, reviewer verification, derived validity, renewal promotion, fixed reminders, dedicated schedule, focused UI, audit, reconciliation, and tests.

**Acceptance:** One current approved document per singleton slot; legacy evidence remains safe; renewal never overwrites history; reminders deduplicate; files remain private; expiration never suspends a shop.

**Dependencies:** Phases 0–4. Phase 5 precedes it for priority but is not a technical dependency.

**Risk:** Medium–High due to historical ambiguity and concurrent promotion.

### Phase 7 — Structural Simplification

**Objective:** Remove duplicate runtime ownership after secured behavior is authoritative.

**Addresses:** SA-015 and remaining SA-011 complexity.

**Scope:** Canonical `/admin` routes, one domain/controller at a time extraction, dead handlers/callers, old audit writers, safe GET compatibility retirement, document route/controller/scheduled-command inventory.

**Acceptance:** One mutation route and owner per capability; no destructive document replacement; HR and shop expiry remain separate; no unnecessary abstraction stack.

**Risk:** Medium.

### Phase 8 — Scale and Final Hardening

**Objective:** Bound operational work and complete integrated negative-path verification.

**Addresses:** SA-014 and residual hardening across all findings and the document requirement.

**Scope:** Pagination, indexes, aggregate reports/monitoring, audit search, bounded expiry scans, renewal queues, timezone/reminder/concurrency tests, private-access regression, measured baselines, compatibility removal.

**Acceptance:** Queries are bounded and scoped; no per-shop/document N+1; reminders and locks preserve invariants; legacy DTI/SEC continuity remains; every visible action and security boundary is verified.

**Risk:** Medium.

## 23. Final Execution Order

```text
0 — Immediate containment
        ↓
1 — Privileged identity and MFA
        ↓
2 — State and workflow correctness
        ↓
3 — Transactions, audit, and delivery
        ↓
4 — Remove fake/broken functionality
        ↓
5 — Billing consolidation
        ↓
6 — Business Document Expiration & Renewal
        ↓
7 — Structural simplification
        ↓
8 — Scale and final hardening
```

Phase numbers express priority, not a ban on the smallest prerequisite change from a later phase. If securing a workflow requires removing its duplicate mutation route or extracting one small shared operation, do that immediately rather than preserving an unsafe intermediate design.

Phase 6 must not delay or expand Phases 0–5. It is the highest-priority new operational feature after the existing defects are stabilized.

## 24. Implementation-Time Confirmations

The design has no unresolved product decision. Phase-level planning must confirm:

- the configured platform timezone, expected to be `Asia/Manila`;
- production scheduler execution and shared cache locking before `onOneServer()`;
- queue workers, retries, and failed-job visibility;
- actual legacy document data before current/version/expiry backfill;
- every supporting-document upload path and stable slot identity;
- provider refund/cancellation capabilities before exposing money movement;
- the exact fixed capability implementation and route grouping against the then-current code.

## 25. Planning Boundary

This document is the design authority, not a file-level execution plan. Each phase receives its own TDD implementation plan immediately before execution. Plans must identify exact files, failing tests, narrow commands, migration/reconciliation steps, deployment order, rollback considerations, and phase-specific acceptance evidence.

