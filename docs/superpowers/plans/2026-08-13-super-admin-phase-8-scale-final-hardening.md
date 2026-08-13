# Super Admin Phase 8 Scale and Final Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** DRAFT FOR APPROVAL

**Goal:** Bound every operational Super Admin query and hydration path, prove the document reminder and renewal workflows remain deterministic under scale and concurrency, retire compatibility aliases only when deployed evidence makes removal safe, and complete integrated negative-path verification for the hardened module.

**Architecture:** Keep the Phase 7 focused controllers, canonical `/admin` mutation surface, fixed capability middleware, MFA/recent reauthentication, workflow services, immutable `shop_documents`, authoritative `PrivilegedAudit`, and separate HR/shop expiry commands. Convert the remaining full-table page payloads to capped server pagination and database aggregates, load large billing history only for one selected subscription through a bounded read endpoint, and add only indexes justified by captured query plans. Do not introduce a repository layer, generic pagination framework, reporting engine, cache layer, or shared expiry abstraction.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Vitest, PHPUnit, MariaDB/MySQL production compatibility, SQLite test compatibility, Vite 7, pnpm.

**Design authority:** `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`, especially Sections 17-19, 21, 22 (Phase 8), 23, and 24; `docs/runbooks/super-admin-operations.md`; executed Phase 7 plan `docs/superpowers/plans/2026-08-13-super-admin-phase-7-structural-simplification.md`.

---

## Implementation Contract

### Acceptance criteria

1. Administrators, registrations, registered shops, users, shop reports, flagged accounts, suspension appeals, subscriptions, business upgrades, document renewals, and privileged audit history use server pagination with a modest default, a hard maximum of 100, preserved query strings, and deterministic `created_at DESC, id DESC` ordering unless a documented domain order is required.
2. Filters are allowlisted and validated server-side. Invalid filters do not broaden scope, bypass lifecycle constraints, or trigger unbounded queries.
3. Full-scope dashboard/card values are computed with database aggregates, not by loading every row into PHP or counting only the current page.
4. List responses select and hydrate only fields/relations needed for their rows. Query counts remain bounded as fixture volume grows; no per-shop, per-document, per-payment, per-refund, actor, or subject N+1 is introduced.
5. Subscription list rows are bounded summaries. Complete payment/refund history is fetched for one selected subscription through a capability-protected, paginated JSON read endpoint; billing mutations and authoritative ledger semantics remain unchanged.
6. Existing bounded paths remain bounded: users and audit stay capped, renewal review stays paginated, reminder scanning stays `chunkById`, and monitoring recent activity remains limited.
7. Schema indexes are added only after before-change SQL/query-plan evidence identifies a missing index for an accepted Phase 8 query. Every new index has a named migration, a production-compatible definition, and a focused schema/query test.
8. Shop expiry reminders still use `Asia/Manila`, fixed 30/7/0 thresholds, bounded chunks, database-backed deduplication, and no shop-status mutation. Retry, simultaneous-run, date-change, and version-promotion cases remain idempotent.
9. Renewal decisions and reminder scans preserve immutable history, one-current-version, stable supporting slots, reviewer verification, private storage, and legacy DTI/SEC continuity under larger fixtures and concurrent attempts.
10. Private document routes remain object-scoped and fail closed when mandatory access audit persistence fails. Pagination or detail endpoints expose metadata/URLs only to the same authorized capability boundary.
11. Every retained visible Admin/Super Admin control reaches a registered canonical route and handles success, validation, conflict, authorization, and unexpected failure without optimistic fake success.
12. Each compatibility GET alias is removed only after repository references, deployed persisted links, and redirect/bookmark telemetry satisfy the Phase 7 retirement criteria. Unknown or non-zero deployed usage causes that alias to remain documented; no mutation alias is restored.
13. Production confirmations for scheduler execution, overlap locking, shared-cache suitability, queue workers/retries, failed-job visibility, and `Asia/Manila` are recorded. `onOneServer()` is added only if shared atomic locking is verified.
14. Improvement claims include before/after row bounds, query counts, relevant query plans/index use, route alias counts, test results, and build output. Wall-clock latency and bundle-size claims remain `not measured` unless measured in a controlled environment.
15. No permission UI, policy engine, generic report builder, generic pagination service, cache dependency, generic document/expiry abstraction, schema rewrite, or new package is introduced.

### Pagination and filter contract

| Surface | Default / maximum | Initial allowlisted filters | Notes |
|---|---:|---|---|
| Administrators | 25 / 100 | `search`, `role`, `status`, `page`, `per_page` | Exclude current actor; metrics cover all other administrators. |
| Registrations | 25 / 100 | `search`, `status`, `page`, `per_page` | Registration queue and renewal queue remain separate. Documents are eager-loaded only for owners on the page. |
| Registered shops | 25 / 100 | `search`, `status`, `lifecycle`, `page`, `per_page` | List stays lightweight; private document metadata remains detail-on-demand. |
| Users | 15 / 100 | existing `q`, `role`, `status`, `department`, `lifecycle`, `page`, `per_page` | Preserve existing page size and relation loading; validate/cap existing inputs. |
| Shop reports | 25 / 100 shops | `search`, `priority`, `status`, `page`, `per_page` | Page aggregate shop groups; fetch one shop's report rows through a separate capped detail read. |
| Flagged accounts | 25 / 100 | `search`, `status`, `page`, `per_page` | Eager-load only the page's customer/shop records, including archived targets. |
| Suspension appeals | 25 / 100 | `search`, `status`, `page`, `per_page` | Presentation may mark stale records; listing must not mutate appeal state. |
| Subscriptions | 25 / 100 | `search`, `status`, `change_type`, `sort`, `page`, `per_page` | List uses summaries; selected history uses independent capped payment/refund pages. |
| Subscription history | 25 / 100 | `payment_page`, `refund_page`, `per_page` | One subscription only; returns authoritative immutable payment/refund rows. |
| Document renewals | existing 25 / 100 | existing `document_id`, `page`, `per_page` | Preserve deterministic `created_at DESC, id DESC`. |
| Business upgrades | existing 20 / 100 | existing validated filters | Preserve private documents and add the ID tie-breaker/bounded selects. |
| Privileged audit | existing 25 / 100 | existing validated filters | Preserve role/capability visibility before applying filters. |

Search is intentionally simple escaped SQL `LIKE` with bound parameters over the small allowlisted identity fields already displayed. Escape `%`, `_`, and the chosen escape character so user input cannot silently become an arbitrary wildcard pattern. Do not add full-text search, Elasticsearch, a query-builder dependency, arbitrary column sorting, or user-selectable page sizes above 100.

### Measurement contract

For each changed surface, capture before and after evidence with deterministic seeded data:

```text
response rows at 1x and larger fixture volume
database query count at 1x and larger fixture volume
pagination metadata and stable page membership
SQL shape and EXPLAIN/index use for accepted production queries
peak hydrated relation counts where nested history exists
```

Query-count tests must assert that relation-query count does not grow per row. They must not assert an unrealistically exact global count that changes when Laravel performs an equivalent harmless metadata query. Capture exact counts in execution notes, then use a tight justified ceiling or before/after relation delta in durable tests.

Do not use a local wall-clock threshold as a CI acceptance test. Record latency only when the same database, fixture, cache state, and command are used before and after.

### Explicit non-goals

- No automatic shop suspension, deactivation, or registration-status change from document expiry.
- No OCR, regulatory validity engine, configurable reminder thresholds, compliance dashboard, or general notification rules engine.
- No redesign of existing pages beyond the controls needed for server filters, pagination, and bounded detail loading.
- No rewrite of transaction services, provider refund/cancellation workflows, audit semantics, document versioning, or MFA/security flows.
- No background export/reporting subsystem, read replica, cache warming, or queue-based page hydration.
- No removal of a compatibility alias based only on local repository search.
- No deletion or rewriting of historical audit, payment, refund, suspension, appeal, notification, or document evidence.

---

## Current Baseline to Preserve

- Phase 7 executed at commit `3179695b4`; worktree was clean when this plan was created.
- Canonical privileged mutations live only under `/admin`; `/superAdmin` has six capability-protected GET aliases and no mutations. Phase 7 also retained eight legacy `/admin` page/detail GET aliases and the public `/shop/register` redirect. All fifteen compatibility reads require evidence-based decisions in Phase 8.
- `UserInterventionController`, `ShopDocumentRenewalController`, and `PrivilegedAuditVisibility` already paginate and cap operational lists. `SystemMonitoringDashboardController` uses database counts and limits recent activity to five rows.
- `AdministratorManagementController`, `RegisteredShopController`, `ShopOwnerRegistrationViewController`, `ShopReportsController`, `FlaggedAccountsController`, `SuspensionAppealsController`, and `SubscriptionManagementController` still call unbounded `get()` for page payloads.
- `ShopReportsController` additionally groups every report in PHP and calls per-shop pattern detection, producing an explicit Phase 8 aggregate/N+1 target. Shop report rows must become bounded detail-on-demand while moderation continues to accept only authorized, validated report IDs.
- The subscription page currently loads all subscriptions, payments, and refunds and performs cross-subscription calculations in PHP. This is the highest-risk hydration path and must become summary plus detail-on-demand without changing the ledger.
- Shop document reminders already query only current approved reviewer-verified dated versions, use `chunkById`, cap chunks at 1000, use `Asia/Manila`, and deduplicate with `shop_doc_reminder_delivery_unique`.
- Renewal review already uses capped pagination, deterministic ordering, scoped eager loading, transactions, and row locks. Phase 8 strengthens tests and indexes rather than replacing this workflow.
- Audit history already enforces visibility before validated filters and uses `activity_log_privileged_created_index` and `activity_log_privileged_event_index`.
- Phase 7 measured structural ownership and test/build results but explicitly recorded latency and bundle improvement as not measured.
- Local source/persisted-notification checks found no first-party legacy callers. Production persisted links and redirect/bookmark telemetry were not available, so that local result alone does not authorize removal of `/superAdmin/*`, old `/admin/*`, or `/shop/register` aliases.

---

## File Ownership Map

### Create

- `tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php` — pagination, row-bound, deterministic-order, query-count, aggregate, route, and compatibility contracts.
- `tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php` — bounded subscription summaries/history and ledger-preservation assertions.
- `resources/js/Pages/superAdmin/AdminTeam/__tests__/AdminManagement.test.tsx` — administrator server-filter and pagination behavior.
- Artisan-generated `database/migrations/<timestamp>_add_super_admin_operational_query_indexes.php` — only the measured, named indexes accepted in Task 2; omit this file if existing indexes cover every accepted query.

### Modify

- `app/Http/Controllers/superAdmin/AdministratorManagementController.php`
- `app/Http/Controllers/superAdmin/RegisteredShopController.php`
- `app/Http/Controllers/superAdmin/UserInterventionController.php`
- `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php`
- `app/Http/Controllers/superAdmin/ShopReportsController.php`
- `app/Http/Controllers/superAdmin/FlaggedAccountsController.php`
- `app/Http/Controllers/superAdmin/SuspensionAppealsController.php`
- `app/Http/Controllers/superAdmin/SubscriptionManagementController.php`
- `app/Http/Controllers/superAdmin/ShopDocumentRenewalController.php` — only measured query/select/index adjustments; preserve workflow ownership.
- `app/Http/Controllers/superAdmin/ShopOwnerUpgradeRequestController.php` — bounded selects and deterministic ordering only; preserve review/download ownership.
- `app/Services/PrivilegedAuditVisibility.php` — only measured query/select/index adjustments; preserve visibility semantics.
- `app/Services/ShopDocumentReminderService.php` — only bounded-query adjustments proven necessary by measurement; preserve thresholds and delivery transaction.
- `routes/web.php` — bounded subscription-history GET and evidence-approved alias removals only.
- `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx`
- `resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx`
- `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx`
- `resources/js/Pages/superAdmin/Shops/ShopReports.tsx`
- `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx`
- `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx`
- `resources/js/Pages/superAdmin/Users/SuspensionAppeals.tsx`
- `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx`
- `resources/js/ziggy.js` — regenerate if route aliases/history route change; never hand-edit.
- Existing adjacent frontend tests under `resources/js/Pages/superAdmin/**/__tests__/` for each changed page.
- `tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php`
- `tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php`
- `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- `tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php`
- `tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php`
- `tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php`
- `tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php`
- `tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php`
- `tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php`
- `tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php`
- `tests/Feature/SuperAdmin/ShopDocumentRenewalReviewTest.php`
- `tests/Feature/SuperAdmin/ShopDocumentRenewalConcurrencyTest.php`
- `tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php`
- `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`
- `tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php`
- `tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php` — reflect only evidence-approved alias removal.
- `docs/runbooks/super-admin-operations.md` — scale contract, production confirmations, measurements, and final compatibility inventory.
- `docs/ai-learning-log.md` — only if execution reveals a durable repository lesson.

### Delete

- No application file is pre-authorized for deletion.
- Individual compatibility route declarations may be removed in Task 8 only when their specific retirement evidence passes.

---

## Task 1: Capture Scale Baselines and Freeze Failing Contracts

**Files:**
- Create: `tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php`
- Create: `tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php`
- Modify: `docs/runbooks/super-admin-operations.md` — execution evidence section only.

- [ ] **Step 1: Record environment and current operational inventory**

Record the database driver/version, configured app/shop timezone, scheduler/cache/queue configuration names without secrets, route counts, aliases, and current indexes:

```powershell
php artisan about
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
php artisan schedule:list
php artisan tinker --execute="dump(DB::connection()->getDriverName(), DB::selectOne('select version() as version'));"
```

Use schema inspection appropriate to the active driver. Never print credentials or `.env` contents.

- [ ] **Step 2: Capture before-change query and hydration baselines**

Create deterministic fixtures at small and larger volumes and capture query counts/response row counts for administrators, registrations, shops, users, flags, appeals, subscriptions, renewals, audit, and monitoring. Record the unbounded response sizes and the existing bounded controls. Use Laravel query listeners in tests or a local measurement harness that is deleted before completion.

- [ ] **Step 3: Capture SQL and query plans**

Capture the SQL shape and `EXPLAIN` for the accepted production-driver queries: each paginated list/count pair, pending renewals, reminder candidates, audit visibility/filter queries, and subscription aggregate/history queries. SQLite evidence may validate behavior but cannot justify a MariaDB/MySQL production index by itself.

- [ ] **Step 4: Write failing pagination and aggregate contracts**

Assert every surface in the pagination contract returns at most its requested capped page, exposes pagination metadata, preserves filters, and uses an ID tie-breaker when timestamps match. Assert full-scope stats remain the same when moving between pages.

- [ ] **Step 5: Write failing bounded-query contracts**

Compare small and larger fixtures. Assert query count stays within a justified fixed delta, and assert subscription list hydration excludes unbounded payment/refund collections. Preserve already-green user, renewal, audit, monitoring, and reminder bounds.

- [ ] **Step 6: Run the red scale suites**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php
```

Expected: assertions for the six current full-table pages and subscription history boundary fail; existing bounded-path assertions pass.

- [ ] **Step 7: Commit baseline contracts and evidence**

```powershell
git add -- tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php docs/runbooks/super-admin-operations.md
git commit -m "test: define phase 8 scale boundaries"
```

---

## Task 2: Add Only Measured Operational Indexes

**Files:**
- Create conditionally with Artisan: `database/migrations/<timestamp>_add_super_admin_operational_query_indexes.php`
- Modify: `tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php`

- [ ] **Step 1: Compare accepted queries with existing indexes**

Review exact column order, selectivity, sorting, soft-delete predicates, and foreign-key indexes. Candidate areas are administrator status/order, shop status/lifecycle/order, pending registrations, review-report status/order, appeal status/order, subscription status/order, pending renewal order, reminder date candidates, and audit actor/subject filters. A candidate is not an instruction to add an index.

- [ ] **Step 2: Reject redundant or low-value indexes**

Do not duplicate an existing unique/index prefix, index every filter independently, optimize leading-wildcard search with a normal B-tree, or add indexes solely to satisfy a structural test. Prefer the smallest composite index serving a demonstrated high-frequency filter/order query.

- [ ] **Step 3: Write failing schema/query-plan assertions for accepted indexes**

For every accepted index, record its before `EXPLAIN`, expected query, and stable explicit name. Add portable schema assertions; keep production-driver `EXPLAIN` evidence in execution notes when CI uses SQLite.

- [ ] **Step 4: Add one reversible migration if and only if indexes are accepted**

Generate it with:

```powershell
php artisan make:migration add_super_admin_operational_query_indexes
```

Use `Schema::table()` and explicit names within MySQL/MariaDB identifier limits. `down()` removes only indexes added here. Do not modify old migrations or rebuild tables.

- [ ] **Step 5: Verify migration portability and query plans**

```powershell
php artisan migrate --pretend
php artisan test tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php
```

On an approved disposable database, also run migrate/rollback/migrate and repeat `EXPLAIN`. Never run rollback against shared or production data.

- [ ] **Step 6: Commit measured indexes or record N/A**

If indexes are justified:

```powershell
git add -- database/migrations/*_add_super_admin_operational_query_indexes.php tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php docs/runbooks/super-admin-operations.md
git commit -m "perf: index privileged operational queries"
```

If none are justified, create no migration and record the existing index/query-plan evidence in the runbook.

---

## Task 3: Bound Administrator, Shop, and User Operational Lists

**Files:**
- Modify: `app/Http/Controllers/superAdmin/AdministratorManagementController.php`
- Modify: `app/Http/Controllers/superAdmin/RegisteredShopController.php`
- Modify: `app/Http/Controllers/superAdmin/UserInterventionController.php`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx`
- Modify: adjacent existing tests and `tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php`.

- [ ] **Step 1: Add failing filter, cap, tie-breaker, and metric tests**

Cover valid/invalid filters, `per_page=100` and over-limit input, identical timestamps across page boundaries, archived visibility, current-admin exclusion, private-ID URL scope, and aggregate metrics independent of the current page.

- [ ] **Step 2: Paginate administrators**

Validate allowlisted filters, apply them server-side, add `id DESC` after `created_at DESC`, select only displayed/security-state fields, and return paginator metadata. Compute administrator metrics with database counts over the authorized base scope excluding the current actor.

- [ ] **Step 3: Paginate registered shops**

Move search/status/lifecycle filtering to the server, add deterministic order, and return only list fields. Keep `show()` as the one-shop detail boundary for private document URLs. Compute totals/current-month cards with aggregate queries over the intended scope, not page rows.

- [ ] **Step 4: Harden the existing user paginator**

Validate/cap existing filters and `per_page`, select the required user columns, constrain eager-loaded employee fields, add the ID tie-breaker, and retain `withQueryString()`. Do not broaden the current `whereNull('shop_owner_id')` operational scope or expose private ID paths.

- [ ] **Step 5: Update page controls**

Replace client-only whole-dataset filtering with debounced Inertia GET filters and paginator navigation preserving state/scroll. Keep lifecycle mutations and role capability visibility unchanged. Do not add an infinite-scroll abstraction.

- [ ] **Step 6: Verify account surfaces**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/AdminTeam/__tests__/AdminManagement.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx
```

If the administrator test file does not yet exist, create it beside the page with the repository's existing Vitest conventions.

- [ ] **Step 7: Commit bounded account lists**

```powershell
git add -- app/Http/Controllers/superAdmin/AdministratorManagementController.php app/Http/Controllers/superAdmin/RegisteredShopController.php app/Http/Controllers/superAdmin/UserInterventionController.php resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx resources/js/Pages/superAdmin tests/Feature/SuperAdmin
git commit -m "perf: bound privileged account lists"
```

Stage only files changed by this task; do not use the broad path arguments if they would include unrelated work.

---

## Task 4: Bound Registration, Shop-Report, Flagged-Account, and Appeal Queues

**Files:**
- Modify: `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php`
- Modify: `app/Http/Controllers/superAdmin/ShopReportsController.php`
- Modify: `app/Http/Controllers/superAdmin/FlaggedAccountsController.php`
- Modify: `app/Http/Controllers/superAdmin/SuspensionAppealsController.php`
- Modify: `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx`
- Modify: `resources/js/Pages/superAdmin/Shops/ShopReports.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/SuspensionAppeals.tsx`
- Modify: related feature/frontend tests and `tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php`.

- [ ] **Step 1: Add failing queue contracts**

Assert capped pages, allowlisted status/search, deterministic order, global stats, bounded eager loading, and unchanged Admin/Super Admin capability behavior. Include aggregate shop-report groups, archived flagged-account targets, and stale appeal presentation.

- [ ] **Step 2: Paginate registration review**

Restrict the base query to the statuses intended for this operational page, apply server filters, paginate owners first, and eager-load only documents for that page with fields required by review. Preserve legacy DTI/SEC `reviewType`, validity classification, private route URLs, and decision-service behavior.

- [ ] **Step 3: Replace full shop-report grouping with bounded aggregates and detail**

Page shop-owner groups from SQL aggregates for total/open/latest report and priority, join or subquery the latest warning strike and latest report fields, and replace `ShopReport::detectPatterns()` per group with bounded aggregate expressions over the page's shop IDs. Add a capability-protected `GET /admin/shop-reports/{shopOwner}` read endpoint that returns one shop's reports with capped deterministic pagination. Keep report moderation POST ownership, the maximum validated report-ID set, warning escalation, locks, audit, and notifications unchanged.

Update the page to fetch one group's report rows when expanded. Do not send every open report ID in the list payload; build the moderation selection from the bounded detail response and require the server service to revalidate ownership/current status as it already does.

- [ ] **Step 4: Paginate flagged accounts**

Validate filters, paginate reports with customer/shop eager loading, and compute status metrics in SQL if displayed. Keep report snapshots and archived-target visibility intact. Mutation responses and `FlaggedAccountModerationService` remain untouched.

- [ ] **Step 5: Paginate appeals without list-time mutation**

Validate filters, paginate deterministically, and compute stats with aggregate queries. Preserve `SuspensionAppealService::presentation()` for current/stale/actionable semantics. Expiry/state transitions belong to their existing workflow/maintenance owner, not a GET page request.

- [ ] **Step 6: Update the remaining queue UIs**

Use server filters/pagination while retaining local state updates only for the current page after successful decisions. A decision that removes a row from the active filter should trigger a bounded reload, not pretend the full queue is locally authoritative.

- [ ] **Step 7: Verify review workflows**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts resources/js/Pages/superAdmin/Shops/__tests__/ShopReports.test.tsx resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuspensionAppeals.test.tsx
```

- [ ] **Step 8: Regenerate routes and commit bounded review queues**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
git add -- app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php app/Http/Controllers/superAdmin/ShopReportsController.php app/Http/Controllers/superAdmin/FlaggedAccountsController.php app/Http/Controllers/superAdmin/SuspensionAppealsController.php routes/web.php resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx resources/js/Pages/superAdmin/Shops/ShopReports.tsx resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx resources/js/Pages/superAdmin/Users/SuspensionAppeals.tsx resources/js/ziggy.js tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php tests/Feature/Reports/ShopAndCustomerReportFlowTest.php
git commit -m "perf: bound privileged review queues"
```

---

## Task 5: Replace Full Billing Hydration with Summary and Bounded History

**Files:**
- Modify: `app/Http/Controllers/superAdmin/SubscriptionManagementController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx`
- Modify: `resources/js/ziggy.js`
- Modify: `tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php`
- Modify: `tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php`
- Modify: `tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php`
- Modify: related subscription frontend tests.

- [ ] **Step 1: Freeze ledger and intervention semantics in failing tests**

Seed multiple payments, succeeded/failed/unresolved refund attempts, renewal/replacement chains, and identical timestamps. Assert list stats, eligibility, block reasons, previous plan, cancellation state, and provider-backed mutation targets remain authoritative after pagination.

- [ ] **Step 2: Build a paginated subscription summary query**

Validate search/status/change-type/sort fields against fixed values. Paginate subscriptions with deterministic order and database-backed aggregate/subquery data for gross paid, succeeded refunds, unresolved refund existence, pending lifecycle child existence, and the eligible payment identifier. Eager-load only the selected parent relations needed for list rows.

Do not derive a refund by rewriting payment history, infer provider success, or make one query per subscription.

- [ ] **Step 3: Compute global billing cards in SQL**

Compute active/expired/expiring-soon counts and gross/refunded/net totals independently of the page. Preserve current cancellation entitlement and effective-end-date rules. Document any unavoidable expression difference between SQLite tests and MariaDB/MySQL.

- [ ] **Step 4: Add a bounded history endpoint**

Register a capability-protected `GET /admin/subscriptions/{subscription}/history` owned by `SubscriptionManagementController`. Return the selected subscription's immutable payments and refunds in independently capped, deterministic paginators. Do not place `privileged.recent` on this read unless the existing private-data policy requires it; all mutations keep their current recent-reauth boundary.

- [ ] **Step 5: Load history on demand in the modal**

The list payload must not include full `payments` or `refund_attempts` collections. Fetch history after the user opens one subscription, display loading/error/empty states, and paginate within the detail. Continue using the summary's authoritative eligible payment ID for refund mutation and reload summary/history after committed interventions.

- [ ] **Step 6: Verify bounded billing and transaction containment**

```powershell
php artisan test tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementBilling.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementContainment.test.tsx
```

- [ ] **Step 7: Regenerate routes and commit billing scale work**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
git add -- app/Http/Controllers/superAdmin/SubscriptionManagementController.php routes/web.php resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx resources/js/ziggy.js tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php
git commit -m "perf: bound privileged billing history"
```

---

## Task 6: Prove Audit, Monitoring, Renewal, and Reminder Bounds

**Files:**
- Modify only if evidence requires: `app/Services/PrivilegedAuditVisibility.php`
- Modify only if evidence requires: `app/Http/Controllers/superAdmin/ShopDocumentRenewalController.php`
- Modify only if evidence requires: `app/Http/Controllers/superAdmin/ShopOwnerUpgradeRequestController.php`
- Modify only if evidence requires: `app/Services/ShopDocumentReminderService.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php`
- Modify: `tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php`
- Modify: `tests/Feature/SuperAdmin/ShopDocumentRenewalReviewTest.php`
- Modify: `tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php`
- Modify: `tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php`

- [ ] **Step 1: Add growth-independent query tests**

At two fixture volumes, prove audit actor/subject eager loading is bounded, monitoring returns five recent visible events, renewal and business-upgrade list queries do not grow per owner/document, and reminder scanning processes configured chunk sizes without loading all candidates. Add the missing `id DESC` tie-breaker and capped selected/eager-loaded columns to business upgrades without changing review/download behavior.

- [ ] **Step 2: Test every audit filter under both roles**

Cover event, actor, target type/ID, correlation ID, date range, page size, and same-timestamp ordering. Prove regular Admin visibility is applied before filters and cannot be broadened by actor/target input; Super Admin retains full privileged history.

- [ ] **Step 3: Test deterministic timezone and reminder boundaries**

Freeze time around `Asia/Manila` midnight and UTC day boundaries. Cover 30, 7, and 0 days; non-threshold dates; no-expiration; unverified/current/superseded/rejected versions; archived or non-approved owners; changed expiration identity; and chunk values 1, 100, and capped-over-1000 input.

- [ ] **Step 4: Test retry and simultaneous delivery**

Run repeated and overlapping attempts for the same document/version/date/threshold/recipient. Assert one delivery identity, one operational notification, deterministic sent/skipped counts, and no shop-status/audit-history mutation. Use the unique constraint as the final concurrency guard.

- [ ] **Step 5: Test renewal/reminder races**

Cover reminder scanning while a renewal is promoted, two simultaneous renewal approvals, approval/rejection collision, stale predecessor, and new expiration replacing old identity. Assert one current version, immutable predecessor, one valid terminal decision, and reminders only for the current approved version.

- [ ] **Step 6: Apply only measured query-shape improvements**

If tests/query plans reveal excess selected columns, missing eager constraints, or a relation N+1, make the smallest local adjustment in the existing owner. Do not create a general report/query service or merge HR and shop expiry.

- [ ] **Step 7: Verify bounded infrastructure paths**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalReviewTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalConcurrencyTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php
php artisan schedule:list
```

- [ ] **Step 8: Commit measured hardening**

```powershell
git add -- app/Services/PrivilegedAuditVisibility.php app/Http/Controllers/superAdmin/ShopDocumentRenewalController.php app/Http/Controllers/superAdmin/ShopOwnerUpgradeRequestController.php app/Services/ShopDocumentReminderService.php tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalReviewTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalConcurrencyTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php
git commit -m "test: harden privileged bounded workflows"
```

Stage only modified files; omit unchanged optional application files.

---

## Task 7: Complete Private-Access and Integrated Negative-Path Verification

**Files:**
- Modify: `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php`
- Modify adjacent workflow tests only where a missing negative path is discovered.

- [ ] **Step 1: Build the visible-action inventory**

Enumerate every Admin and Super Admin sidebar/page action after pagination changes and map it to route, method, capability, active/MFA middleware, recent reauthentication where required, object scope, service owner, success audit, and failure behavior.

- [ ] **Step 2: Test private-object boundaries at scale**

For shop documents, customer IDs, registration documents, and renewal documents, cover authorized owner/document pairing, cross-owner ID substitution, archived targets where policy allows history, missing file, invalid path, range/download headers, suspended actor, regular-Admin authorized scope, and mandatory audit failure rollback/fail-closed behavior.

- [ ] **Step 3: Test stale pages and race failures**

Submit decisions from an old page after another actor changes registration, flag, appeal, account, plan, subscription, or renewal state. Assert `409`/validation/authorization behavior, no duplicate transition, no fake frontend success, and no duplicate authoritative audit/notification.

- [ ] **Step 4: Re-run core invariants**

Cover final active MFA-enrolled Super Admin, self-mutation, active-status next-request denial, suspension/current-appeal linkage, warning strike uniqueness, provider initiation/final outcome audit boundaries, immutable billing history, immutable document versions, legacy DTI/SEC continuity, and expiration never changing shop status.

- [ ] **Step 5: Run the integrated security suite**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/ShopReportModerationInvariantTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalConcurrencyTest.php tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php
```

- [ ] **Step 6: Commit final negative-path coverage**

```powershell
git add -- tests/Feature/SuperAdmin
git commit -m "test: complete privileged negative path coverage"
```

Stage exact changed test files if unrelated work exists.

---

## Task 8: Decide Compatibility Retirement from Deployed Evidence

**Files:**
- Modify conditionally: `routes/web.php`
- Modify conditionally: `resources/js/ziggy.js`
- Modify: `tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php`
- Modify: `docs/runbooks/super-admin-operations.md`

- [ ] **Step 1: Reconfirm zero first-party callers**

```powershell
rg -n "/superAdmin/|superAdmin\.|/admin/(admin|create-admin|shop-owner-registration-view|registered-shops|shops/.+/details|user-management|subscription-management|data-reports)|/shop/register" app routes resources/js tests --glob '!resources/js/ziggy.js'
rg -n "action_url.*(superAdmin|admin/(admin|create-admin|shop-owner-registration-view|registered-shops|user-management|subscription-management|data-reports)|shop/register)|/superAdmin/" database app tests
```

Classify every match as an alias declaration, explicit absence test, fixture, or real caller. Migrate any real caller before considering removal.

- [ ] **Step 2: Inspect deployed persisted links safely**

Run a read-only, bounded count/group query against each deployed database or approved snapshot for notification/action URL variants pointing to all retained aliases. Record counts and newest timestamps without exporting recipient content or secrets. Local zero is supporting evidence only.

- [ ] **Step 3: Review redirect/bookmark telemetry**

For an agreed observation window, count requests to each of the six `/superAdmin` aliases, eight old `/admin` aliases, and `/shop/register` by path/status, and distinguish known automated probes. Do not log query-string contents containing sensitive filters. If telemetry does not exist, record `unknown`; do not infer zero.

- [ ] **Step 4: Decide each alias independently**

Remove an alias only when source references are zero, deployed persisted-link counts are zero across supported variants, and telemetry shows no legitimate use for the observation window. If any condition is unknown or non-zero, retain that one protected GET redirect with its reason and next review date.

- [ ] **Step 5: Test removals and retained redirects**

Removed GETs return `404`; retained aliases preserve query strings and target-equivalent authentication/capability middleware. Every non-GET request remains `404|405` with no state/audit/notification effect.

- [ ] **Step 6: Regenerate Ziggy only when routes changed**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
php artisan route:list --path=superAdmin --except-vendor
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=shop/register --except-vendor
php artisan test tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php
```

- [ ] **Step 7: Commit the evidence-based decision**

```powershell
git add -- routes/web.php resources/js/ziggy.js tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php docs/runbooks/super-admin-operations.md
git commit -m "chore: retire proven privileged compatibility routes"
```

If no alias qualifies, do not create a no-op route commit; commit only the documented evidence if it materially updates the runbook.

---

## Task 9: Production Readiness, Review Stack, and Final Verification

**Files:**
- Modify: `docs/runbooks/super-admin-operations.md`
- Modify: `docs/superpowers/plans/2026-08-13-super-admin-phase-8-scale-final-hardening.md` — execution evidence/status only.
- Create: `docs/ai-learning-log.md` — only for a durable lesson; otherwise do not add it.

- [ ] **Step 1: Confirm production operating assumptions**

Record, without secrets:

```text
APP/shop timezone resolves to Asia/Manila
exact scheduler host/process and daily execution evidence
withoutOverlapping lock store and lock duration suitability
shared atomic cache support before any onOneServer use
queue worker process, retry/backoff, timeout, and failed-job monitoring
deployment route/config cache rebuild behavior
database engine/version used for EXPLAIN and index evidence
```

If shared atomic locking is unverified, retain the verified single-scheduler model and do not add `onOneServer()`.

- [ ] **Step 2: Run the required review stack sequentially**

Record each result once:

1. **simplify / ponytail:** remove duplicate pagination code only when a native paginator/page component already exists; reject generic query repositories, report builders, cache layers, and new dependencies;
2. **standards review:** Laravel validation, eager loading, pagination, aggregate queries, migration/index naming, controller ownership, React/Inertia conventions;
3. **spec review:** every Phase 8 acceptance criterion, core invariant, pagination surface, and evidence gate;
4. **correctness/risk review:** capability scope, private objects, billing ledger, audit visibility, stable ordering, concurrent decisions, reminder identity, timezone, immutable versions, legacy DTI/SEC;
5. **TypeScript/React review:** typed paginator/history payloads, stable keys, debounced server filters, loading/error states, no stale full-array assumptions or unsafe assertions;
6. **code splitting:** `N/A` unless a new heavy conditional dependency was introduced; pagination alone does not justify splitting small components;
7. **gauge improvements:** before/after response rows, query counts, query plans/index use, relation hydration, alias counts; latency/bundle size `not measured` unless controlled evidence exists;
8. **security review:** filters cannot expand visibility, detail endpoint matches subscription capability, private routes remain scoped/audited, old mutations remain absent;
9. **verification-before-completion:** all claims use fresh command output.

- [ ] **Step 3: Run reuse and dead-code scans**

```powershell
rg -n -- "->get\(\)|::all\(\)" app/Http/Controllers/superAdmin app/Services/PrivilegedAuditVisibility.php app/Services/ShopDocumentReminderService.php
rg -n "payments\.refunds|subscriptionModels|flaggedAccounts: .*\[\]|registrationsState|appeals\.filter" app resources/js tests
rg -n "/superAdmin/|superAdmin\.|/admin/(admin|create-admin|shop-owner-registration-view|registered-shops|shops/.+/details|user-management|subscription-management|data-reports)|/shop/register" app routes resources/js tests --glob '!resources/js/ziggy.js'
rg -n "documents\(\)->delete|ShopDocument::.*delete|Business Registration \(DTI/SEC\)" app routes resources/js tests
rg -n "AuditLog::create|activity\(\)" app/Http/Controllers/superAdmin app/Services
```

Every remaining full collection load must be a demonstrably bounded relation/list, a tiny configuration set such as plans, or documented reconciliation tooling. Remove stale imports, abandoned local filters, obsolete payload types, and TODOs created by this phase.

- [ ] **Step 4: Run focused backend suites**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalReviewTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalConcurrencyTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php
```

- [ ] **Step 5: Run focused frontend suites**

```powershell
pnpm exec vitest run resources/js/Pages/superAdmin/AdminTeam/__tests__/AdminManagement.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts resources/js/Pages/superAdmin/Shops/__tests__/ShopReports.test.tsx resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuspensionAppeals.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementBilling.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementContainment.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/DocumentRenewalQueue.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/BusinessUpgradeRequests.test.tsx resources/js/Pages/superAdmin/Audit/__tests__/PrivilegedAuditHistory.test.tsx resources/js/Pages/superAdmin/__tests__/SystemMonitoringDashboard.test.tsx
```

- [ ] **Step 6: Run structural and generated-file inspection**

```powershell
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
php artisan schedule:list
php artisan migrate --pretend
php artisan ziggy:generate resources/js/ziggy.js
git diff --check
```

Verify one canonical mutation per semantic action, only evidence-retained GET aliases, one shop reminder schedule, separate HR/shop commands, capped page sizes, and no generated route drift.

- [ ] **Step 7: Run broad quality gates**

```powershell
composer test
pnpm run test:frontend
pnpm run build
git diff --check
```

The repository has no committed TypeScript compiler configuration or frontend lint script. Do not report type-checking or linting as passing unless tooling is actually added and run. If the Composer wrapper times out after the underlying Laravel suite completes, record both exact outputs and do not call the wrapper itself passed.

- [ ] **Step 8: Browser-verify integrated role and scale flows**

Using realistic multi-page fixtures, verify desktop/mobile, browser console, and network behavior:

```text
Admin -> paginated authorized monitoring, registrations, shops, users, flags, own audit scope
Admin -> denied administrator management, plan/intervention actions, security administration, full audit
Super Admin -> every canonical page/filter/page/action/detail history
same-timestamp rows -> no duplicates or omissions between pages
subscription modal -> bounded history, retry, cancellation/refund conflicts
private document -> authorized stream; cross-owner and audit-failure denial
renewal -> private review, concurrent decision conflict, immutable promotion
expiry command fixture -> 30/7/0 delivery once; shop status unchanged
retained alias -> protected query-preserving redirect
removed alias -> 404; every old mutation -> 404/405 and no side effect
```

- [ ] **Step 9: Record final measurements and close the program**

Set this plan to `EXECUTED` only after all applicable evidence is recorded. Include before/after per-surface row limits/query counts, accepted/rejected index candidates and query plans, production operating confirmations, alias decisions, focused/full tests, build, browser flows, and unresolved external prerequisites. Phase 8 is the final planned phase; remaining unrelated product work becomes separately scoped work rather than Phase 9.

---

## Acceptance Checklist

- [ ] Every operational list named by the design is server-paginated, capped, scoped, and deterministically ordered.
- [ ] Full-scope stats use database aggregates and do not count only current-page rows.
- [ ] Shop-report grouping/pattern detection is aggregate and bounded; report detail is one-shop paginated and moderation revalidates selected IDs.
- [ ] Larger fixtures do not increase relation-query counts per row.
- [ ] Subscription list hydration is bounded and complete history is one-subscription, server-paginated, and capability-protected.
- [ ] Billing history, refund outcomes, cancellation entitlement, and provider intervention ownership remain authoritative.
- [ ] Audit visibility applies before filters; regular Admin cannot broaden scope.
- [ ] Monitoring uses bounded aggregates/recent activity and reports only measurable health state.
- [ ] Renewal queue and reminder scan remain bounded with deterministic date/order behavior.
- [ ] Reminder retries/concurrency create one delivery/notification and never mutate shop status.
- [ ] Renewal concurrency preserves one current version and immutable predecessors.
- [ ] Legacy DTI/SEC evidence continues to satisfy approved-shop continuity until safely classified or renewed.
- [ ] Private document and valid-ID access remains object-scoped, private, and mandatory-audited.
- [ ] Fixed capabilities, active/MFA middleware, recent reauthentication, and final-Super-Admin invariants remain enforced.
- [ ] New indexes have measured query-plan justification; redundant candidates were rejected.
- [ ] Production timezone, scheduler, overlap locks, cache assumptions, queue workers, retries, and failed-job visibility are recorded.
- [ ] Each compatibility alias is either removed with complete deployed evidence or retained with an explicit reason/review date.
- [ ] `/admin` remains the only privileged mutation prefix; old mutations remain `404|405` without side effects.
- [ ] Every visible Admin/Super Admin action and negative path is feature- and browser-verified.
- [ ] Before/after measurements, focused/full suites, build, route/schedule inspection, and diff hygiene are recorded.
- [ ] No generic framework, new dependency, automatic compliance enforcement, destructive history change, or unrelated feature was introduced.

## Rollout and Rollback Notes

Deploy index migrations before or with the application code that relies on them, using the normal maintenance/online-DDL procedure appropriate to measured table sizes. Apply pagination backend and frontend changes in coherent commits so payload contracts move together. Rebuild route/config caches and Ziggy metadata through normal deployment. Monitor page errors, query latency, database load, `404/405`, compatibility redirects, queue failures, reminder counts, duplicate-key skips, and provider intervention failures.

Rollback application commits in reverse order while preserving migrations unless index removal has been separately assessed as safe. Do not roll back by restoring unbounded duplicate mutation routes, legacy audit writers, destructive document replacement, pseudo-refunds, or automatic expiry enforcement. If an alias was removed and verified legitimate traffic reappears, restore only the protected query-preserving GET redirect to the canonical focused owner; never restore an old mutation. Historical documents, audit rows, subscriptions, payments, refunds, notifications, suspension events, and appeals are never deleted as part of rollback.
