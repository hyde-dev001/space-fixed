# Super Admin Phase 3 Transactions, Audit, and Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task in the existing `super-admin-phase-0-containment` worktree. Apply `superpowers:test-driven-development` before implementation changes, `laravel-best-practices` and `security-review` for backend work, `ponytail` for the minimum coherent solution, and `verification-before-completion` before every completion claim. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the privileged transaction boundary so every committed local Super Admin mutation has one atomic authoritative audit record, privileged failures return sanitized correlation-aware responses, reliable legacy privileged history is imported without guessing, and operational mail delivery is durable, deduplicated, retryable, and unable to roll back an already committed decision.

**Architecture:** Preserve the Phase 0-2 guard, capability, MFA, private-document, suspension, and workflow foundations. Continue using focused workflow services and `PrivilegedAudit`; do not add a generic event framework or outbox table. Route the finite set of Super Admin workflow emails through one server-owned, encrypted, unique queued job. A small shared dispatcher registers enqueueing in `DB::afterCommit()`, catches enqueue infrastructure failure so it cannot change the committed HTTP outcome, and records only sanitized diagnostics. Use existing queue retries and `failed_jobs` storage for execution recovery/visibility. Add provenance columns to Spatie's `activity_log` for idempotent legacy imports, a deterministic legacy mapper/command, and one scoped paginated `/admin/audit` read path. Premium-plan mutations and business-upgrade review are the remaining in-scope local workflows; subscription cancellation, plan switching, refunds, and provider operations remain untouched until Phase 5.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, Spatie Laravel Activitylog 4, database queues, Inertia 2, React 18, TypeScript 5.7, PHPUnit 11, Vitest 3, pnpm.

**Status:** DRAFT FOR APPROVAL

---

## Design Authority and Scope Guard

Authoritative design:

- `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`
- Phase 3, "Transactions, Audit, and Delivery"
- Sections 8, 10-17, and 19-24 where they define fixed capabilities, transaction/audit atomicity, HTTP semantics, operational notifications, invariants, and verification

Implemented prerequisites:

- Completed Phase 2 tip: `1d44cfebfa`
- Worktree/branch: `.worktrees/super-admin-phase-0-containment` / `super-admin-phase-0-containment`

This plan is based on the clean post-Phase-2 worktree. Do not execute it against a branch lacking Phase 0 private-document/audit containment, Phase 1 active/MFA/capability enforcement, or Phase 2 state-machine and suspension-identity invariants.

Phase 3 includes:

1. a server-owned correlation ID for every privileged request and sanitized failure responses;
2. completion of local transaction/audit ownership for premium-plan management and business-upgrade review;
3. regression failure injection for Phase 1-2 transactional workflows;
4. removal/cutover of remaining Super Admin runtime writes to legacy `audit_logs` and unnamed activity logs;
5. idempotent, allowlisted import of reliable historical privileged rows into `activity_log`;
6. a real, scoped, paginated privileged audit-history route and page;
7. one bounded encrypted/unique privileged mail job, deterministic deduplication, retries, and failed-job visibility;
8. authoritative activity in monitoring and an operator runbook for audit import and queue recovery.

Do not add in this phase:

- subscription cancellation, upgrade, downgrade, pseudo-refund repair, provider calls, payment reconciliation, or financial corrections; Phase 5 owns them;
- irreversible deletion, administrator archival, or changes to the approved two-role capability model;
- document expiration, renewal, immutable document versions, DTI/SEC classification, or reminder scheduling; Phase 6 owns them;
- a general event bus, notification platform, communications center, export engine, report generator, support-ticket system, repository layer, command bus, or configurable audit policy;
- broad `/superAdmin` route consolidation or decomposition of the monolithic controller beyond methods directly displaced by this phase; Phase 7 owns structural simplification;
- importing ERP, HR, shop-owner, finance, order, or permission audit history into the privileged ledger;
- a delivery/outbox table or exactly-once claims for email-provider acceptance. The application prevents duplicate dispatch for the same committed business event while the unique-job lock exists; provider acknowledgement remains at-least-once infrastructure behavior.

## Confirmed Post-Phase-2 Baseline

- `PrivilegedAudit` already writes normalized `activity_log` rows under `log_name = privileged` and reuses one server-generated UUID per audited request.
- Phase 1 administrator identity workflows and Phase 2 registration/account/report/flag/appeal workflows already place mandatory success audit writes inside their local transactions.
- Phase 2 services use canonical aggregate locks and avoid duplicate terminal side effects, but several mail paths still call `Mail::send()` synchronously after commit and swallow failures into logs.
- Registration approval/rejection and business-upgrade notifications implement `ShouldQueue`; invitation and password-reset mailables additionally implement `ShouldBeEncrypted`.
- Database queue and `failed_jobs` storage exist; queue connections default to `database`, while connection-level `after_commit` remains false. Every Phase 3 dispatch must therefore opt into per-job after-commit behavior explicitly.
- Premium-plan create/update/archive/reactivate still mutate directly in `SuperAdminController`; only update is transactional and none writes mandatory privileged audit.
- Business-upgrade review is transactional but writes raw unnamed `activity()` entries instead of using `PrivilegedAudit`.
- Subscription cancellation/upgrade/downgrade also write raw activity and expose exception details, but their behavior is intentionally frozen for Phase 5. Phase 3 may sanitize their failure responses but must not certify or redesign their financial state transitions.
- One unreachable legacy registration handler still writes `AuditLog::create()`; current routes use `ShopOwnerRegistrationViewController` and `ShopOwnerRegistrationDecisionService`.
- Monitoring prefers legacy `audit_logs` whenever that table exists, so it ignores the authoritative privileged ledger.
- `/admin/data-reports` and legacy `/superAdmin/data-report-access` render a simulated export/report page. The approved canonical audit path is `/admin/audit`.
- `activity_log` has no durable legacy provenance columns and only a basic `log_name` index.

## Frozen Phase 3 Contracts

### Atomic local transition

```text
lock aggregate root(s) in the approved order
        -> revalidate source/current state
        -> mutate local business rows
        -> write exactly one mandatory success audit outcome
        -> register unique queued delivery job(s) with the shared after-commit dispatcher
        -> commit (dispatcher enqueues eligible jobs)
```

If any local mutation or mandatory audit write fails, the entire local transaction rolls back and Laravel does not dispatch the after-commit jobs. A queued or completed job never changes the authoritative business decision or audit outcome.

### Failure semantics

- Never write a success audit before the transaction commits.
- An exception after any local mutation rolls back the mutation and success audit together; registered after-commit jobs are discarded.
- Security-relevant denials, stale/conflicting privileged decisions, and unexpected privileged failures may write one sanitized failure audit only after rollback.
- Routine validation errors do not create audit noise.
- Failure-audit failure never replaces the original safe response; report it internally and retain the same correlation ID.
- JSON and Inertia responses expose a generic message and server-owned correlation ID where support correlation is useful. They never expose exception messages, SQL, storage paths, queue payloads, credentials, tokens, or provider payloads.

HTTP meanings remain:

```text
401 unauthenticated
403 authenticated but capability/object scope denied
404 concealed or absent resource
409 stale source state or conflicting terminal retry
422 malformed or invalid input
429 throttled
500 unexpected internal failure
```

### Audit event contract

Every runtime `activity_log` row under `log_name = privileged` contains:

- normalized `event` and matching `description`;
- actor type, guard, ID, and role when an authenticated privileged actor exists;
- subject/target type and ID when a reliable target exists;
- prior/result state or outcome where applicable;
- allowlisted reason/decision metadata only;
- source (`http`, `console`, or `legacy_import`);
- server-generated correlation ID;
- IP address for HTTP activity;
- original historical timestamp for imported rows.

Never include passwords, MFA secrets/recovery codes, bearer/setup/reset tokens, private document contents or paths, sensitive filenames, full email/provider payloads, or raw request bodies.

### Legacy import boundary

Only the following historical `audit_logs` action families from confirmed old Super Admin writers are eligible for mapping:

| Legacy action | Normalized event | Minimum reliable evidence |
|---|---|---|
| `user_suspended` | `user_suspended` | target type/user ID and matching Super Admin actor ID |
| `user_activated` | `user_reactivated` | target type/user ID and matching Super Admin actor ID |
| `shop_activated` | `shop_reactivated` | target type/shop ID and matching Super Admin actor ID |
| `shop_owner_approved` | `shop_registration_approved` | target type/shop ID and matching Super Admin actor ID |
| `shop_report_dismiss`, `shop_report_warn`, `shop_report_suspend` | `shop_reports_moderated` | matching shop, reliable `data.admin_id`, and recognized outcome |
| `suspension_appeal_approved`, `suspension_appeal_rejected` | `suspension_appeal_decided` | reliable appeal/account target and matching Super Admin actor ID |

Rows outside this allowlist, rows with missing/deleted targets, unknown actor identity, conflicting target fields, malformed JSON, or ambiguous Shop Owner/User ID collisions are reported and skipped. Do not infer actor role from a numeric collision alone when the old writer did not prove the guard. Phase 2 reconciliation events already represented in `activity_log` are not imported a second time.

Imported rows retain their original `created_at`, add `legacy_source = audit_logs` and `legacy_id`, and contain the same provenance in allowlisted properties. Historical role is not recoverable from these rows: record `actor_role = legacy_unknown` plus `actor_role_verified = false` rather than copying the administrator's current role. A unique `(legacy_source, legacy_id)` constraint makes reruns idempotent. The source `audit_logs` row is retained.

### Audit visibility

- Super Admin sees all `log_name = privileged` rows.
- Admin sees rows they caused plus events related to operational cases covered by their fixed capabilities: registration/document review, user/shop lifecycle, report/flag moderation, and appeal queue history.
- Admin does not gain unrestricted administrator-management, platform-security, plan, subscription, credential-recovery, or bootstrap history merely because they can open `/admin/audit`.
- Filters are allowlisted and server applied. Default order is `created_at DESC, id DESC`; page size defaults to 25 and is capped at 100.
- The page displays safe event labels and allowlisted metadata. It never dumps raw `properties` JSON.

### Delivery contract

`SendPrivilegedWorkflowMail` is a focused job, not a second notification platform. It supports only an allowlisted set of Super Admin workflow mail types:

```text
privileged_admin_setup
privileged_password_reset
shop_registration_approved
shop_registration_rejected
shop_suspension_notice
customer_suspension_notice
shop_report_warning
suspension_appeal_submitted
suspension_appeal_decided
shop_owner_upgrade_requested
shop_owner_upgrade_reviewed
```

The job implements `ShouldQueue`, `ShouldBeEncrypted`, and `ShouldBeUnique`. Its server-derived `uniqueId()` combines delivery type, stable business-event identity, recipient model identity, and channel. It has an explicit bounded timeout, retry count, exponential backoff, and `failed()` logging that contains only delivery type, business identity, recipient type/ID, and correlation ID.

- Register dispatch from inside the owning local transaction through `PrivilegedMailDispatcher`, which uses `DB::afterCommit()`; rolled-back transactions enqueue nothing.
- Queue-enqueue failure is caught after commit, reported with sanitized context/correlation, and never converts a committed decision into an HTTP failure. Because no outbox is introduced, the runbook treats this rare pre-queue failure as a manual audited resend/reconciliation case.
- The encrypted job payload contains only the minimum allowlisted IDs/content needed to build the existing mailable/notification. Tokens, rejection reasons, and case notes never appear in logs, audit metadata beyond approved reason fields, failed-job summaries, or client responses.
- The unique lock prevents concurrent/retry dispatch of the same event-recipient-channel job. Existing workflow `changed = false` semantics prevent redispatch after a committed identical HTTP retry.
- Verify that the production cache driver supports atomic unique-job locks. If it does not, deployment is blocked until a supported shared cache is configured; do not silently claim deduplication.
- Customer/shop notices may intentionally target pending, rejected, or suspended accounts. Privileged-recipient fan-out includes only currently active, MFA-enrolled administrators with the relevant capability and rechecks eligibility when the job executes.
- Delivery failures use Laravel retries and then `failed_jobs`; operators use `queue:failed` and `queue:retry`. The original business decision and success audit remain committed.
- Registration/setup/reset token delivery recovery uses the existing audited resend/rotation workflow when a failed job cannot safely be retried; no token is reconstructed from its hash.

## Canonical Lock Additions

Phase 2 lock orders remain unchanged. Phase 3 adds only:

```text
Premium-plan update/archive/reactivate
premium plan -> affected subscriptions by ID ascending when entitlement propagation is required

Business-upgrade review
shop owner -> upgrade request -> request documents by ID ascending -> affected module rows by key/ID

Delivery processing
no application database lock during external mail; Laravel unique-job cache lock only
```

Bulk entitlement propagation must remain bounded and transactionally correct. If current data volume makes row-by-row subscription locks disproportionate, lock the plan, issue one deterministic scoped update, and document why the plan root serializes competing plan mutations; do not load an unbounded model collection merely to lock it.

---

## Task 1: Freeze the Phase 3 Runtime Inventory and Correlation Contract

**Files:**

- Create: `tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php`
- Create: `app/Http/Middleware/AttachPrivilegedCorrelationId.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Test: `tests/Feature/SuperAdmin/PrivilegedAuditTest.php`

- [ ] **Step 1: Write failing structural tests**

Assert that:

- every `/admin` privileged response receives a server-generated UUID `X-Correlation-ID`;
- an inbound `X-Correlation-ID` cannot control the server value;
- all audit rows written during one request reuse that response correlation ID;
- canonical Phase 3 mutation routes retain auth, active-account, MFA, and fixed-capability middleware;
- no route points to the legacy `approveShopOwner`/`rejectShopOwner` handlers;
- subscription mutation routes and methods are explicitly marked out of Phase 3 transaction-certification coverage.

Run:

```bash
php artisan test tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
```

Expected: FAIL because the response-wide correlation middleware and boundary assertions do not yet exist.

- [ ] **Step 2: Add the correlation middleware**

Generate one UUID at the beginning of each privileged request, store it in `privileged_audit_correlation_id`, append it to the response header, and ignore client-supplied IDs. Register it before privileged authentication/capability middleware for login/setup/MFA and operational `/admin` routes so denied and failed requests can share the same ID.

- [ ] **Step 3: Expose one audit correlation accessor**

Let `PrivilegedAudit` consume the middleware attribute and provide a narrow public accessor/helper for safe error handling. Do not introduce a global request-context singleton.

- [ ] **Step 4: Run focused tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
git add app/Http/Middleware/AttachPrivilegedCorrelationId.php app/Services/PrivilegedAudit.php bootstrap/app.php routes/web.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
git commit -m "feat: establish privileged request correlation"
```

## Task 2: Implement Encrypted, Unique After-Commit Delivery

**Files:**

- Create: `app/Jobs/SendPrivilegedWorkflowMail.php`
- Create: `app/Enums/PrivilegedDeliveryType.php`
- Create: `app/Exceptions/PrivilegedDeliveryException.php`
- Create: `app/Services/PrivilegedMailDispatcher.php`
- Modify: `app/Mail/SuspensionNoticeMail.php`
- Modify: `app/Mail/SuspensionAppealSubmittedMail.php`
- Modify: `app/Mail/SuspensionAppealDecisionMail.php`
- Modify: `app/Mail/ShopReportWarningMail.php`
- Modify: `app/Notifications/ShopOwnerApproved.php`
- Modify: `app/Notifications/ShopOwnerRejected.php`
- Modify: `app/Notifications/ShopOwnerUpgradeRequested.php`
- Modify: `app/Notifications/ShopOwnerUpgradeReviewed.php`
- Test: `tests/Feature/SuperAdmin/PrivilegedDeliveryJobTest.php`

- [ ] **Step 1: Write failing queue and deduplication tests**

Cover:

- job dispatch is deferred until transaction commit and absent after rollback;
- queue-enqueue failure is sanitized/reported but does not throw through the already committed business response;
- job implements `ShouldQueue`, `ShouldBeEncrypted`, and `ShouldBeUnique`;
- `uniqueId()` is stable for the same type/business event/recipient/channel and differs for a distinct recipient or event;
- timeout is lower than the configured queue `retry_after`, with explicit tries and exponential backoff;
- success sends the correct existing mailable/notification without an application DB lock;
- failure preserves the business decision, retries, and ultimately appears in `failed_jobs`;
- transport failures are reported internally with redacted context, then rethrown as a sanitized `PrivilegedDeliveryException` so `failed_jobs.exception` does not capture token/payload content;
- `failed()` logs only safe IDs/type/correlation and never token, reason text, raw transport exception message, or payload;
- simultaneous or repeated dispatch of one unique ID does not create concurrent jobs while the unique lock exists;
- privileged recipients are rechecked for active status, completed MFA, and required capability; ineligible jobs exit without mail;
- suspension/registration recipients are not incorrectly skipped merely because the workflow intentionally leaves them pending, rejected, or suspended.

Use `Mail::fake()` and `Queue::fake()` for dispatch contracts, plus one focused synchronous job invocation. Do not claim SMTP exactly-once behavior.

- [ ] **Step 2: Implement the finite job**

Use one exhaustive `match` over `PrivilegedDeliveryType` inside the job or one private builder method per type; do not add a separate factory unless the job becomes unreadable during implementation. Reuse the existing mailables/notifications and deliver them with Laravel's `sendNow()` APIs inside the already queued outer job so a `ShouldQueue` mailable/notification cannot create an untracked nested job. Keep payload fields allowlisted and never log them. The dispatcher has one responsibility: register a post-commit enqueue and contain/sanitize enqueue infrastructure errors.

Set explicit retries/backoff and a sanitized `failed()` handler. Catch transport errors, report them with safe context, and rethrow the bounded delivery exception. Confirm the selected cache driver supports Laravel atomic locks before relying on `ShouldBeUnique` in production.

- [ ] **Step 3: Preserve framework-native recovery**

Verify the existing commands and storage rather than adding wrappers:

```bash
php artisan queue:failed
php artisan queue:retry <failed-job-uuid>
```

Document worker timeout/retry alignment and cache-lock requirements in the runbook. Do not add Horizon, a scheduler, a delivery table, or a custom retry command.

- [ ] **Step 4: Run focused tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/PrivilegedDeliveryJobTest.php
git add app/Jobs/SendPrivilegedWorkflowMail.php app/Enums/PrivilegedDeliveryType.php app/Exceptions/PrivilegedDeliveryException.php app/Services/PrivilegedMailDispatcher.php app/Mail/SuspensionNoticeMail.php app/Mail/SuspensionAppealSubmittedMail.php app/Mail/SuspensionAppealDecisionMail.php app/Mail/ShopReportWarningMail.php app/Notifications/ShopOwnerApproved.php app/Notifications/ShopOwnerRejected.php app/Notifications/ShopOwnerUpgradeRequested.php app/Notifications/ShopOwnerUpgradeReviewed.php tests/Feature/SuperAdmin/PrivilegedDeliveryJobTest.php
git commit -m "feat: queue privileged delivery after commit"
```

## Task 3: Migrate Phase 1-2 Workflow Delivery to Unique Jobs

**Files:**

- Modify: `app/Services/ShopOwnerRegistrationDecisionService.php`
- Modify: `app/Services/AccountLifecycleService.php`
- Modify: `app/Services/ShopReportModerationService.php`
- Modify: `app/Services/FlaggedAccountModerationService.php`
- Modify: `app/Services/SuspensionAppealService.php`
- Modify: `app/Services/AdministratorIdentityService.php`
- Modify: `app/Actions/ShopOwner/SubmitShopOwnerUpgradeRequest.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `app/Http/Controllers/PrivilegedPasswordResetController.php`
- Test: `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- Test: `tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php`
- Test: `tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php`
- Test: `tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php`
- Test: `tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php`
- Test: `tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php`
- Test: `tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php`
- Test: `tests/Feature/BusinessScaling/BusinessScalingNotificationTest.php`

- [ ] **Step 1: Add failing dispatch/rollback/retry tests to each existing workflow suite**

For every mail-producing transition, prove:

- successful changed outcome commits one audit and registers one unique job per intended recipient;
- identical retry creates no new job fan-out;
- conflicting retry returns `409` and creates no job;
- injected audit failure rolls back the business mutation and dispatches no job;
- queue/mail failure after commit leaves business and success audit committed;
- appeal-submission fan-out snapshots only active, MFA-enrolled recipients with `view_appeals`;
- business-upgrade submission fan-out includes only active, MFA-enrolled recipients with `review_registrations`;
- exact suspension/report/appeal business identity participates in the unique job ID.

- [ ] **Step 2: Register unique jobs inside existing transactions**

Do not wrap services in nested competing transaction owners. Register the finite encrypted job through `PrivilegedMailDispatcher` immediately after the mandatory audit for changed outcomes only. Laravel must discard the callback on rollback. Replace direct synchronous mail and broad try/catch swallowing.

For invitation/password-reset token flows, pass secrets only into encrypted delivery payloads, never into audit metadata, controller responses, logs, or unencrypted jobs. Preserve existing token rotation and audited resend semantics.

- [ ] **Step 3: Remove misleading `notification_failed` request semantics**

The HTTP decision succeeds when local state/audit commit. Return a neutral queued-delivery status only where the UI genuinely needs it; do not report external delivery as complete merely because a job was queued.

- [ ] **Step 4: Run all affected workflow suites and commit**

```bash
php artisan test tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php tests/Feature/BusinessScaling/BusinessScalingNotificationTest.php
git add app/Services/ShopOwnerRegistrationDecisionService.php app/Services/AccountLifecycleService.php app/Services/ShopReportModerationService.php app/Services/FlaggedAccountModerationService.php app/Services/SuspensionAppealService.php app/Services/AdministratorIdentityService.php app/Actions/ShopOwner/SubmitShopOwnerUpgradeRequest.php app/Http/Controllers/SuperAdminController.php app/Http/Controllers/PrivilegedPasswordResetController.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php tests/Feature/BusinessScaling/BusinessScalingNotificationTest.php
git commit -m "refactor: make privileged workflow delivery durable"
```

## Task 4: Complete Premium-Plan and Business-Upgrade Transaction Ownership

**Files:**

- Create: `app/Services/PremiumPlanManagementService.php`
- Create: `app/Http/Requests/Privileged/StorePremiumPlanRequest.php`
- Create: `app/Http/Requests/Privileged/UpdatePremiumPlanRequest.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `app/Actions/superAdmin/ReviewShopOwnerUpgradeRequest.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `app/Enums/PrivilegedDeliveryType.php`
- Test: `tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php`
- Test: `tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php`
- Test: `tests/Feature/BusinessScaling/ShopOwnerUpgradeConcurrencyTest.php`

- [ ] **Step 1: Write failing premium-plan transaction tests**

Cover create/update/archive/reactivate source states, fixed capability enforcement, plan locking, affected entitlement propagation, identical retry/no-op behavior, conflicting state as `409`, audit atomicity, and rollback after injected mutation/audit failure. Plan create/update/archive/reactivate produce normalized events:

```text
premium_plan_created
premium_plan_updated
premium_plan_archived
premium_plan_reactivated
```

Do not include subscription cancellation or plan-change endpoints in these tests.

- [ ] **Step 2: Move premium-plan invariants into one focused service**

Keep validation in Form Requests and response orchestration in the controller. Lock existing plans before update/state change. Include only changed allowlisted fields in audit metadata; never dump the request. Keep the existing bounded bulk showroom entitlement update inside the local transaction and verify it rolls back with audit failure.

- [ ] **Step 3: Replace raw business-upgrade activity writes**

Add `shop_owner_upgrade_reviewed`/`shop_owner_upgrade_superseded` methods to `PrivilegedAudit`, preserve the existing owner-first lock order, and register one unique `shop_owner_upgrade_reviewed` job with `afterCommit()` for changed terminal outcomes. Remove raw `activity()` calls and direct callback notification logic.

- [ ] **Step 4: Add failure-injection and concurrency assertions**

Prove business-upgrade mutation, module provisioning, and audit roll back together and enqueue no job. Concurrent reviews produce one terminal outcome, one normalized audit, and one unique queued job.

- [ ] **Step 5: Run focused tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeConcurrencyTest.php
git add app/Services/PremiumPlanManagementService.php app/Http/Requests/Privileged/StorePremiumPlanRequest.php app/Http/Requests/Privileged/UpdatePremiumPlanRequest.php app/Http/Controllers/SuperAdminController.php app/Actions/superAdmin/ReviewShopOwnerUpgradeRequest.php app/Services/PrivilegedAudit.php app/Enums/PrivilegedDeliveryType.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeConcurrencyTest.php
git commit -m "feat: standardize remaining local privileged workflows"
```

## Task 5: Standardize Sanitized Privileged Failure Audits

**Files:**

- Create: `app/Support/PrivilegedFailureResponse.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `app/Http/Middleware/EnsurePrivilegedCapability.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `app/Http/Controllers/superAdmin/ShopOwnerUpgradeRequestController.php`
- Modify: `app/Http/Controllers/superAdmin/ShopReportsController.php`
- Modify: `app/Http/Controllers/superAdmin/FlaggedAccountsController.php`
- Modify: `app/Http/Controllers/superAdmin/SuspensionAppealsController.php`
- Test: `tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php`

- [ ] **Step 1: Write failing negative-path tests**

Assert:

- capability denial can produce one `privileged_capability_denied` event with capability and route name only;
- stale/conflicting decisions can produce one sanitized `privileged_workflow_conflict` after rollback;
- unexpected failures can produce one `privileged_workflow_failed` after rollback and return a generic response with correlation ID;
- `422` validation errors do not create failure-audit noise;
- failure records never contain exception text, SQL, token, email, private path, raw input, or stack data;
- failure-audit write failure leaves the original `403`, `409`, or `500` response intact;
- subscription endpoint exception messages are no longer returned to clients, while their Phase 5 business behavior remains unchanged.

- [ ] **Step 2: Add one narrow failure-response helper**

Centralize correlation-aware JSON/Inertia formatting and sanitized audit invocation. Do not create a new exception hierarchy or replace Laravel validation/auth handling.

- [ ] **Step 3: Apply it only to privileged boundaries**

Use stable operation codes supplied by each controller/middleware. Log detailed exceptions through Laravel reporting only; client and audit output remain allowlisted.

- [ ] **Step 4: Run focused tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php
git add app/Support/PrivilegedFailureResponse.php app/Services/PrivilegedAudit.php app/Http/Middleware/EnsurePrivilegedCapability.php app/Http/Controllers/SuperAdminController.php app/Http/Controllers/superAdmin/ShopOwnerUpgradeRequestController.php app/Http/Controllers/superAdmin/ShopReportsController.php app/Http/Controllers/superAdmin/FlaggedAccountsController.php app/Http/Controllers/superAdmin/SuspensionAppealsController.php tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php
git commit -m "feat: sanitize privileged workflow failures"
```

## Task 6: Add Idempotent Legacy Privileged Audit Import

**Files:**

- Create: `database/migrations/2026_08_12_XXXXXX_add_legacy_provenance_to_activity_log_table.php`
- Create: `app/Services/LegacyPrivilegedAuditMapper.php`
- Create: `app/Console/Commands/ImportLegacyPrivilegedAudit.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Test: `tests/Feature/SuperAdmin/LegacyPrivilegedAuditImportTest.php`
- Test: `tests/Feature/SuperAdmin/PrivilegedLegacyWriterCutoverTest.php`

- [ ] **Step 1: Write failing schema/import tests**

Cover all allowlisted action mappings and every skip condition in the frozen contract. Prove original timestamps, sanitized metadata, actor/subject linkage, `source = legacy_import`, UUID correlation, provenance properties/columns, source-row retention, dry-run default, bounded chunks, and idempotent reruns.

Include collision fixtures where a User and SuperAdmin share an integer ID. Import only when the known legacy writer and row shape make the privileged guard identity reliable; otherwise report ambiguity.

- [ ] **Step 2: Add provenance and query indexes**

Add nullable `legacy_source` and `legacy_id`, a unique composite key, and practical `(log_name, created_at, id)` / `(log_name, event, created_at)` indexes. Keep the migration additive and reversible; do not rewrite existing activity rows in `up()`.

- [ ] **Step 3: Implement pure mapping plus an operator command**

The mapper returns either a normalized allowlisted record or an explicit skip reason. The command defaults to dry-run and supports bounded `--chunk`/`--limit` plus `--apply`. It prints deterministic imported/already-imported/skipped counts and grouped skip reasons without outputting sensitive metadata.

Example:

```bash
php artisan privileged-audit:import-legacy --limit=500
php artisan privileged-audit:import-legacy --apply --chunk=200
```

- [ ] **Step 4: Remove the last Super Admin legacy writer**

Delete the unreachable `approveShopOwner`/`rejectShopOwner` methods and `AuditLog` import from `SuperAdminController` after route-contract tests prove no caller remains. Do not modify non-privileged ERP/HR/shop audit writers.

- [ ] **Step 5: Run focused tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/LegacyPrivilegedAuditImportTest.php tests/Feature/SuperAdmin/PrivilegedLegacyWriterCutoverTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
git add database/migrations/2026_08_12_*_add_legacy_provenance_to_activity_log_table.php app/Services/LegacyPrivilegedAuditMapper.php app/Console/Commands/ImportLegacyPrivilegedAudit.php app/Services/PrivilegedAudit.php app/Http/Controllers/SuperAdminController.php tests/Feature/SuperAdmin/LegacyPrivilegedAuditImportTest.php tests/Feature/SuperAdmin/PrivilegedLegacyWriterCutoverTest.php
git commit -m "feat: import reliable privileged audit history"
```

## Task 7: Replace Simulated Reports with Scoped Privileged Audit History

**Files:**

- Create: `app/Http/Controllers/superAdmin/PrivilegedAuditController.php`
- Create: `app/Http/Requests/Privileged/PrivilegedAuditIndexRequest.php`
- Create: `app/Services/PrivilegedAuditVisibility.php`
- Create: `resources/js/Pages/superAdmin/Audit/PrivilegedAuditHistory.tsx`
- Create: `resources/js/Pages/superAdmin/Audit/__tests__/PrivilegedAuditHistory.test.tsx`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/superAdmin/SystemMonitoringDashboardController.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Delete: `app/Http/Controllers/superAdmin/DataReportAccessController.php`
- Delete: `resources/js/Pages/superAdmin/Reports/DataReportAccess.tsx`
- Test: `tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php`
- Test: `tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php`

- [ ] **Step 1: Write failing authorization/scope/query tests**

Prove:

- `/admin/audit` requires active privileged auth, MFA, and `view_privileged_audit`;
- Super Admin sees full privileged history;
- Admin sees own actions and approved operational event families, but not other administrators' identity/security, plan, subscription, bootstrap, or unrestricted audit events;
- filters for event, actor, target type/ID, correlation ID, and date range are validated and scoped before pagination;
- sort is deterministic and page size is capped at 100;
- serialized rows expose only explicit safe fields/metadata;
- `/admin/data-reports` and safe legacy GET aliases redirect to `/admin/audit` without rendering fake exports;
- no mutation/export/download endpoint is introduced.

- [ ] **Step 2: Implement fixed visibility and paginated query**

Keep the event-family-to-capability mapping deterministic in code. Query Spatie `Activity` with `log_name = privileged`; never merge HR, permission, or generic shop-owner audits into this page.

- [ ] **Step 3: Build the focused audit page**

Reuse existing Super Admin layout, table, filter, pagination, badge, loading, empty, and error patterns. Display event, safe actor label/role, target label/ID, outcome/state summary, source, IP when allowed, correlation ID, and timestamp. Do not render raw JSON or add export controls.

- [ ] **Step 4: Cut monitoring over to authoritative activity**

Remove `AuditLog` fallback/preference. Recent privileged activity comes only from `activity_log` with safe normalized labels. Keep failed-job count and existing live health checks.

- [ ] **Step 5: Run backend/frontend tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Audit/__tests__/PrivilegedAuditHistory.test.tsx
git add app/Http/Controllers/superAdmin/PrivilegedAuditController.php app/Http/Requests/Privileged/PrivilegedAuditIndexRequest.php app/Services/PrivilegedAuditVisibility.php resources/js/Pages/superAdmin/Audit/PrivilegedAuditHistory.tsx resources/js/Pages/superAdmin/Audit/__tests__/PrivilegedAuditHistory.test.tsx routes/web.php app/Http/Controllers/superAdmin/SystemMonitoringDashboardController.php app/Http/Controllers/SuperAdminController.php app/Http/Controllers/superAdmin/DataReportAccessController.php resources/js/Pages/superAdmin/Reports/DataReportAccess.tsx tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php
git commit -m "feat: expose scoped privileged audit history"
```

## Task 8: Add Deployment Runbook and End-to-End Failure Evidence

**Files:**

- Create: `docs/runbooks/super-admin-phase-3-audit-delivery.md`
- Create: `tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php`
- Create: `docs/ai-learning-log.md` only if a genuinely reusable lesson is discovered and the file is still absent

- [ ] **Step 1: Add cross-workflow failure-injection tests**

Use existing service seams or narrow test-only fakes to fail after each meaningful local mutation and at the mandatory audit boundary for representative high-risk workflows:

```text
administrator invitation
registration approval
account suspension
shop-report warning/suspension
appeal decision
premium-plan update
business-upgrade approval
```

For each representative failure, assert no partial business state, no success audit, no queued job, and a sanitized correlation-aware response. Separately fail queued delivery and assert committed business state/audit remain unchanged.

- [ ] **Step 2: Document deployment and recovery order**

The runbook must include:

1. maintenance/readiness checks and database backup expectation;
2. additive migrations first;
3. dry-run legacy audit import and interpretation of every skip class;
4. apply import and rerun idempotency check;
5. queue worker restart/reload after deploy;
6. verified production queue connection, retry/backoff, and `failed_jobs` access;
7. single-scheduler confirmation unless shared atomic locking is proven;
8. `queue:failed` inspection and targeted `queue:retry` recovery;
9. safe failed-job retry procedure and how to inspect a delivery by correlation/business identity without exposing payload;
10. rollback rule: application rollback is allowed while additive tables/columns and source `audit_logs` remain; never delete imported/source history during rollback;
11. known at-least-once provider acknowledgement limitation and operator reconciliation procedure;
12. explicit note that Phase 5 still owns subscription money movement.

- [ ] **Step 3: Run focused tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php
git add docs/runbooks/super-admin-phase-3-audit-delivery.md tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php docs/ai-learning-log.md
git commit -m "docs: add phase 3 audit and delivery runbook"
```

Do not stage `docs/ai-learning-log.md` if no durable lesson was added.

## Task 9: Required Sequential Reviews and Final Verification

**Files:**

- Review all Phase 3 changes since `1d44cfebfa`
- Modify only files required to resolve verified findings

- [ ] **Step 1: Run the required review stack sequentially**

Record one result for each:

1. **simplify / ponytail:** remove speculative delivery abstractions, duplicate event maps, unnecessary DTOs, and general-purpose APIs. Prefer the one native encrypted/unique job and existing `failed_jobs`; do not add a delivery ledger without measured evidence that native queue recovery is insufficient.
2. **Standards review:** Laravel conventions, Form Requests, short transactions, canonical locks, queue safety, Eloquent scopes, Inertia/React conventions.
3. **Spec review:** compare every Phase 3 acceptance criterion and frozen contract in this plan and the design authority.
4. **clean-code-typescript:** verify explicit page props, safe narrowing, focused components, readable filters, and no unnecessary `any`/assertions.
5. **karpathy-guidelines:** surface assumptions, keep the diff surgical, and remove only orphans created by the cutover.
6. **code-splitting:** N/A unless the audit page adds a measured heavy dependency; do not split a small page speculatively.
7. **gauge-improvements:** record before/after evidence for legacy-writer count, normalized audit coverage, delivery retry visibility, bounded audit page query, and fake audit/report controls removed. If latency/bundle size was not measured, state `not measured`.
8. **security-review:** inspect token/payload encryption, logs, audit metadata, actor/object scope, IDOR, failure responses, queue serialization, and recipient eligibility.
9. **reuse/dead-code review:** confirm existing mailables, layouts, pagination, capability map, audit helper, queue tables, and monitoring patterns are reused; scan for stale imports, raw activity writers, fake report routes, and direct synchronous privileged mail.

- [ ] **Step 2: Run narrow suites**

```bash
php artisan test tests/Feature/SuperAdmin
php artisan test tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeConcurrencyTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Audit/__tests__/PrivilegedAuditHistory.test.tsx
```

- [ ] **Step 3: Run structural inspections**

```bash
php artisan route:list --path=admin
php artisan schedule:list
php artisan privileged-audit:import-legacy --limit=100
php artisan queue:failed
rg -n "AuditLog::create|activity\(|Mail::.*send|->send\(" app/Http/Controllers/SuperAdminController.php app/Http/Controllers/superAdmin app/Actions/superAdmin app/Services/AccountLifecycleService.php app/Services/ShopOwnerRegistrationDecisionService.php app/Services/ShopReportModerationService.php app/Services/FlaggedAccountModerationService.php app/Services/SuspensionAppealService.php app/Services/PremiumPlanManagementService.php
```

Expected:

- no routed Phase 3 privileged workflow writes `AuditLog` or unnamed activity;
- direct mail is isolated inside the delivery job/factory path;
- any remaining subscription raw activity is explicitly Phase 5 debt and is not represented as Phase 3 compliant;
- dry-run commands make no changes and print bounded summaries.

- [ ] **Step 4: Run broader verification**

```bash
composer test
pnpm run test:frontend
pnpm run build
git diff --check
git status --short
```

Do not report TypeScript type-checking or linting as passed; this repository has no committed standalone scripts for those checks.

- [ ] **Step 5: Browser verification**

With the local app and queue worker running, verify:

- Admin opens `/admin/audit`, sees own/authorized operational history, and cannot see another administrator's security/plan history;
- Super Admin sees full privileged history and filters/pagination preserve scope;
- old safe data-report GET links redirect to `/admin/audit` and no fake export/report success remains;
- a privileged mutation shows committed state immediately while delivery remains queued;
- a forced failed delivery appears in monitoring/`failed_jobs` evidence without reverting state;
- retry/recovery does not create a second business mutation or success audit;
- correlation ID shown for a safe simulated failure matches the audit/support record.

- [ ] **Step 6: Commit review fixes and final evidence**

```bash
git add <only Phase 3 files changed by verified findings>
git commit -m "test: verify phase 3 transaction audit delivery"
```

If no files changed after review, do not create an empty commit.

---

## Phase 3 Acceptance Checklist

- [ ] Every in-scope committed local privileged transition and mandatory success audit commit atomically.
- [ ] Injected local failure leaves no partial business state, false success audit, or queued delivery job.
- [ ] Premium-plan and business-upgrade review use focused transaction owners and approved lock order.
- [ ] Subscription money movement remains explicitly uncertified and reserved for Phase 5.
- [ ] Runtime Super Admin workflows no longer write legacy `audit_logs` or unnamed activity, except explicitly documented Phase 5 subscription debt.
- [ ] Reliable legacy privileged history imports idempotently with original timestamps and provenance; ambiguous rows remain visible in reconciliation output and are not guessed.
- [ ] `/admin/audit` reads only authoritative privileged activity and enforces Admin versus Super Admin scope on the backend.
- [ ] Monitoring reads authoritative privileged activity and retains real queue/failed-job health.
- [ ] Privileged mail jobs are encrypted, uniquely keyed by business event/recipient/type/channel, and dispatched only after commit.
- [ ] Delivery failures never roll back committed decisions and remain recoverable/visible without raw exception or token disclosure.
- [ ] Secret-bearing jobs are encrypted and never exposed through logs, audit, failed-job summaries, or client responses.
- [ ] Privileged notification fan-out excludes inactive, setup-incomplete, non-MFA, and capability-ineligible administrators.
- [ ] Failure responses use correct HTTP semantics, generic messages, and a server-owned correlation ID.
- [ ] No fake audit export/report action remains reachable.
- [ ] Focused backend/frontend tests, full suites, build, structural inspection, and browser flows have fresh recorded evidence.

## Rollback and Recovery Notes

- The activity-provenance migration is additive. Retain provenance and source `audit_logs` during an application rollback so evidence is not destroyed.
- Do not reverse an applied audit import by deleting `activity_log` rows during ordinary rollback. Provenance allows later reconciliation and duplicate prevention.
- A failed delivery is retried independently. Never reverse a registration, suspension, appeal, moderation, plan, or upgrade decision merely because email failed.
- If a token-bearing delivery cannot be safely retried, rotate/resend through the existing audited workflow rather than revealing or reconstructing the token.
- If queue workers are unavailable, restore workers and process/retry the existing database queue. Do not switch privileged delivery to synchronous request-time mail.
- If the audit UI must be rolled back, preserve the ledger and use console/database inspection under operator controls; do not restore the simulated export page.

## Execution Order

```text
request correlation and boundary tests
        -> encrypted unique job
        -> migrate Phase 1-2 delivery
        -> premium-plan/business-upgrade transactions
        -> sanitized failure audit
        -> legacy audit import
        -> scoped audit UI + monitoring cutover
        -> runbook/failure injection
        -> sequential reviews and full verification
```

No task is complete based solely on a happy-path response, queued-job assertion, or UI toast. State, audit, rollback behavior, authorization scope, and retry behavior must be verified independently.
