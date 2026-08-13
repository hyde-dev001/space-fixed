# Super Admin Phase 2 State and Workflow Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task in the existing `super-admin-phase-0-containment` worktree. Apply `superpowers:test-driven-development` before implementation changes, `laravel-best-practices` and `security-review` for backend work, `ponytail` for the minimum coherent solution, and `verification-before-completion` before every completion claim. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make registration, user/shop lifecycle, report moderation, flagged-account review, and suspension appeals enforce explicit source states, commit atomically, survive retries and races, preserve historical identity, and deny archived or suspended accounts on their next request.

**Architecture:** Keep the Phase 0 fixed-capability and authoritative-audit foundation and the Phase 1 privileged guard/MFA/session foundation. Add one concrete `AccountSuspension` record for stable suspension identity, nullable current-suspension pointers on users and shops, one minimal linked-employee suspension-provenance reference, reversible Eloquent soft deletion for users and shops, and one persisted exact-set shop-report decision record so warning strikes are business state rather than inferred from logs. Focused workflow services lock aggregate roots in a canonical order and write the state transition plus mandatory privileged audit in one local transaction. Controllers remain request/response orchestrators; notifications are dispatched only after commit. An idempotent operator command reconciles legacy suspended accounts, appeals, and warning strikes without inventing uncertain history.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, MySQL production profile with SQLite test compatibility, Inertia 2, React 18, TypeScript 5.7, PHPUnit 11, Vitest 3, pnpm.

**Status:** APPROVED / FROZEN / READY FOR EXECUTION

---

## Design Authority and Scope Guard

Authoritative design:

- `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`
- Phase 2, "State and Workflow Correctness"
- Sections 8, 10-16, 19-21, and 24 where they define capabilities, state machines, workflow contracts, audit atomicity, security boundaries, invariants, and verification

Implemented prerequisites:

- Completed Phase 1 tip: `22568832f`
- Worktree/branch: `.worktrees/super-admin-phase-0-containment` / `super-admin-phase-0-containment`

This plan is based on the completed post-Phase-1 worktree. Do not execute it against a branch that lacks Phase 0 private-document/audit containment or Phase 1 active-account, MFA, recent-reauthentication, and fixed-capability middleware.

Phase 2 includes:

1. strict registration, shop, user, report, flag, appeal, and administrator regression state contracts;
2. stable suspension identity and current-suspension references;
3. deterministic legacy suspension, appeal, and warning-strike reconciliation;
4. transactional suspension/reactivation, appeal, report, and flag workflows;
5. reversible user/shop archival and restoration with relationship preservation;
6. next-request authority removal for suspended or archived user/shop accounts;
7. real UI controls and negative-path handling for the changed workflows;
8. idempotency and targeted concurrency tests for invariant-bearing mutations.

Do not add in this phase:

- administrator archival/deletion or changes to the approved Admin/Super Admin role model;
- a configurable state-machine, workflow engine, repository layer, command bus, generic moderation framework, or generic polymorphic archival service;
- document expiration, renewal, immutable document versions, DTI/SEC classification, or logical document slots; those remain Phase 6;
- a broad notification-delivery redesign, outbox framework, unrestricted audit-history UI/backfill, or operational-notification read model; those remain Phase 3;
- billing consolidation, provider-backed intervention, or subscription workflow changes; those remain Phase 5;
- route/controller consolidation unrelated to these workflow invariants; that remains Phase 7;
- hard deletion of users, shops, administrators, documents, or historical workflow records;
- automatic shop suspension for document expiration or any other new automation.

## Confirmed Post-Phase-1 Baseline

- Privileged routes use the `super_admin` guard plus active-account, MFA, and fixed-capability middleware.
- `Admin` and `Super Admin` both have registration-review, report-moderation, and account-intervention capabilities; only `Super Admin` can decide appeals.
- `PrivilegedAudit` writes authoritative `activity_log` records with server-generated correlation IDs, but the Phase 2 workflows still write legacy `AuditLog` rows directly or omit privileged audit.
- Registration approval locks the shop, creates a password setup token, approves the shop, and provisions modules, but does not enforce the pending source state, complete documents, transactional audit, or duplicate semantics.
- Registration rejection and owner resubmission do not consistently lock and revalidate state; current resubmission destructively replaces document files. Phase 2 hardens the state transition but deliberately leaves immutable document versions to Phase 6.
- Shop activation can change pending or rejected registrations directly to approved. User/shop suspension and activation are split across partial writes and swallowed linked-employee failures.
- `User` and `ShopOwner` do not use soft deletion. Existing foreign keys would cascade on physical deletion, so archival must use `deleted_at` and never call a force-delete path.
- `CheckEmployeeSuspension` denies a suspended shop owner, suspended parent shop, or suspended employee, but does not deny an already-authenticated standalone suspended user and does not detect an archived parent shop for staff.
- Appeals identify only `account_type + account_id`; a stale appeal can reactivate an account after a later suspension. Appeal expiry currently mutates state from GET endpoints.
- Shop-report warning counts are inferred from legacy `AuditLog` rows; grouped report decisions are not locked or persisted as a domain decision, so races can duplicate warning/suspension effects.
- Flagged-account review accepts invalid source states and persists terminal `banned`; Phase 2 may retain `banned` in storage but must expose and test the accurate domain meaning `account_suspended`.
- Phase 1 already owns administrator lifecycle transactions, self-management denial, recent reauthentication, session invalidation, and final-active-MFA-enrolled-Super-Admin protection. Phase 2 adds regression coverage and must not duplicate that service.

## Frozen Phase 2 Contracts

### State transitions

```text
Shop registration
pending -> approved | rejected
rejected -> pending only through valid owner resubmission
approved -> suspended
suspended -> approved only through reactivation/current-appeal approval

User account
active -> suspended
suspended -> active

Shop report
submitted -> under_review -> dismissed | warned | suspended
terminal -> no further transition

Flagged customer report
pending_review -> under_investigation -> dismissed | account_suspended
terminal -> no further transition

Appeal
eligible -> submitted | expired | superseded
submitted -> approved | rejected | expired | superseded
terminal -> no further transition
```

The database value `review_reports.status = banned` remains an allowed compatibility representation of the domain terminal state `account_suspended`. New code, UI labels, audit events, and tests use `account_suspended`; no Phase 2 enum rewrite is required for `review_reports`.

Administrator state remains the Phase 1 contract:

```text
pending_setup | active | suspended | inactive
no routine deletion or archival
all final-Super-Admin and self-management invariants remain enforced
```

### Stable suspension identity

Each suspension creates one durable `account_suspensions` row with a stable identity; only its explicit closure metadata is later updated. `users.current_suspension_id` and `shop_owners.current_suspension_id` identify the suspension that currently removes authority. `suspension_appeals.suspension_id` identifies the exact suspension being appealed. `employees.privileged_suspension_id` identifies the privileged suspension that currently owns a linked employee's synchronized `suspended` state.

```text
account status = suspended
+ current_suspension_id = Suspension #41
+ Appeal belongs to Suspension #41
= appeal may be submitted or decided

account status = suspended
+ current_suspension_id = Suspension #52
+ Appeal belongs to Suspension #41
= stale appeal is terminal/non-actionable and cannot mutate the account
```

Closing a suspension sets its end metadata, clears the account's current pointer, and leaves no eligible/submitted appeal for that suspension. Manual reactivation marks a non-terminal appeal `superseded`; appeal approval marks it `approved`; appeal rejection leaves the suspension current.

At most one appeal may be linked to one suspension. A current suspension is enforced by locking the account root before reading or creating suspension/appeal state. Do not introduce a generic morph-map subsystem: store the existing bounded account types `shop_owner` and `customer`, validate them in the model/service, and use explicit model resolution.

### Canonical lock order

All services use these orders:

```text
Direct account lifecycle
account root -> current suspension -> appeal -> linked employee

Shop report decision
shop root -> open report IDs ascending -> current suspension -> appeal

Flagged-account decision
customer root -> review report -> current suspension -> appeal -> linked employee

Appeal submit/decision
account root -> suspension -> appeal -> linked employee when restoration needs it

Registration decision
shop root -> current required documents by ID ascending -> setup-token row
```

Where an endpoint starts from an appeal/report ID, it may perform an unlocked lookup only to discover the aggregate root ID. The transaction must then re-fetch and verify every row under the canonical locks before mutation.

### Duplicate and conflict semantics

- A retry of an already committed identical terminal outcome returns the current result and creates no second token, strike, suspension, appeal, audit success, or notification.
- A request that conflicts with the current terminal/source state returns HTTP `409` for JSON and an equivalent Inertia validation/error response without mutation.
- Validation failures remain `422`; missing records remain `404`; unauthorized roles remain `403`.
- Concurrent requests are serialized by aggregate-root locks. The losing request must re-evaluate state after obtaining the lock.

Concrete examples:

```text
approve pending registration twice -> one approval token/audit/notification
approve then reject same registration -> 409
suspend approved shop twice with same current suspension -> one suspension/appeal
reactivate already-active account after same suspension closed -> idempotent current result
warn same locked open-report set twice -> one grouped decision/strike
decide submitted appeal twice the same way -> one terminal decision
approve appeal after manual reactivation -> 409, account unchanged
```

### Exact shop-report decision set

The moderator decides only the bounded report IDs actually presented and reviewed. The request carries `shop_id`, a distinct bounded `report_ids` array, requested outcome, and notes. Canonicalize report IDs as ascending integers and derive a server-owned `decision_key = sha256(shop_id + ':' + comma-joined canonical IDs)`. Persist the key with a unique constraint; do not accept a client decision key. `decision_key` may be null only on reconciled legacy warning actions whose report membership cannot be known; every runtime decision requires it.

Inside the transaction:

```text
lock shop root
-> lock exactly the requested report IDs ascending
-> verify every ID exists, belongs to that shop, and has an expected open state
-> persist submitted -> under_review -> terminal for that exact set
-> persist exact sorted IDs + server-derived decision_key on the action
-> audit
-> commit
```

A report submitted after page render is not in the request and remains open for a later review. Retrying the same shop + canonical report set + requested outcome returns the existing decision without duplicate effects. Reusing the same set with a conflicting outcome returns `409`. An omitted, foreign, duplicate, over-limit, or partially terminal set cannot be silently widened or reduced.

### Linked employee synchronization

The existing user-to-employee association is email-based and not guaranteed unique. Under the user row lock, load non-archived employee matches in ascending ID order with `lockForUpdate()`.

- zero matches: the customer/user transition may proceed;
- more than one match: reject with `409`; do not guess;
- one active match during suspension: store its ID and prior status on the suspension, then set it to `suspended` and set `employees.privileged_suspension_id` to the new suspension ID in the same transaction;
- one inactive or terminated match: it is already non-operational and is not rewritten;
- one already-suspended match not attributable to the current operation: reject with `409`;
- reactivation restores only the employee recorded by that suspension and only when it is still present, non-archived, `suspended`, and its `privileged_suspension_id` equals that exact suspension ID; restoration clears the marker in the same update;
- every independent employee-status mutation path must set `privileged_suspension_id = null` in the same update, proving that a later HR/shop-owner action superseded privileged ownership even when the resulting status remains `suspended`.

Repository inspection found no existing employee status version or ownership field, so the nullable suspension reference is the minimum reliable provenance mechanism. Do not infer ownership from `updated_at`, audit text, email, or status alone. If marker attribution cannot be proven, return `409` and roll back the entire account restoration.

Any database or audit failure rolls back account, suspension, appeal, report/flag, and employee changes together. Notification failure occurs after commit and cannot roll back or counterfeit the authoritative outcome.

### Archival

`User` and `ShopOwner` use Eloquent `SoftDeletes`. Archive/restore:

- requires `intervene_accounts`, a non-empty reason, and Phase 1 `privileged.recent` middleware;
- preserves business status, current suspension identity, documents, reports, orders, employees, and all other relations;
- never cascades or physically deletes related rows;
- is idempotent for an identical already-achieved archive/restore state;
- writes the local state transition and mandatory privileged audit in the same transaction;
- leaves a restored suspended account suspended and denied;
- makes archived accounts unavailable through normal authentication/providers and normal application queries.

The existing status middleware must additionally deny a current standalone user whose `users.status != active` and a staff user whose parent shop is archived. Keep the existing `check.suspension` middleware alias/class in Phase 2 to avoid broad route churn; broaden its behavior and tests, and reserve naming cleanup for Phase 7.

### HTTP method safety

No GET or HEAD request may suspend, reactivate, archive, restore, expire an appeal, or otherwise mutate lifecycle state. Legacy GET routes, if retained, are navigation/redirect compatibility only. Every lifecycle mutation uses POST/PATCH/PUT/DELETE with Laravel CSRF protection, authentication, active/MFA checks, the required fixed capability, and recent reauthentication where the contract requires it.

### Legacy reconciliation without guessing

Migrations are additive DDL only. An idempotent `super-admin:reconcile-phase-two-state` command performs a dry run by default and requires `--apply` to mutate.

For each user/shop:

1. expire eligible/submitted appeals whose `expires_at` is in the past;
2. if the account is operational, mark remaining legacy eligible/submitted appeals `superseded` because no current suspension exists;
3. if the account is suspended and lacks a current suspension, create one `source = legacy_reconciliation` using a recorded shop reason or a uniquely attributable appeal reason; leave reason null and report it when evidence is absent;
4. link a live appeal only when exactly one eligible/submitted appeal can be attributed to that account; if multiple remain, mark them all `superseded`, link none, and report the ambiguity;
5. leave approved/rejected/expired historical appeals unlinked; they remain evidence and can never mutate an account;
6. never infer a linked employee or prior employee status for a legacy suspension.

For legacy warning strikes, create one `ShopReportModerationAction` compatibility row per unique legacy `AuditLog` `shop_report_warn` record, keyed by `legacy_audit_log_id`. Within each shop, order reliable warning audits by `created_at ASC, id ASC` and assign `warning_strike_number` as 1, 2, 3, and so on. Do not guess which report rows belonged to that historical decision. Re-running the command creates no duplicates or renumbering.

For runtime warnings, lock the shop root and assign `max(warning_strike_number) + 1`; a requested warning that reaches the threshold still owns that next strike even when its applied outcome is suspension. Enforce unique `(shop_owner_id, warning_strike_number)` for non-null strike numbers where the MySQL/SQLite schema supports the ordinary nullable composite unique constraint, and prove duplicate numbers cannot commit in the real-database concurrency profile.

The command accepts no operation/correlation UUID input. At command start it generates one UUID server-side, prints it once, and uses that same UUID for every authoritative audit event in that execution. A later run always receives a new server-generated UUID; idempotency comes from domain identities such as account IDs and `legacy_audit_log_id`, never from correlation-ID reuse. The command prints counts and ambiguous IDs, uses bounded `chunkById()`, wraps each aggregate in a transaction, and sends no customer/shop notifications.

A legacy suspended account with no safely attributable live appeal remains suspended with a reconstructed current suspension and no fabricated appeal. The command reports it in a dedicated `operator_review_required` list/count. The rollout runbook requires an operator to review those cases; Phase 2 does not invent an expiry date, fabricate appeal history, or silently issue a replacement appeal.

### Registration and document boundary

Approval requires one current row for every Phase 2 required type from `ShopOwnerDocumentRequirementService`, a non-empty private storage path/disk, and a stored file that exists. Under the registration lock, the selected current required rows move from `pending` to `approved` with the shop. Rejection requires a reason and moves current pending required rows to `rejected` with the shop. Resubmission moves `rejected -> pending`, resets current documents to pending, and increments the retry count once.

Phase 2 must not split DTI/SEC, add expiration metadata, or build immutable document versions. Preserve the current resubmission upload behavior behind a clearly tested Phase 6 boundary; do not expand destructive document replacement beyond that existing flow.

### Audit and delivery boundary

Every committed local privileged transition introduced or changed here writes one allowlisted `PrivilegedAudit` event inside the same transaction. Event properties contain IDs, source/target states, reasons/notes where operationally necessary, suspension/appeal/report-action IDs, and server correlation; they never contain passwords, setup/appeal bearer tokens, private paths, document contents, or customer identity snapshots.

Phase 2 reuses existing notifications/mailables and dispatches them through `DB::afterCommit()` or notification `afterCommit()` behavior. It does not build Phase 3's general delivery/outbox architecture. Delivery exceptions are reported with non-secret IDs and do not alter the committed workflow result.

## Acceptance Criteria

Phase 2 is complete only when:

- registration approval/rejection/resubmission enforce source state, complete documents, duplicate semantics, one setup token, atomic audit, and post-commit delivery;
- pending/rejected shops cannot be activated through general reactivation;
- every new user/shop suspension has one stable current identity and at most one linked appeal;
- linked-employee restoration requires exact suspension provenance, and independent employee status changes clear that provenance;
- stale, expired, superseded, terminal, or wrong-suspension appeals cannot mutate an account;
- manual reactivation and appeal approval close the exact current suspension and synchronize an applicable employee atomically;
- ambiguous employee email links fail closed and no partial account change commits;
- report and flagged-account state machines reject invalid transitions and terminal reopening;
- one grouped report decision creates at most one warning strike, suspension, appeal, audit success, and notification under retry/concurrency;
- grouped report moderation mutates only the exact bounded IDs reviewed by the moderator; later reports remain open, and exact-set conflicting retries fail;
- warning strikes are numbered deterministically, uniquely per shop, and cannot duplicate under real-database concurrency;
- user/shop archival preserves status and relations, disables normal authentication/participation, and restores without silently activating a suspended account;
- current cookies lose authority on the next request after suspension or archival even if physical session cleanup is delayed;
- regular Admin may view appeals and manage own security but receives `403` from appeal-decision endpoints; Super Admin may decide valid current appeals;
- Phase 1 administrator lifecycle and final-Super-Admin protections still pass unchanged;
- the legacy reconciliation command is dry-run by default, idempotent, bounded, auditable, and reports rather than guesses ambiguous history;
- every reconciliation execution owns a fresh server-generated correlation UUID and accepts no client/operator UUID input;
- legacy suspended accounts without a safely attributable appeal are reported for operator review without fabricated appeal history;
- no GET/HEAD compatibility route mutates lifecycle or expiry state;
- no user/shop/admin hard-delete route, force-delete call, document-version subsystem, generic workflow engine, or Phase 3+ feature is introduced.

---

## Task 1: Pin the Phase 1 Baseline and Add Phase 2 Contract Fixtures

**Files:**

- Create: `tests/Concerns/BuildsPhaseTwoWorkflowFixtures.php`
- Create: `tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php`
- Reuse: `tests/Concerns/AuthenticatesPrivilegedUsers.php`
- Read only: `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`

- [ ] **Step 1: Verify the execution baseline**

```powershell
git status --short --branch
git rev-parse HEAD
php artisan test tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
```

Require a clean worktree at `22568832f` or an explicitly reviewed descendant containing only approved Phase 1 follow-up commits. Stop on unrelated working-tree changes or failing prerequisite tests.

- [ ] **Step 2: Add narrow shared fixtures, not a workflow test framework**

Provide helpers for:

- a completed privileged Admin/Super Admin session using the existing Phase 1 helper;
- a pending registration with all required private documents;
- active/suspended user and approved/suspended shop aggregates;
- a uniquely linked or deliberately ambiguous employee by email;
- a current suspension plus eligible/submitted appeal;
- open report groups and flagged review reports.

Keep helpers concrete and data-only. Do not hide assertions, transactions, HTTP requests, or workflow behavior inside them.

- [ ] **Step 3: Record executable prerequisite contracts**

Add focused passing tests for the Phase 1 conditions Phase 2 depends on: both privileged roles reach their approved operational capabilities only through completed MFA sessions, appeal decisions remain Super-Admin-only, private registration documents use protected routes, administrator lifecycle has no archive/delete endpoint, and user/shop hard-delete routes remain absent. Record the known Phase 2 defects in test names/comments only where a runnable assertion can remain green. Each later task owns its own RED test immediately before the corresponding production change; do not commit a deliberately failing cross-phase test suite.

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php
```

- [ ] **Step 4: Commit test scaffolding**

```powershell
git add -- tests/Concerns/BuildsPhaseTwoWorkflowFixtures.php tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php
git commit -m "test: define phase two workflow contracts"
```

## Task 2: Add Suspension, Moderation, Appeal, and Archival Schema

**Files:**

- Create with Artisan: `database/migrations/*_create_account_suspensions_table.php`
- Create with Artisan: `database/migrations/*_add_phase_two_lifecycle_columns.php`
- Create with Artisan: `database/migrations/*_create_shop_report_moderation_actions_table.php`
- Create: `app/Models/AccountSuspension.php`
- Create: `app/Models/ShopReportModerationAction.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/ShopOwner.php`
- Modify: `app/Models/Employee.php`
- Modify: `app/Models/SuspensionAppeal.php`
- Modify: `app/Models/ShopReport.php`
- Modify: `app/Models/ReviewReport.php`
- Create: `tests/Feature/SuperAdmin/PhaseTwoSchemaTest.php`
- Modify/create relevant model factories only where needed by tests

- [ ] **Step 1: Write RED schema/model tests**

Assert:

- `account_suspensions` contains bounded account identity, reason/source, actor/timestamps, end metadata, and optional linked-employee snapshot;
- users/shops have nullable indexed `current_suspension_id` and `deleted_at`;
- employees have nullable unique `privileged_suspension_id` provenance referencing the suspension that owns their synchronized status; it is guarded from request mass assignment and only the suspension service may assign a non-null value;
- appeals have nullable unique `suspension_id`, nullable reviewer ID, and a status column that accepts `superseded` while preserving all existing values;
- `shop_report_moderation_actions` stores shop, actor, requested/applied action, exact sorted report IDs, a unique server-derived decision key, optional strike number, source, and unique nullable legacy audit ID;
- model casts, hidden/fillable boundaries, relations, and `SoftDeletes` behave as intended;
- rollback order drops foreign keys before referenced tables;
- fresh migration and rollback work on SQLite, with a documented MySQL migration check later.

- [ ] **Step 2: Generate and implement focused additive migrations**

Use Artisan-generated migrations. Keep DDL separate from reconciliation DML. Use explicit indexes for:

```text
account_suspensions(account_type, account_id, ended_at)
suspension_appeals(suspension_id unique nullable)
shop_report_moderation_actions(shop_owner_id, created_at)
shop_report_moderation_actions(decision_key unique nullable for legacy only)
shop_report_moderation_actions(shop_owner_id, warning_strike_number unique nullable)
users(current_suspension_id, deleted_at)
shop_owners(current_suspension_id, deleted_at)
employees(privileged_suspension_id unique nullable)
```

Change `suspension_appeals.status` from the deployed enum to a bounded string only as needed to support `superseded` portably. Preserve existing values/defaults. Do not change `review_reports.status`.

- [ ] **Step 3: Add the two concrete domain models and relations**

`AccountSuspension` owns explicit helpers/scopes for current/closed state and validates supported account types through constants or an enum local to this domain. `User` and `ShopOwner` add `SoftDeletes`, `currentSuspension()`, and suspension history relations. `Employee` adds the nullable privileged-suspension provenance relation. `SuspensionAppeal` belongs to a suspension and reviewer. `ShopReportModerationAction` is the exact-set decision/warning source of truth and derives no identity from client input.

Do not add interfaces, repositories, morph maps, observers, or model methods that perform multi-row workflows.

- [ ] **Step 4: Run schema tests and migration cycles**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseTwoSchemaTest.php
php artisan migrate:fresh --env=testing
php artisan migrate:rollback --step=3 --env=testing
php artisan migrate --env=testing
```

- [ ] **Step 5: Commit**

```powershell
git add -- database/migrations app/Models tests/Feature/SuperAdmin/PhaseTwoSchemaTest.php database/factories
git commit -m "feat: add phase two workflow schema"
```

Review staged paths before committing; do not stage unrelated model/factory edits.

## Task 3: Implement Safe Legacy State Reconciliation

**Files:**

- Create: `app/Console/Commands/ReconcilePhaseTwoState.php`
- Create: `app/Services/PhaseTwoStateReconciler.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Create: `tests/Feature/SuperAdmin/PhaseTwoStateReconciliationTest.php`
- Create later in Task 10: `docs/runbooks/super-admin-phase-2-state-workflows.md`

- [ ] **Step 1: Write RED reconciliation tests**

Cover:

- dry run makes no database changes and prints proposed counts;
- suspended account with no appeal receives one legacy suspension/current pointer with no invented reason;
- suspended account with no safely attributable live appeal is listed as `operator_review_required` and receives no fabricated appeal;
- suspended account with exactly one live appeal links it;
- multiple live appeals become superseded and are reported, not arbitrarily selected;
- operational account live appeals become superseded;
- expired appeals become expired before attribution;
- terminal historical appeals remain unchanged and unlinked;
- each legacy warning audit becomes one compatibility moderation-action row numbered deterministically by shop, `created_at ASC, id ASC`;
- duplicate `(shop_owner_id, warning_strike_number)` values cannot be produced;
- a second `--apply` run produces zero additional rows/audits;
- the command exposes no correlation/operation UUID input option and each execution prints one newly server-generated UUID;
- chunk boundaries do not skip modified records;
- command failure in one aggregate rolls back that aggregate and exits non-zero with its ID.

- [ ] **Step 2: Implement bounded dry-run/apply behavior**

Use `chunkById()` and one transaction per account or warning-audit chunk. Lock each account before rechecking. Accept no operation/correlation UUID argument; generate it server-side at command start, print it once, and use it for all audit events from that execution. The dry run must use read-only queries and never simulate by opening then rolling back transactions that could trigger observers or external work.

- [ ] **Step 3: Extend `PrivilegedAudit` narrowly**

Add allowlisted console events for:

```text
legacy_account_suspension_reconciled
legacy_appeal_superseded
legacy_warning_strike_reconciled
```

Properties contain only aggregate IDs, prior/new status, ambiguity/operator-review count, and the server-generated operation UUID. Do not record appeal tokens, recipient email, private paths, or copied legacy payloads.

- [ ] **Step 4: Verify idempotency and output**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseTwoStateReconciliationTest.php
php artisan super-admin:reconcile-phase-two-state --help
php artisan super-admin:reconcile-phase-two-state
```

The final command is a dry run against the current environment. Do not run `--apply` against a non-test database during plan execution without explicit deployment authorization.

- [ ] **Step 5: Commit**

```powershell
git add -- app/Console/Commands/ReconcilePhaseTwoState.php app/Services/PhaseTwoStateReconciler.php app/Services/PrivilegedAudit.php tests/Feature/SuperAdmin/PhaseTwoStateReconciliationTest.php
git commit -m "feat: reconcile legacy suspension state"
```

## Task 4: Enforce Registration Decision and Resubmission Contracts

**Files:**

- Create: `app/Http/Requests/SuperAdmin/RejectShopOwnerRegistrationRequest.php`
- Create: `app/Services/ShopOwnerRegistrationDecisionService.php`
- Modify: `app/Services/ShopOwnerDocumentRequirementService.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php`
- Modify: `app/Http/Controllers/ShopOwnerAuthController.php`
- Modify: `app/Notifications/ShopOwnerApproved.php`
- Modify: `app/Notifications/ShopOwnerRejected.php`
- Modify: `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx`
- Create: `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- Modify: `tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php`
- Modify: `resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts`

- [ ] **Step 1: Write RED registration transition tests**

Test pending approval/rejection, missing/stale/missing-on-disk required documents, required rejection reason, rejected resubmission, invalid general activation, identical retry, conflicting retry, setup-token uniqueness, module provisioning, document statuses, rollback on audit/provisioning failure, and no notification before commit.

Preserve private document URLs from Phase 0 and assert no `/storage/` URL reappears.

- [ ] **Step 2: Extend the existing requirement service minimally**

Add methods that select the latest row for each normalized required type and return missing/invalid types. Reuse existing type normalization. Do not split DTI/SEC or add expiration/version semantics.

- [ ] **Step 3: Implement the decision service**

Under the canonical lock order:

- approve only pending, complete registrations;
- create/update the password setup token only for the first committed approval;
- set selected current documents and shop to approved;
- initialize eligible modules;
- write `shop_registration_approved` audit in-transaction;
- reject only pending, require reason, set selected documents/shop rejected, and write `shop_registration_rejected` audit;
- return an outcome object/array sufficient for the controller to distinguish applied versus idempotent without a generic result hierarchy.

Dispatch the existing approval/rejection notification after commit only when applied. A delivery failure is reported and does not change the response into a false rollback.

- [ ] **Step 4: Harden owner resubmission without implementing Phase 6**

Stage validated new files first, lock the rejected shop, revalidate signed-link state and retry limit, then move shop/current document rows to pending, clear rejection reason, and increment `resubmission_count` in one database transaction. Delete superseded files only after commit; delete newly staged files if the transaction fails. A committed retry while already pending is idempotent and does not increment or replace again. Preserve existing file cleanup safeguards and add an explicit test documenting that immutable document rows begin only in Phase 6.

- [ ] **Step 5: Make UI controls reflect server state**

Show approve/reject only for pending rows, require a visible rejection reason, disable controls while submitting, and surface `409/422` responses without optimistic false success. Server enforcement remains authoritative.

- [ ] **Step 6: Verify and commit**

```powershell
php artisan test tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
pnpm run test:frontend -- resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
git diff --check
git add -- app/Http/Requests/SuperAdmin/RejectShopOwnerRegistrationRequest.php app/Services/ShopOwnerRegistrationDecisionService.php app/Services/ShopOwnerDocumentRequirementService.php app/Services/PrivilegedAudit.php app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php app/Http/Controllers/ShopOwnerAuthController.php app/Notifications/ShopOwnerApproved.php app/Notifications/ShopOwnerRejected.php resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
git commit -m "feat: enforce registration decision states"
```

## Task 5: Implement Atomic Account Suspension, Reactivation, Archive, and Restore

**Files:**

- Create: `app/Http/Requests/SuperAdmin/AccountSuspensionRequest.php`
- Create: `app/Http/Requests/SuperAdmin/AccountReactivationRequest.php`
- Create: `app/Http/Requests/SuperAdmin/AccountArchiveRequest.php`
- Create: `app/Http/Requests/SuperAdmin/AccountRestoreRequest.php`
- Create: `app/Services/AccountSuspensionService.php`
- Create: `app/Services/AccountLifecycleService.php`
- Modify: `app/Services/SuspensionAppealService.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `app/Http/Middleware/CheckEmployeeSuspension.php`
- Modify: `app/Models/Employee.php`
- Modify: `app/Repositories/HR/EmployeeRepository.php`
- Modify: `app/Http/Controllers/EmployeeController.php`
- Modify: `app/Http/Controllers/Erp/HR/EmployeeController.php`
- Modify: `app/Http/Controllers/Erp/HR/SuspensionRequestController.php`
- Modify: `app/Http/Controllers/Erp/Manager/SuspensionApprovalController.php`
- Modify: `app/Http/Controllers/ShopOwner/SuspensionFinalApprovalController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php`
- Create: `tests/Feature/SuperAdmin/EmployeeSuspensionProvenanceTest.php`
- Modify: `tests/Feature/Auth/SuspensionSessionEnforcementTest.php`
- Modify: `tests/Feature/SuperAdmin/HardDeleteContainmentTest.php`

- [ ] **Step 1: Write RED lifecycle tests**

Cover both `User` and `ShopOwner`:

- valid source transitions and required reasons;
- pending/rejected shop reactivation denial;
- one stable suspension/current pointer/appeal;
- identical retries and conflicting transitions;
- zero, unique-active, inactive/terminated, already-suspended, missing-on-restore, and ambiguous linked employees;
- exact linked-employee provenance marker creation, matching restoration/clear, and `409` on missing/mismatched attribution;
- every existing independent employee suspend/activate/inactivate workflow clears privileged provenance in the same status update, including model/repository, legacy employee controller, ERP HR, manager approval, and shop-owner final approval paths;
- rollback when employee or mandatory audit write fails;
- manual reactivation closes exact suspension and supersedes its live appeal;
- archive/restore preserve status and representative relations;
- archive/restore require capability + recent reauthentication + reason;
- regular hard delete/force delete endpoints remain absent;
- archived or suspended existing sessions lose authority on next request;
- archived parent shop denies staff even when the staff user row remains active;
- restoring a suspended account does not activate it.

- [ ] **Step 2: Implement a transaction-neutral suspension primitive**

`AccountSuspensionService` operates only on caller-supplied locked account rows and owns the bounded invariants for creating/closing `AccountSuspension`, setting/clearing current pointers, creating/superseding the linked appeal, and synchronizing the uniquely resolved employee. When it suspends an active linked employee, use trusted model assignment (not request mass assignment) to set `privileged_suspension_id` to the new suspension ID. Restore only on exact employee ID + suspended status + marker match, and clear the marker atomically. Document in method names/PHPDoc that callers must hold the aggregate transaction/lock; do not expose these methods directly to controllers.

- [ ] **Step 3: Implement direct lifecycle transactions**

`AccountLifecycleService` resolves explicit user/shop types, begins the transaction, locks the root, calls the suspension primitive, applies archive/restore with `delete()`/`restore()`, and writes one mandatory allowlisted audit event. It must never call `forceDelete()`, delete relations, or swallow exceptions.

Update every repository/controller/model path found by the Employee status-writer inventory so an employee status change outside `AccountSuspensionService` includes `privileged_suspension_id => null`. Keep those changes surgical; do not refactor the surrounding HR approval workflows in Phase 2. A final `rg` verification in Task 11 must catch newly missed writers.

Use events:

```text
user_suspended / user_reactivated / user_archived / user_restored
shop_suspended / shop_reactivated / shop_archived / shop_restored
```

Include suspension ID and source/target state; redact private paths and bearer values.

- [ ] **Step 4: Keep controllers and routes explicit**

Retain existing mutation route names only where caller compatibility requires them, but make canonical actions `suspend`, `reactivate`, `archive`, and `restore`. Any legacy GET is redirect/navigation-only and must never call a lifecycle service or write state. Add archive/restore mutation routes with:

```text
super_admin.auth
privileged.active
privileged.mfa
privileged.capability:intervene_accounts
privileged.recent
```

Resolve archived targets explicitly with `withTrashed()` inside the focused controller/service; do not globally bind trashed users/shops.

- [ ] **Step 5: Broaden next-request authority enforcement**

Keep the `CheckEmployeeSuspension` class/alias for compatibility, but deny:

- current `auth:user` model status other than active;
- current shop-owner status other than approved;
- parent shop found with `withTrashed()` when archived or non-operational;
- linked employee status other than active where the existing route family relies on employee access.

Use generic account-unavailable messages/codes that do not disclose private moderation details. Status/database checks are the authority boundary; physical session cleanup remains secondary.

- [ ] **Step 6: Verify and commit**

```powershell
php artisan test tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/EmployeeSuspensionProvenanceTest.php tests/Feature/Auth/SuspensionSessionEnforcementTest.php tests/Feature/SuperAdmin/HardDeleteContainmentTest.php
php artisan route:list --path=admin/users --except-vendor
php artisan route:list --path=admin/shops --except-vendor
git diff --check
git add -- app/Http/Requests/SuperAdmin app/Services/AccountSuspensionService.php app/Services/AccountLifecycleService.php app/Services/SuspensionAppealService.php app/Services/PrivilegedAudit.php app/Http/Controllers/SuperAdminController.php app/Http/Middleware/CheckEmployeeSuspension.php app/Models/Employee.php app/Repositories/HR/EmployeeRepository.php app/Http/Controllers/EmployeeController.php app/Http/Controllers/Erp/HR/EmployeeController.php app/Http/Controllers/Erp/HR/SuspensionRequestController.php app/Http/Controllers/Erp/Manager/SuspensionApprovalController.php app/Http/Controllers/ShopOwner/SuspensionFinalApprovalController.php routes/web.php tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/EmployeeSuspensionProvenanceTest.php tests/Feature/Auth/SuspensionSessionEnforcementTest.php tests/Feature/SuperAdmin/HardDeleteContainmentTest.php
git commit -m "feat: enforce account lifecycle invariants"
```

## Task 6: Make Shop-Report Moderation Transactional and Idempotent

**Files:**

- Create: `app/Http/Requests/SuperAdmin/ModerateShopReportsRequest.php`
- Create: `app/Services/ShopReportModerationService.php`
- Modify: `app/Http/Controllers/superAdmin/ShopReportsController.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `app/Mail/ShopReportWarningMail.php` only if after-commit queue compatibility requires it
- Modify: `resources/js/Pages/superAdmin/Shops/ShopReports.tsx`
- Modify: `tests/Feature/Reports/ShopAndCustomerReportFlowTest.php`
- Create: `tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php`
- Create: `resources/js/Pages/superAdmin/Shops/__tests__/ShopReports.test.tsx`

- [ ] **Step 1: Write RED grouped-decision tests**

Cover deterministic lock order, submitted/under-review to terminal transitions, terminal reopening denial, grouped report IDs, required suspension notes, one action row, warning numbers 1/2/3, third warning applying suspension, one suspension/appeal, retries with no duplicate effect, new reports after a prior decision forming a new group, rollback on audit/suspension failure, and delivery only after commit.

Add the exact-set race explicitly: render IDs `[10, 11, 12]`, insert open report `13` before mutation, submit `[10, 11, 12]`, and prove `13` remains open. Test duplicate/foreign/empty/more-than-100 IDs as `422/409` without mutation, a same-set/same-outcome retry as idempotent, and a same-set/conflicting-outcome retry as `409`. Assert `decision_key` is server-derived and cannot be supplied by the client.

- [ ] **Step 2: Implement the grouped workflow service**

`ModerateShopReportsRequest` requires `report_ids` as an array with 1-100 distinct integer IDs, allowlists the outcome, bounds notes, and prohibits `decision_key`. Ownership/state validation remains under the service transaction because request-time `exists` checks cannot establish the locked decision contract.

Inside one transaction:

1. lock shop root;
2. canonicalize the route-bound shop ID plus the request's distinct `report_ids` (1-100), derive the server-owned decision key, and check for an existing exact-set decision;
3. lock exactly those report IDs ascending--never a transaction-time query for all open reports;
4. verify every requested row exists, belongs to the route-bound shop, and has an expected state; never silently add, drop, or substitute an ID;
5. persist the valid `submitted -> under_review -> terminal` sequence (a submitted row may enter review and receive the terminal decision in the same transaction, but code must not write a direct submitted-to-terminal jump);
6. assign runtime warning number as the locked shop's current maximum plus one, including a threshold warning whose applied outcome becomes suspension;
7. create one moderation action with the exact sorted IDs, unique decision key, warning number where applicable, and requested/applied outcome;
8. for suspension, call the locked suspension primitive without opening a nested transaction;
9. write one mandatory privileged audit event;
10. commit, then send one warning or suspension notification when applied.

An identical exact-set retry returns the existing matching action even though its reports are now terminal; the same decision key with another outcome returns `409`. Reports absent from the request remain open and form a later decision set. The ordinary nullable composite unique constraint on `(shop_owner_id, warning_strike_number)` is the database defense against duplicate non-null strike numbers; the shop-root lock is the runtime concurrency owner. Never use `AuditLog` as the strike counter after reconciliation.

- [ ] **Step 3: Thin the controller and update the read model**

Inject the service, use the Form Request, and derive warning counts from `ShopReportModerationAction`. The read model supplies the exact actionable open IDs the moderator is shown; the UI submits those IDs unchanged, with a maximum of 100 per decision. A newly submitted ID is not appended client-side or server-side. Eager load/select bounded columns and paginate or preserve the current bounded response only if measurements confirm it is safe; broad scaling work remains Phase 8.

- [ ] **Step 4: Align UI behavior**

Disable actions when a group has no open reports, require notes for suspension, show the persisted strike count/threshold outcome, and handle `409/422` without optimistic local mutation.

- [ ] **Step 5: Verify and commit**

```powershell
php artisan test tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php
pnpm run test:frontend -- resources/js/Pages/superAdmin/Shops/__tests__/ShopReports.test.tsx
git diff --check
git add -- app/Http/Requests/SuperAdmin/ModerateShopReportsRequest.php app/Services/ShopReportModerationService.php app/Http/Controllers/superAdmin/ShopReportsController.php app/Services/PrivilegedAudit.php app/Mail/ShopReportWarningMail.php resources/js/Pages/superAdmin/Shops/ShopReports.tsx tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php resources/js/Pages/superAdmin/Shops/__tests__/ShopReports.test.tsx
git commit -m "feat: serialize shop report decisions"
```

Stage `ShopReportWarningMail.php` only if it actually changed.

## Task 7: Enforce the Flagged-Account Review State Machine

**Files:**

- Create: `app/Http/Requests/SuperAdmin/FlaggedAccountDecisionRequest.php`
- Create: `app/Services/FlaggedAccountModerationService.php`
- Modify: `app/Http/Controllers/superAdmin/FlaggedAccountsController.php`
- Modify: `app/Models/ReviewReport.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx`
- Create: `tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php`
- Create: `resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx`

- [ ] **Step 1: Write RED state and rollback tests**

Test:

- pending review may only enter under investigation;
- dismissal/account suspension may only leave under investigation;
- terminal rows cannot reopen;
- persisted `banned` is exposed as `account_suspended`;
- customer must be active for a new suspension decision;
- one current suspension/appeal is created through the shared primitive;
- identical retry is idempotent and conflicting retry returns `409`;
- missing/ambiguous linked employee and audit failures roll back report/customer/suspension together;
- regular Admin may perform moderation but cannot use appeal decisions.

- [ ] **Step 2: Implement the focused moderation service**

Lock customer root before the report, revalidate both, apply the transition, call the locked suspension primitive for `account_suspended`, persist `banned` only as the compatibility value, and write one allowlisted audit inside the same transaction. Do not create a generic report service shared with shop reports; the roots and invariants differ.

- [ ] **Step 3: Make the UI use domain labels and real states**

Display `Pending review`, `Under investigation`, `Dismissed`, and `Account suspended`; show only valid next controls; require a reason for suspension; and surface server conflicts. Do not display `Ban` as if it were permanent deletion.

- [ ] **Step 4: Verify and commit**

```powershell
php artisan test tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php
pnpm run test:frontend -- resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx
git diff --check
git add -- app/Http/Requests/SuperAdmin/FlaggedAccountDecisionRequest.php app/Services/FlaggedAccountModerationService.php app/Http/Controllers/superAdmin/FlaggedAccountsController.php app/Models/ReviewReport.php app/Services/PrivilegedAudit.php resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx
git commit -m "feat: enforce flagged account review states"
```

## Task 8: Tie Appeal Submission and Decisions to the Current Suspension

**Files:**

- Create: `app/Http/Requests/SuperAdmin/DecideSuspensionAppealRequest.php`
- Create: `app/Console/Commands/ExpireSuspensionAppeals.php`
- Modify: `app/Services/SuspensionAppealService.php`
- Modify: `app/Http/Controllers/SuspensionAppealPublicController.php`
- Modify: `app/Http/Controllers/superAdmin/SuspensionAppealsController.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `routes/console.php`
- Modify: `resources/js/Pages/superAdmin/Users/SuspensionAppeals.tsx`
- Modify: `tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php`
- Create: `tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php`
- Create: `resources/js/Pages/superAdmin/Users/__tests__/SuspensionAppeals.test.tsx`

- [ ] **Step 1: Write RED appeal identity tests**

Cover:

- GET show/index never mutates expiry state;
- submission requires eligible, unexpired appeal tied to the current suspension and currently suspended account;
- duplicate submission is idempotent only for the same committed message/outcome; conflicting repeat fails;
- only Super Admin may approve/reject; Admin may view the queue and manage own MFA/security;
- approval restores exact current suspension and linked employee atomically;
- rejection leaves account/current suspension intact;
- stale, superseded, expired, terminal, unlinked legacy, and wrong-account appeals cannot mutate;
- manual reactivation racing appeal approval yields one valid terminal result;
- decision notification occurs after commit once;
- expiry command is bounded/idempotent and changes only eligible/submitted expired rows.

- [ ] **Step 2: Refactor the existing appeal service around locked workflows**

Keep notification composition in `SuspensionAppealService`, but add concrete submit/approve/reject/expire methods that own or clearly participate in the canonical aggregate transaction. Reuse `AccountSuspensionService` for restoration and supersession. Do not duplicate account-type resolution in controllers.

Public GET computes an effective expired/non-actionable presentation without writing. Public POST locks and may atomically persist `expired` when the deadline passed. Decision endpoints lock account, suspension, and appeal in canonical order and write mandatory audit with reviewer ID/notes.

- [ ] **Step 3: Add scheduled expiry ownership**

`suspension-appeals:expire` processes bounded IDs with `chunkById()`. Schedule it at a simple fixed interval with `withoutOverlapping()` and `onOneServer()` only where the configured shared cache supports it. The command is the owner of routine expiry detection; route GETs are read-only.

- [ ] **Step 4: Align the appeal queue UI**

Show current/stale/expired/superseded state explicitly. Only submitted, unexpired, current appeals expose decision controls. The server remains authoritative if state changes after render.

- [ ] **Step 5: Verify and commit**

```powershell
php artisan test tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php
pnpm run test:frontend -- resources/js/Pages/superAdmin/Users/__tests__/SuspensionAppeals.test.tsx
php artisan schedule:list
php artisan suspension-appeals:expire --help
git diff --check
git add -- app/Http/Requests/SuperAdmin/DecideSuspensionAppealRequest.php app/Console/Commands/ExpireSuspensionAppeals.php app/Services/SuspensionAppealService.php app/Http/Controllers/SuspensionAppealPublicController.php app/Http/Controllers/superAdmin/SuspensionAppealsController.php app/Services/PrivilegedAudit.php routes/console.php resources/js/Pages/superAdmin/Users/SuspensionAppeals.tsx tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php resources/js/Pages/superAdmin/Users/__tests__/SuspensionAppeals.test.tsx
git commit -m "feat: bind appeals to current suspensions"
```

## Task 9: Add Real Archive/Restore and Correct Lifecycle Controls

**Files:**

- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php`
- Modify: `resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx`
- Create: `resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx`
- Create: `resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx`
- Modify: `tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php`

- [ ] **Step 1: Write RED read-model and UI tests**

Assert archived filtering/counts, explicit archived badges, valid action visibility, reason prompts, recent-reauth redirect handling, disabled submitting state, `409/422/403` response handling, and no optimistic success. Confirm administrator management still has active/suspended/inactive only and no archive/delete control.

- [ ] **Step 2: Expose archived records only in privileged management reads**

Use explicit `withTrashed()` in user/shop management queries with a simple `active|archived|all` filter. Normal application queries remain protected by the `SoftDeletes` global scope. Select/eager-load only fields needed by these pages and keep existing pagination conventions where present.

- [ ] **Step 3: Replace misleading controls**

- shop `Activate` appears only for suspended shops and calls reactivation;
- user `Activate` appears only for suspended users and calls reactivation;
- archive/restore use reason dialogs and Phase 1 recent reauthentication;
- pending/rejected shop rows never receive a general activation control;
- archived suspended records show both lifecycle conditions and restoration does not claim activation;
- no administrator archive/delete action is introduced.

- [ ] **Step 4: Verify and commit**

```powershell
php artisan test tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php
pnpm run test:frontend -- resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx
pnpm run build
git diff --check
git add -- app/Http/Controllers/SuperAdminController.php app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php
git commit -m "feat: add reversible account lifecycle controls"
```

## Task 10: Write the Phase 2 Reconciliation and Rollout Runbook

**Files:**

- Create: `docs/runbooks/super-admin-phase-2-state-workflows.md`
- Modify only for a genuinely durable lesson: `docs/ai-learning-log.md`

- [ ] **Step 1: Document preflight and stop conditions**

Require:

- completed Phase 1 backup/restore and privileged MFA access verified;
- current database backup and tested restore procedure;
- maintenance window for additive migration + reconciliation;
- production DB engine/version captured and migration rehearsed on a copy;
- counts of users/shops by status, live/terminal appeals, duplicate live appeals, suspended accounts with no safely attributable appeal, legacy warning audits, ambiguous employee emails, and candidate archives;
- queue/mail health and failed-job visibility;
- stop on migration failure, duplicate current references, unresolved command failure, ambiguous employees on a requested transition, failing invariant tests, or count drift not explained by the dry run.

- [ ] **Step 2: Document the rollout order**

```text
enter maintenance
-> deploy code and additive migrations
-> run migration/status checks
-> run reconciliation dry run and save output
-> review ambiguous IDs/counts
-> run reconciliation --apply; command generates/prints the operation UUID
-> rerun dry run; expect zero pending changes
-> run focused state/invariant tests against deployment profile
-> verify route/schedule inventory and queue worker
-> verify both privileged roles and next-request denial
-> exit maintenance
```

No irreversible cleanup or legacy hard delete occurs in this rollout.

- [ ] **Step 3: Document forward recovery/rollback limits**

- before application traffic resumes, schema rollback may be used only if no Phase 2 rows were committed and rollback was rehearsed;
- after Phase 2 suspension/action rows exist, prefer a forward corrective migration; never erase suspension, appeal, moderation, or audit evidence to force rollback;
- restoring a database backup also requires restoring matching application code and invalidating affected sessions;
- reconciliation can be rerun safely; ambiguity requires operator review, not SQL guesswork;
- `operator_review_required` legacy suspensions without a safely attributable live appeal remain without a fabricated appeal; the operator reviews each case and uses an existing authorized lifecycle decision rather than inventing tokens, expiry, or history;
- if notifications fail after commit, retry delivery operationally without replaying the state mutation.

- [ ] **Step 4: Verify named commands/routes**

```powershell
php artisan help super-admin:reconcile-phase-two-state
php artisan help suspension-appeals:expire
php artisan schedule:list
php artisan route:list --path=admin --except-vendor
```

- [ ] **Step 5: Commit**

```powershell
git add -- docs/runbooks/super-admin-phase-2-state-workflows.md
# Only when this phase produced a genuinely durable repository lesson:
git add -- docs/ai-learning-log.md
git commit -m "docs: add phase two workflow rollout runbook"
```

## Task 11: Integrated Phase 2 Concurrency, Security, and Regression Verification

**Files:**

- Create: `tests/Feature/SuperAdmin/PhaseTwoConcurrencyTest.php`
- All Phase 2 files above
- No production behavior changes unless verification reveals a Phase 2 defect

- [ ] **Step 1: Run the focused backend Phase 2 suite**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php tests/Feature/SuperAdmin/PhaseTwoSchemaTest.php tests/Feature/SuperAdmin/PhaseTwoStateReconciliationTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/EmployeeSuspensionProvenanceTest.php tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php tests/Feature/Auth/SuspensionSessionEnforcementTest.php tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php
```

Expected: PASS, zero failures.

- [ ] **Step 2: Run targeted real-database concurrency tests**

Use the supported MySQL integration profile; do not claim `lockForUpdate()` concurrency from SQLite. The harness must use separate database connections/processes and deterministic barriers rather than timing-only sleeps.

Cover:

```text
two simultaneous registration approvals
two simultaneous direct suspensions
two simultaneous warning decisions on one exact report set
new report arrival after render but before exact-set decision
flag suspension racing direct suspension
appeal approval racing manual reactivation
archive racing suspension/reactivation
independent employee suspension racing linked-account restoration
```

Exactly one valid terminal result may commit; there must be one token/decision/unique strike number/current suspension/appeal/audit/notification as applicable. A report absent from the submitted exact set remains open, and an employee whose provenance marker was cleared cannot be restored by the old account suspension. If the CI environment cannot run the MySQL profile, record the exact blocker and require it before production rollout.

- [ ] **Step 3: Run Phase 0/1 and adjacent regression suites**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/HardDeleteContainmentTest.php tests/Feature/SuperAdmin/PrivilegedAuthenticationFlowTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/PrivilegedRecentReauthenticationTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php tests/Feature/BusinessScaling/ShopModuleInitialApprovalTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php
```

Confirm the final active MFA-enrolled Super Admin cannot be removed and administrators remain non-archivable.

- [ ] **Step 4: Run frontend tests and production build**

```powershell
pnpm run test:frontend -- resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopReports.test.tsx resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuspensionAppeals.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx
pnpm run build
```

- [ ] **Step 5: Run broader quality gates**

```powershell
composer test
pnpm run test:frontend
composer audit
git diff --check
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
php artisan migrate:status
php artisan schedule:list
```

Record exact pass/fail/skipped counts. Do not weaken Phase 2 assertions for pre-existing unrelated failures; record those separately with evidence.

- [ ] **Step 6: Inspect destructive, state, and query boundaries**

```powershell
rg -n "forceDelete|->delete\(|destroy\(|onDelete\('cascade'\)|cascadeOnDelete" app routes database/migrations -g "*.php"
rg -n "status.*(approved|active|suspended|rejected|banned)|current_suspension_id|suspension_id" app/Http/Controllers app/Services -g "*.php"
rg -n "Employee|employee|employees" app -g "*.php" | rg "status|suspend|activate|inactive|terminated"
rg -n "AuditLog::|activity\(|PrivilegedAudit" app/Http/Controllers/superAdmin app/Http/Controllers/SuperAdminController.php app/Services -g "*.php"
rg -n "Mail::|->notify\(" app/Http/Controllers/superAdmin app/Http/Controllers/SuperAdminController.php app/Services -g "*.php"
rg -n "Route::(get|match|any).*?(suspend|activate|reactivate|archive|restore|expire)" routes -g "*.php"
```

Expected:

- no Phase 2 user/shop/admin force delete or relation deletion;
- canonical mutations route through focused services, not direct controller updates;
- changed privileged workflows do not write legacy `AuditLog` as their authoritative outcome;
- notifications occur after transaction commit and no delivery catch hides a state/audit failure;
- no general activation path can approve pending/rejected shops;
- every independent employee status writer clears `privileged_suspension_id`, while only `AccountSuspensionService` assigns it;
- every report mutation uses the submitted exact ID set and a server-derived decision key; warning numbers have a per-shop database uniqueness defense;
- compatibility GET/HEAD routes are navigation-only and call no mutation service;
- no GET route expires or otherwise mutates appeals.

- [ ] **Step 7: Perform browser verification for both roles**

Use `webapp-testing` against the local application:

```text
Admin: registration approve/reject, user/shop suspend/reactivate/archive/restore, report/flag moderation, appeal queue view
Admin: appeal decision denied; own MFA/security still allowed
Super Admin: valid current appeal approve/reject
pending/rejected shop: no general activation control and direct request denied
archived active account: existing cookie denied next request; restore returns prior status
archived suspended account: restore remains suspended/denied
stale appeal: visible as historical/non-actionable; decision denied
report warning threshold: one visible strike per grouped decision
flagged account: no permanent-ban wording; terminal state cannot reopen
mobile/keyboard/error/focus behavior for every new reason dialog
```

Screenshots are QA evidence only. Verify server responses and database state, not only visible UI.

- [ ] **Step 8: Complete the required sequential review stack**

Record:

1. **simplify/ponytail:** one suspension model, one exact-set report-decision model, one nullable employee provenance reference, concrete services, no workflow engine/new repository/interface-with-one-implementation, and no Phase 6+ subsystem;
2. **standards review:** Form Requests, constructor injection, explicit route middleware, Eloquent relations/casts, additive migrations, canonical row locks, bounded commands, eager loading, and after-commit delivery follow repository conventions;
3. **spec review:** every Phase 2 acceptance criterion maps to a passing test, deployment command, or browser check;
4. **TypeScript clean-code review:** changed TSX has typed props/results, no new `any`, focused state, accessible dialogs, and no duplicated response parsing where an existing helper exists;
5. **code splitting:** N/A unless measured bundle evidence shows a changed management page is genuinely heavy; do not split these controls speculatively;
6. **gauge improvements:** record before/after counts for unsafe source-state mutations, workflows with stable suspension identity, workflows with transactional audit, archive-capable hard-delete alternatives, and duplicate-effects under concurrency;
7. **security review:** verify authorization, recent reauthentication, CSRF, exact report-ID bounds/ownership, soft-delete/auth-provider behavior, stale-session denial, fail-closed employee ambiguity/provenance, server-owned correlation/decision identities, canonical locks, audit redaction, private-document regression, GET non-mutation, no bearer leakage, and no hard-delete/cascade execution;
8. **reuse/dead-code:** reuse Phase 0 audit/private-document and Phase 1 capability/reauth/session helpers; remove only direct mutation/audit code made obsolete by Phase 2;
9. **verification-before-completion:** no pass/completion claim without fresh command output.

The repository does not authorize an unrequested parallel subagent review. Perform Standards, Spec, and risk reviews sequentially unless the user explicitly approves the bounded read-only review gate.

- [ ] **Step 9: Commit verification-only fixes, if any**

Use a narrow message and stage only files changed to resolve verified Phase 2 findings. Do not create an empty commit.

## Phase 2 Completion Evidence

Attach to the handoff:

- commit IDs for Tasks 1-10 and any verification fix;
- exact focused/full backend, frontend, build, audit, route, schedule, migration, and browser results;
- MySQL concurrency evidence for approval, suspension, warning, appeal/reactivation, and archive races;
- migration rehearsal and rollback/forward-recovery evidence for the production DB engine;
- reconciliation dry-run/apply/second-dry-run output, freshly server-generated operation UUIDs, counts, unresolved ambiguous IDs, and operator-review-required no-appeal cases;
- database evidence that every suspended account has the intended current pointer and linked appeals cannot cross suspension identity;
- evidence that one exact-set report decision excludes later arrivals, creates one uniquely numbered warning strike, and creates one suspension/appeal at threshold;
- evidence that archive preserves representative relations/status and removes authority on the next request;
- evidence that a linked-employee failure or missing/mismatched provenance rolls back the whole transition and that independent employee status writes clear provenance;
- route evidence that archive/restore require capability + recent reauthentication and appeal decisions remain Super-Admin-only;
- audit evidence that each committed changed workflow has one authoritative allowlisted event without private paths/tokens;
- confirmation that notification failure cannot rewrite committed state and duplicate retries do not fan out again;
- route evidence that GET/HEAD compatibility is navigation-only and cannot mutate lifecycle/expiry state;
- confirmation that administrator archival, document versioning/expiry/DTI-SEC split, billing, and generic workflow infrastructure were not introduced;
- any skipped deployment/browser/concurrency check with exact blocker and owner.

Do not call Phase 2 complete based on UI behavior or happy paths. Completion requires invalid-source, retry, rollback, stale-identity, archive/session, reconciliation, and real-database concurrency evidence.
