# Shop Owner Phase 3B Material Exceptions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a readiness-gated `Urgent Exceptions` bucket to the existing Shop Owner Action Center, launch it first with Compliance Documents, then onboard Failed Refunds and Unowned Logistics Failures only after their authoritative domain prerequisites pass.

**Architecture:** Evolve the existing Phase 3A DTO, adapter registry, and in-memory coordinator instead of creating a second exception system. Domain policies determine materiality and current responsibility; adapters emit explicit bucket metadata; the shared coordinator provides separate bucket summaries and queues. The plan has named stop/release gates so Compliance can ship safely while Refund and Logistics remain hidden until ready.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, PHPUnit, Inertia 2, React 18, TypeScript 5.7, Vitest, Tailwind CSS 4, pnpm, local browser/Playwright verification.

---

## Source specifications

- `docs/superpowers/specs/2026-08-15-shop-owner-phase-3-action-center-master-design.md`
- `docs/superpowers/specs/2026-08-16-shop-owner-phase-3b-material-exceptions-design.md`
- `docs/superpowers/specs/2026-08-15-shop-owner-phase-3a-owner-decisions-design.md`
- `docs/superpowers/plans/2026-08-15-shop-owner-phase-3a-owner-decisions.md`

The Phase 3B focused specification is authoritative. This is the one implementation plan for all declared Phase 3B coverage. A gate becoming releasable does not authorize starting or enabling later blocked gates.

## Required implementation skills

- Use `@superpowers:test-driven-development` for every code task.
- Use `@laravel-best-practices` for Eloquent queries, policies, migrations, services, controllers, authorization, and tests.
- Use `@ui-ux-pro-max`, `@design-system`, and `@ui-styling` for the two-bucket Action Center UI.
- Use `@vercel-react-best-practices` for React/TSX changes and `@webapp-testing` for browser-visible behavior.
- Use `@ponytail` and `@karpathy-guidelines` during the sequential simplification and scope review.
- Use `@security-review` for tenant scoping, sensitive Compliance metadata, recovery ownership, URLs, and telemetry.
- Use `@superpowers:verification-before-completion` before any release or completion claim.

Repository policy requires sequential implementation unless the user separately approves the optional parallel-review gate.

## Non-negotiable boundaries

- Keep one Action Center coordinator and one request-time read model. Do not add Action Center tables, models, jobs, polling, WebSockets, SSE, or persisted attention state.
- Keep domain pages as the only execution surfaces. Do not add Approve, Reject, Dismiss, Acknowledge, Hide, Snooze, or Resolve controls to Action Center rows.
- Classification order is fixed: owner decision, then deterministic other-party responsibility, then material unowned exception, otherwise no item.
- Phase 3B reserves but does not emit `waiting_on_others`. Deterministic other-party concerns stay omitted until Phase 3C.
- Materiality thresholds belong to Compliance, Refund, and Logistics domain policies, never `config/owner_action_center.php` or the coordinator.
- Complete the `urgent` to `critical` vocabulary migration atomically before enabling Compliance. Do not support both tokens.
- Use the configured business timezone for Compliance date boundaries.
- Treat normal non-qualifying lifecycle states as exclusions, not adapter failures. Treat structurally inconsistent current-approved records as domain-health failures.
- Blocked Refund and Logistics sources remain hidden: no filters, counts, placeholders, or unavailable notices.
- A later source is enabled only after its domain prerequisite and adapter readiness gates pass independently.
- Preserve all Phase 3A routes, decisions, ordering, pagination, authorization, and failure behavior.

## Locked file structure

### Shared Action Center evolution

- `app/Contracts/OwnerActionCenter/OwnerAttentionAdapter.php` — add explicit primary-bucket identity to adapters.
- `app/Support/OwnerActionCenter/OwnerAttentionItem.php` — accept and validate explicit bucket, responsibility, action-required, and coverage metadata.
- `app/Support/OwnerActionCenter/OwnerAttentionQuery.php` — validate bucket-scoped coverage, page, and candidate bounds.
- `app/Support/OwnerActionCenter/OwnerActionCenterResult.php` — serialize selected bucket, bucket-scoped coverage counts, and adapter health.
- `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php` — resolve adapters by explicit bucket plus coverage source.
- `app/Services/OwnerActionCenter/OwnerActionCenterService.php` — reuse one merge/order/count/paginate path for bucket-specific reads.
- `config/owner_action_center.php` — add only bounded bucket/adapter enablement; no domain thresholds.

### Compliance domain and adapter

- `app/Services/ShopDocumentValidityService.php` — become the singular side-effect-free Compliance expiry-window policy while preserving existing broad validity compatibility.
- `app/Services/ShopDocumentReminderService.php` — consume shared boundary definitions while retaining exact 30/7/0 delivery milestones.
- `app/Services/OwnerActionCenter/Adapters/ComplianceDocumentAttentionAdapter.php` — bounded tenant query and projection only.

### Refund recovery prerequisite and adapter

- `database/migrations/2026_08_16_000001_add_recovery_lifecycle_to_order_refunds_table.php` — add current recovery/responsibility fields while preserving immutable failure evidence on the existing Refund record.
- `app/Models/OrderRefund.php` — expose casts, bounded constants, and replacement linkage for recovery state.
- `app/Services/OrderRefundRecoveryService.php` — controlled, idempotent recovery transitions and current responsibility, following the existing top-level Refund service convention.
- `app/Services/OwnerActionCenter/Adapters/FailedRefundAttentionAdapter.php` — project only materially unresolved, legitimately unowned failed recoveries.

### Logistics prerequisite and adapter

- `app/Support/Logistics/LogisticsResponsibility.php` — immutable current-state responsibility projection.
- `app/Services/Logistics/LogisticsResponsibilityProjection.php` — side-effect-free, bulk-safe classification from authoritative leg, assignment, incident, retry, return, and policy state.
- `app/Services/OwnerActionCenter/Adapters/UnownedLogisticsFailureAttentionAdapter.php` — project only exhausted, material, unowned failures.

### HTTP and frontend

- `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php` — validate bucket and bucket-scoped filters.
- `app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php` — request separate bounded summaries from the shared service.
- `resources/js/types/ownerActionCenter.ts` — shared two-bucket serialized contract.
- `resources/js/components/owner-action-center/OwnerAttentionList.tsx` — compact operational rows for decisions and exceptions.
- `resources/js/components/owner-action-center/OwnerActionCenterAvailability.tsx` — bucket-specific healthy, partial, unavailable, and empty states.
- `resources/js/Pages/ShopOwner/ActionCenter.tsx` — dominant bucket tabs, secondary source filters, independent pagination, and refresh.
- `resources/js/Pages/ShopOwner/Dashboard.tsx` — independent bounded Home summaries.

## Gate A — Shared Framework Evolution

### Task 1: Characterize Phase 3A and Compliance boundaries

**Files:**

- Create: `tests/Feature/ShopOwner/ActionCenter/PhaseThreeBCharacterizationTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php`
- Reference: `app/Support/OwnerActionCenter/OwnerAttentionItem.php`
- Reference: `app/Services/OwnerActionCenter/OwnerActionCenterService.php`
- Reference: `app/Services/ShopDocumentValidityService.php`
- Reference: `app/Services/ShopDocumentReminderService.php`
- Reference: `app/Models/ShopDocument.php`

- [ ] **Step 1: Write Phase 3A serialization and ordering characterization**

Lock the current four adapter identities, `needs_my_decision` behavior, relative priority ordering, coverage counts, pagination, destination links, partial failures, and all-source failure behavior before changing shared contracts.

- [ ] **Step 2: Write Compliance lifecycle characterization**

Cover current approved reviewer-verified dated rows, non-current rows, unapproved rows, non-expiring rows, malformed dated metadata, conflicting current rows, valid pending successors, and contradictory successor responsibility. Assert current broad validity and reminder behavior without introducing Action Center expectations yet.

- [ ] **Step 3: Run the baseline**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeBCharacterizationTest.php tests/Unit/ShopDocumentValidityServiceTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php --compact
```

Expected: PASS against current behavior. If characterization reveals a tenant or lifecycle defect, stop and record it before contract migration.

- [ ] **Step 4: Commit the baseline**

```powershell
git add -- tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeBCharacterizationTest.php
git commit -m "test: characterize phase 3b source boundaries"
```

### Task 2: Evolve the immutable Action Center contracts atomically

**Files:**

- Modify: `app/Contracts/OwnerActionCenter/OwnerAttentionAdapter.php`
- Modify: `app/Support/OwnerActionCenter/OwnerAttentionItem.php`
- Modify: `app/Support/OwnerActionCenter/OwnerAttentionQuery.php`
- Modify: `app/Support/OwnerActionCenter/OwnerActionCenterResult.php`
- Modify: `app/Services/OwnerActionCenter/Adapters/OrderRefundAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/Adapters/RepairRefundAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/Adapters/ExpenseAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/Adapters/PurchaseRequestAttentionAdapter.php`
- Modify: `resources/js/types/ownerActionCenter.ts`
- Modify: `tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/*AttentionAdapterTest.php`

- [ ] **Step 1: Write failing contract tests**

Require every item and adapter to emit explicit metadata:

```text
primary_bucket
waiting_on
owner_action_required
coverage_source
```

Validate these combinations:

```text
needs_my_decision + shop_owner + true
urgent_exceptions + none + false
waiting_on_others + bounded actor/team + false  (reserved only)
```

Reject bucket inference, invalid coverage/bucket combinations, and duplicate identity. Add `compliance_document` to source types, `compliance` and `logistics` to coverage vocabulary, and `compliance_documents`, `failed_refunds`, and `unowned_logistics_failures` to adapter-key vocabulary.

- [ ] **Step 2: Write the priority compatibility test**

Assert no PHP serialization or TypeScript union retains `urgent`, `critical` is the sole highest tier, `critical > high > normal > low`, and existing Phase 3A fixtures retain identical relative order and equivalent visual severity.

- [ ] **Step 3: Run to verify RED**

```powershell
php artisan test tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php --compact
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
```

Expected: FAIL on explicit metadata and the new `critical` vocabulary.

- [ ] **Step 4: Implement the minimal explicit DTO contract**

Keep `OwnerAttentionItem` readonly and request-scoped. Constructor arguments become explicit rather than hidden defaults:

```php
public string $primaryBucket,
public string $waitingOn,
public bool $ownerActionRequired,
public string $coverageSource,
```

Each Phase 3A adapter passes `needs_my_decision`, `shop_owner`, `true`, and its existing coverage family. Do not expose Phase 3C rows.

- [ ] **Step 5: Replace `urgent` with `critical` in one coordinated change**

Update PHP validation and ranking, all existing adapter emissions, TypeScript unions, labels, fixtures, and tests. Do not add a compatibility alias.

- [ ] **Step 6: Re-run and commit**

```powershell
php artisan test tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php tests/Feature/ShopOwner/ActionCenter --compact
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
git add -- app/Contracts/OwnerActionCenter app/Support/OwnerActionCenter app/Services/OwnerActionCenter/Adapters resources/js/types/ownerActionCenter.ts tests/Unit/Support/OwnerActionCenter tests/Unit/Services/OwnerActionCenter tests/Feature/ShopOwner/ActionCenter resources/js/Pages/ShopOwner/__tests__
git commit -m "refactor: make owner attention classification explicit"
```

Expected: PASS with no serialized `priority_tier=urgent`.

### Task 3: Add bucket-scoped registry and coordinator reads

**Files:**

- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Modify: `app/Services/OwnerActionCenter/OwnerActionCenterService.php`
- Modify: `app/Support/OwnerActionCenter/OwnerAttentionQuery.php`
- Modify: `app/Support/OwnerActionCenter/OwnerActionCenterResult.php`
- Modify: `config/owner_action_center.php`
- Modify: `tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php`

- [ ] **Step 1: Write failing two-bucket coordinator tests**

Cover explicit registry resolution by `(bucket, coverage)`, duplicate suppression within a bucket, independent counts, independent pagination, bucket change resetting page to 1, invalid filter rejection, healthy-empty default decisions remaining selected, and no mixed paginated queue.

- [ ] **Step 2: Write failure isolation tests**

Prove one exception adapter failure cannot degrade the decision bucket, all enabled exception adapters failing yields exception-unavailable rather than zero, and intentionally disabled/blocked adapters are absent rather than failed.

- [ ] **Step 3: Run to verify RED**

```powershell
php artisan test tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php --compact
```

- [ ] **Step 4: Implement explicit bucket reads**

Use one private read path and thin methods accepting `OwnerAttentionQuery` with `bucket`, `coverage`, `page`, and `perPage`. Keep ordering shared, but calculate items, health, counts, and pages only for the selected bucket. Home calls the same path once per enabled bucket with the configured home limit.

- [ ] **Step 5: Extend narrow configuration**

Add this shape without editing `.env`:

```php
'buckets' => [
    'urgent_exceptions' => [
        'enabled' => (bool) env('SHOP_OWNER_ACTION_CENTER_URGENT_EXCEPTIONS_ENABLED', false),
        'coverage' => [
            'compliance' => (bool) env('SHOP_OWNER_ACTION_CENTER_COMPLIANCE_ENABLED', false),
            'refunds' => (bool) env('SHOP_OWNER_ACTION_CENTER_FAILED_REFUNDS_ENABLED', false),
            'logistics' => (bool) env('SHOP_OWNER_ACTION_CENTER_LOGISTICS_EXCEPTIONS_ENABLED', false),
        ],
    ],
],
```

Do not add expiry days, failure limits, or logistics retry thresholds here.

- [ ] **Step 6: Re-run and commit Gate A**

```powershell
php artisan test tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter tests/Feature/ShopOwner/ActionCenter --compact
git add -- app/Services/OwnerActionCenter app/Support/OwnerActionCenter config/owner_action_center.php tests/Unit/Services/OwnerActionCenter tests/Feature/ShopOwner/ActionCenter
git commit -m "feat: support bucket scoped owner attention reads"
```

Expected: PASS. Gate A is complete only when Phase 3A remains unchanged and the atomic vocabulary migration evidence is recorded.

## Gate B — Compliance Domain and Adapter

### Task 4: Centralize the Compliance expiry-window policy

**Files:**

- Modify: `app/Services/ShopDocumentValidityService.php`
- Modify: `app/Services/ShopDocumentReminderService.php`
- Modify: `tests/Unit/ShopDocumentValidityServiceTest.php`
- Modify: `tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php`

- [ ] **Step 1: Write failing fixed-date boundary tests**

Using `config('app.shop_timezone')`, cover `31`, `30`, `8`, `7`, `1`, `0`, and negative days plus non-expiring and metadata-unverified states. Preserve the existing broad `classify()` outputs for current callers.

- [ ] **Step 2: Write reminder-parity tests**

Assert reminder delivery remains exact at 30/7/0 and does not fire on 29, 8, 6, 1, or expired days. Assert the Action Center-facing classifier exposes continuous windows without sending notifications.

- [ ] **Step 3: Run to verify RED**

```powershell
php artisan test tests/Unit/ShopDocumentValidityServiceTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php --compact
```

- [ ] **Step 4: Implement one side-effect-free boundary source**

Add bounded constants and a precise method on `ShopDocumentValidityService`, following existing naming conventions:

```php
public const RENEWAL_WINDOW_DAYS = 30;
public const URGENT_WINDOW_DAYS = 7;

public function expiryWindow(ShopDocument $document, ?CarbonImmutable $today = null): string;
public function milestoneDays(): array; // [30, 7, 0]
```

Keep `classify()` as the compatible broad projection. Update `ShopDocumentReminderService` to consume `milestoneDays()` and shared date normalization, while retaining delivery and deduplication responsibility in the reminder service.

- [ ] **Step 5: Re-run and commit**

```powershell
php artisan test tests/Unit/ShopDocumentValidityServiceTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php tests/Feature/ShopDocuments --compact
git add -- app/Services/ShopDocumentValidityService.php app/Services/ShopDocumentReminderService.php tests/Unit/ShopDocumentValidityServiceTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php
git commit -m "refactor: centralize compliance expiry windows"
```

### Task 5: Implement the Compliance Document adapter

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/ComplianceDocumentAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/ComplianceDocumentAttentionAdapterTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php`

- [ ] **Step 1: Write failing inclusion and exclusion tests**

Assert tenant scope, current approved reviewer-verified dated state, source-owned window qualification, no owner decision, and no valid successor responsibility. Normal exclusions are non-current, unapproved, non-expiring, outside-window, and valid pending successor rows.

- [ ] **Step 2: Write failing domain-health tests**

Assert current-approved rows with missing reviewer metadata, malformed dated expiry, conflicting current versions, broken successor chains, contradictory renewal responsibility, and unclassifiable legacy state contribute no item and emit only a bounded health reason.

- [ ] **Step 3: Write projection tests**

Assert:

```text
source_type=compliance_document
coverage_source=compliance
adapter_key=compliance_documents
category=document_expiry
primary_bucket=urgent_exceptions
waiting_on=none
owner_action_required=false
destination=/shop-owner/settings/policies-compliance
```

Verify priority/materiality mappings, `urgency_at=expires_on`, and `actionable_since` as the later local start-of-day of window entry or current reviewer verification. Identity must include the immutable current `ShopDocument` ID; replacement gets a new key.

- [ ] **Step 4: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/ComplianceDocumentAttentionAdapterTest.php --compact
```

- [ ] **Step 5: Implement the bounded query and projection**

Use tenant-scoped SQL predicates and a bounded successor existence check. Select only safe fields. Do not load document history, storage paths, checksums, or evidence contents. Do not call notification delivery.

- [ ] **Step 6: Run focused regressions and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/ComplianceDocumentAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php tests/Feature/ShopDocuments tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php --compact
git add -- app/Services/OwnerActionCenter/Adapters/ComplianceDocumentAttentionAdapter.php app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php tests/Feature/ShopOwner/ActionCenter
git commit -m "feat: surface compliance document exceptions"
```

### Task 6: Build the two-bucket Home and compact queue UI

**Files:**

- Modify: `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php`
- Modify: `app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php`
- Modify: `resources/js/types/ownerActionCenter.ts`
- Modify: `resources/js/components/owner-action-center/OwnerAttentionList.tsx`
- Modify: `resources/js/components/owner-action-center/OwnerActionCenterAvailability.tsx`
- Modify: `resources/js/Pages/ShopOwner/ActionCenter.tsx`
- Modify: `resources/js/Pages/ShopOwner/Dashboard.tsx`
- Modify: `resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx`
- Modify: `resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php`

- [ ] **Step 1: Write failing route tests**

Validate `bucket` plus bucket-specific `source`, independent pages, default `needs_my_decision`, page reset on bucket change, healthy-empty decisions remaining selected, and unavailable-default fallback only when the default bucket is genuinely unsupported/unavailable.

- [ ] **Step 2: Write failing frontend tests**

Cover dominant bucket tabs and count badges, bucket-scoped source filters only when more than one source participates, separate Home summaries, top 3–5 per active bucket, compact rows, explicit source badge, textual priority, age/due context, semantic `Open workflow` link, normal refresh preserving valid query state, conventional pagination, calm partial notices, and absence of all mutation/dismiss controls. The Compliance-only rollout omits the redundant source-filter row.

- [ ] **Step 3: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php --compact
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
```

- [ ] **Step 4: Implement server-owned bucket state**

The controller validates bounded query parameters and returns the normalized selected bucket/page. React renders server results and never reclassifies, recounts, or globally paginates mixed buckets.

- [ ] **Step 5: Implement the operational queue**

Use existing SoleSpace blue/neutral tokens, restrained semantic accents, native links/buttons, visible focus, dark mode, and responsive stacking. Rows—not oversized tiles and not a dense ERP table—are the permanent Action Center visual language.

- [ ] **Step 6: Re-run and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php --compact
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
git add -- app/Http/Controllers/ShopOwner/OwnerActionCenterController.php app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php resources/js/types/ownerActionCenter.ts resources/js/components/owner-action-center resources/js/Pages/ShopOwner/ActionCenter.tsx resources/js/Pages/ShopOwner/Dashboard.tsx resources/js/Pages/ShopOwner/__tests__ tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php
git commit -m "feat: add urgent exceptions action center UI"
```

### Task 7: Pass the Compliance release gate

**Files:**

- Create: `docs/shop-owner-phase-3b-rollout-guide.md`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php`
- Modify: `docs/ai-learning-log.md` only for a durable repository-wide lesson discovered during implementation

- [ ] **Step 1: Add security, failure, and observability evidence**

Prove cross-shop documents never appear, sensitive fields never serialize/log, Compliance failure leaves decisions healthy, disabled blocked sources stay hidden, all exception sources unavailable is not zero, and disabling `urgent_exceptions` returns exactly to Phase 3A.

- [ ] **Step 2: Add query evidence**

Assert bounded counts/candidates, SQL tenant filtering, no N+1 predecessor/successor/reviewer queries, and identical Home/full-queue contracts under fixed state.

- [ ] **Step 3: Run Gate B checks**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter tests/Unit/ShopDocumentValidityServiceTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php tests/Feature/ShopDocuments --compact
pnpm run test:frontend
pnpm run build
```

Expected: PASS. The repository has no committed standalone TypeScript type-check or lint script; do not claim either was run.

- [ ] **Step 4: Perform browser verification**

Verify desktop/mobile, light/dark mode, 200% zoom, keyboard/focus behavior, healthy/empty/partial/unavailable states, decision and exception tabs, source filters, refresh, pagination, semantic links, and no horizontal overflow.

- [ ] **Step 5: Write first-stage rollout instructions**

Document safe flag order, stable shop allowlist, Compliance-only coverage, blocked Refund/Logistics status, expected telemetry, rollback, and the distinction between first-stage releasable and full declared Phase 3B completion.

- [ ] **Step 6: Commit Gate B evidence**

```powershell
git add -- docs/shop-owner-phase-3b-rollout-guide.md tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php docs/ai-learning-log.md
git commit -m "docs: record phase 3b compliance rollout evidence"
```

Stage `docs/ai-learning-log.md` only if changed. Stop here for first-stage release approval. Do not begin Gate C automatically.

## Gate C — Refund Recovery Domain Prerequisite

### Task 8: Add authoritative Refund recovery lifecycle

**Files:**

- Create: `database/migrations/2026_08_16_000001_add_recovery_lifecycle_to_order_refunds_table.php`
- Modify: `app/Models/OrderRefund.php`
- Create: `app/Services/OrderRefundRecoveryService.php`
- Create: `tests/Feature/OrderRefundRecoveryLifecycleTest.php`
- Modify only at existing execution boundaries: `app/Services/OrderRefundService.php`
- Modify only at existing execution boundaries: `app/Services/PaymongoRefundService.php`

- [ ] **Step 1: Write failing schema and lifecycle tests**

Define current recovery state on the existing failed Refund record with bounded fields:

```text
status = unresolved | in_progress | resolved | superseded
responsible_party = finance | payment_recovery | none
attempt_count
last_attempted_at
resolved_at
resolution
replacement_refund_id
```

Require an index on `(shop_owner_id, recovery_status, recovery_responsible_party)`, a self-link to an optional replacement refund, casts for recovery timestamps/counts, and model constants matching repository convention. Preserve the original refund's `failed_at` and `failure_reason` unchanged.

- [ ] **Step 2: Write failing transition tests**

Cover idempotent creation on authoritative execution failure, controlled `claim`, `recordRetry`, `replace`, and `resolve` operations, invalid/stale transition rejection, retry evidence, replacement linkage, owner-decision precedence, and terminal exit.

- [ ] **Step 3: Run to verify RED**

```powershell
php artisan test tests/Feature/OrderRefundRecoveryLifecycleTest.php --compact
```

- [ ] **Step 4: Generate the focused migration**

```powershell
php artisan make:migration add_recovery_lifecycle_to_order_refunds_table --table=order_refunds
```

Expected: one timestamped migration. Use the generated timestamp in place of the planned `2026_08_16_000001` placeholder if Artisan selects a different available name. Keep schema changes reversible and separate from data reconciliation.

- [ ] **Step 5: Implement the minimal domain service**

All mutations occur in transactions with current-state validation and locking. The service answers current unresolved/material/responsibility state side-effect free for readers; Action Center code never writes recovery state.

- [ ] **Step 6: Connect existing refund execution failure/success boundaries**

Call the recovery service only where `OrderRefundService`/gateway execution already authoritatively records failure, retry, replacement, or success. Do not infer from notifications or historical `failed` rows. Existing authorization and payment safeguards remain unchanged.

- [ ] **Step 7: Run refund regressions and commit Gate C**

```powershell
php artisan test tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Unit/Refund tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php --compact
git add -- database/migrations/*_add_recovery_lifecycle_to_order_refunds_table.php app/Models/OrderRefund.php app/Services/OrderRefundRecoveryService.php app/Services/OrderRefundService.php app/Services/PaymongoRefundService.php tests/Feature/OrderRefundRecoveryLifecycleTest.php
git commit -m "feat: add authoritative refund recovery lifecycle"
```

Stage optional services only if changed. Stop if current failure boundaries cannot be integrated without changing payment semantics; update the focused design before proceeding.

## Gate D — Failed Refund Adapter

### Task 9: Project materially unowned Failed Refunds

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/FailedRefundAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/FailedRefundAttentionAdapterTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php`
- Modify: `docs/shop-owner-phase-3b-rollout-guide.md`

- [ ] **Step 1: Write failing classification tests**

Prove:

```text
owner approval required                 -> Phase 3A only
unresolved + legitimate Finance owner  -> omitted until Phase 3C
material unresolved + no owner         -> Urgent Exceptions only
resolved/superseded/replaced            -> no item
ambiguous recovery state                -> domain health, no item
```

- [ ] **Step 2: Write tenant, identity, exit, and bounded-query tests**

Use `coverage_source=refunds`, `adapter_key=failed_refunds`, a distinct failure-recovery category, current failed refund ID identity, owner-safe Refund workflow link, and domain-owned materiality/recovery timestamps.

- [ ] **Step 3: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/FailedRefundAttentionAdapterTest.php --compact
```

- [ ] **Step 4: Implement the read-only adapter and registry entry**

Query only current authoritative recovery records, exclude owner decisions and legitimate recovery ownership in SQL where practical, and contribute no item on ambiguous state. Do not treat a historical `failed` status alone as active.

- [ ] **Step 5: Run readiness and commit Gate D**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/FailedRefundAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Unit/Refund --compact
git add -- app/Services/OwnerActionCenter/Adapters/FailedRefundAttentionAdapter.php app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php tests/Feature/ShopOwner/ActionCenter docs/shop-owner-phase-3b-rollout-guide.md
git commit -m "feat: surface unowned failed refund exceptions"
```

Gate D makes Failed Refund coverage independently releasable. Stop for rollout approval before enabling its configuration.

## Gate E — Logistics Responsibility Prerequisite

### Task 10: Add a current Logistics responsibility projection

**Files:**

- Create: `app/Support/Logistics/LogisticsResponsibility.php`
- Create: `app/Services/Logistics/LogisticsResponsibilityProjection.php`
- Create: `tests/Unit/Services/Logistics/LogisticsResponsibilityProjectionTest.php`
- Modify only if characterization proves a missing shared rule: `app/Services/Logistics/ShipmentLegService.php`
- Modify only if characterization proves a missing shared rule: `app/Services/Logistics/LogisticsActorPolicy.php`

- [ ] **Step 1: Write failing responsibility matrix tests**

Using current shipment legs, active assignments, rider status, dispatcher-resolution state, returns, incidents, retry limits, and terminal states, require this immutable result:

```php
new LogisticsResponsibility(
    ownerActionRequired: bool,
    deterministicResponsibleParty: ?string,
    recoveryPathActive: bool,
    recoveryPathExhausted: bool,
    materialExceptionActive: bool,
    healthReason: ?string,
);
```

- [ ] **Step 2: Cover stale and contradictory states**

Inactive rider, cancelled/completed assignment, superseded leg, completed batch, stale overdue event, contradictory active assignments, invalid return chain, and indeterminate responsibility must not establish legitimate current ownership or a misleading exception.

- [ ] **Step 3: Run to verify RED**

```powershell
php artisan test tests/Unit/Services/Logistics/LogisticsResponsibilityProjectionTest.php --compact
```

- [ ] **Step 4: Implement the side-effect-free bulk-safe projection**

Consume authoritative Logistics retry/return/resolution policy; do not copy numeric attempt thresholds into Action Center. Return bounded actor/team keys only for current legitimate responsibility. Do not assign riders, alter legs, or emit events.

- [ ] **Step 5: Run Logistics regressions and commit Gate E**

```powershell
php artisan test tests/Unit/Services/Logistics/LogisticsResponsibilityProjectionTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/ReturnToShopTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php --compact
git add -- app/Support/Logistics/LogisticsResponsibility.php app/Services/Logistics/LogisticsResponsibilityProjection.php app/Services/Logistics/ShipmentLegService.php app/Services/Logistics/LogisticsActorPolicy.php tests/Unit/Services/Logistics/LogisticsResponsibilityProjectionTest.php
git commit -m "feat: define logistics recovery responsibility"
```

Stage optional Logistics files only if changed. Stop if current persisted state cannot distinguish legitimate responsibility from ambiguity; do not let the adapter guess.

## Gate F — Unowned Logistics Failure Adapter

### Task 11: Project exhausted unowned Logistics failures

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/UnownedLogisticsFailureAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/UnownedLogisticsFailureAttentionAdapterTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php`
- Modify: `docs/shop-owner-phase-3b-rollout-guide.md`

- [ ] **Step 1: Write failing classification tests**

Prove owner return confirmation maps to decisions only, current rider/dispatcher recovery is omitted until Phase 3C, exhausted material unowned failure maps to Urgent Exceptions only, and retry/return/completion/cancellation/reassignment causes reclassification or exit on the next read.

- [ ] **Step 2: Write bounded-query and safety tests**

Assert same-shop scope, current-state joins/eager loads, no historical-event-only inclusion, no N+1 projection, `coverage_source=logistics`, `adapter_key=unowned_logistics_failures`, stable leg/category identity, safe Logistics destination, and no private proof/evidence data.

- [ ] **Step 3: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/UnownedLogisticsFailureAttentionAdapterTest.php --compact
```

- [ ] **Step 4: Implement the adapter**

Retrieve bounded candidate legs, evaluate them through the bulk-safe domain projection, emit only the exact 3B predicate, and health-report contradictory states without presenting them as exceptions.

- [ ] **Step 5: Run readiness and commit Gate F**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/UnownedLogisticsFailureAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php tests/Unit/Services/Logistics/LogisticsResponsibilityProjectionTest.php tests/Feature/Logistics --compact
git add -- app/Services/OwnerActionCenter/Adapters/UnownedLogisticsFailureAttentionAdapter.php app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php tests/Feature/ShopOwner/ActionCenter docs/shop-owner-phase-3b-rollout-guide.md
git commit -m "feat: surface unowned logistics exceptions"
```

Gate F makes Logistics coverage independently releasable. Stop for rollout approval before enabling its configuration.

## Gate G — Complete Three-Source Verification

### Task 12: Prove full declared Phase 3B coverage

**Files:**

- Modify: `tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php`
- Modify: `resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx`
- Modify: `resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx`
- Modify: `docs/shop-owner-phase-3b-rollout-guide.md`
- Modify: `docs/ai-learning-log.md` only for durable lessons

- [ ] **Step 1: Add adversarial cross-source ordering tests**

Interleave Compliance, Failed Refund, and Logistics priorities across pages; test deterministic tie-breakers, one source dominating early pages, stable page boundaries, duplicate concern suppression, source filters, and maximum page/candidate ceilings.

- [ ] **Step 2: Add full taxonomy tests**

For the same concern, prove owner decision outranks other-party responsibility, other-party responsibility outranks unowned exception, and no concern appears in more than one primary bucket. Confirm Phase 3B still emits no `waiting_on_others` rows.

- [ ] **Step 3: Run the required sequential review stack**

Record:

1. simplify with `@ponytail`;
2. Standards review against repository/Laravel/Inertia conventions;
3. Spec review against every Phase 3B acceptance criterion;
4. TS/TSX clean-code and React performance review with `@vercel-react-best-practices`;
5. assumptions/minimum-scope review with `@karpathy-guidelines`;
6. code-splitting review—expected `N/A` unless measured bundle behavior justifies it;
7. gauge improvements using query counts, bounds, tests, and build evidence;
8. security review for tenancy, sensitive evidence, recovery transitions, inputs, links, isolation, and logs;
9. verification-before-completion evidence review.

Perform reviews sequentially. Do not invoke the parallel `code-review` skill unless the user separately approves the optional review gate.

- [ ] **Step 4: Perform reuse and dead-code audits**

Confirm reuse of Phase 3A coordinator/contracts, Phase 2 shell/rollout, existing domain mutation services, Compliance lifecycle, Logistics policy, canonical pages, tokens, and Inertia patterns. Remove only dead code created by this change. Scan for duplicate thresholds, legacy `urgent`, hidden bucket inference, Action Center mutations, unsafe URLs, sensitive logs, stale placeholders, and orphaned test helpers.

- [ ] **Step 5: Run final backend gates**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter tests/Unit/ShopDocumentValidityServiceTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Unit/Services/Logistics/LogisticsResponsibilityProjectionTest.php --compact
composer test
```

Expected: focused tests PASS. Run `composer test` when practical and report its result separately without hiding unrelated failures.

- [ ] **Step 6: Run final frontend/build gates**

```powershell
pnpm run test:frontend
pnpm run build
git diff --check
```

Expected: PASS. Do not claim standalone lint/type-check evidence because the repository does not provide those scripts.

- [ ] **Step 7: Perform full controlled browser verification**

Verify Compliance-only, Compliance+Refund, and all-three coverage; healthy empty; partial source failure; all exception sources unavailable; Phase 3B disabled; Phase 3A unaffected; separate Home summaries; independent queue pagination; refresh; accessibility; responsive layouts; and authoritative workflow exits/reclassification.

- [ ] **Step 8: Finalize rollout and completion evidence**

Record exact flags, enable order, stable shop cohort, per-adapter readiness evidence, query counts, security checks, rollback, known limits, and separate release status for each gate. State that full declared Phase 3B is complete only when all three adapters are ready and safely enabled for the approved cohort.

- [ ] **Step 9: Commit Gate G evidence**

```powershell
git add -- tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php tests/Feature/ShopOwner/ActionCenter resources/js/Pages/ShopOwner/__tests__ docs/shop-owner-phase-3b-rollout-guide.md docs/ai-learning-log.md
git commit -m "test: verify complete phase 3b exception coverage"
```

Stage `docs/ai-learning-log.md` only if changed.

## Final acceptance gates

### First Phase 3B rollout stage releasable

Do not enable Compliance for production until Gates A and B prove:

- Phase 3A behavior and relative ordering are unchanged;
- `critical` is the sole highest priority token across PHP, serialized payloads, TypeScript, tests, and UI;
- one Compliance policy owns expiry boundaries while reminders remain exact milestones and Action Center qualification remains continuous;
- normal lifecycle exclusions do not mark the adapter unhealthy;
- invalid current-approved lifecycle data is health-reported without leaking evidence;
- Compliance identity uses the immutable current document version;
- Home and full Action Center use separate bucket summaries/queues from the same coordinator;
- the compact queue is accessible and contains no mutation or acknowledgement controls;
- Compliance failure does not affect decisions; and
- disabling Phase 3B returns exactly to Phase 3A without data rollback.

### Failed Refund coverage releasable

Do not enable Failed Refunds until Gates C and D prove authoritative unresolved recovery, current responsibility, idempotent retry/replacement/resolution, preserved failure evidence, tenant safety, materiality, exhaustive exits, and no duplication with Refund decisions.

### Logistics coverage releasable

Do not enable Unowned Logistics Failures until Gates E and F prove current legitimate responsibility, active/exhausted recovery paths, source-owned materiality, owner-action precedence, ambiguity detection, bounded reads, and reassignment/retry/return/terminal exits.

### Full declared Phase 3B complete

Do not call full declared Phase 3B complete until Gate G proves all three enabled sources use the same explicit bucket contract, deterministic ordering, independent pagination, accurate degradation, safe rollback, and mutually exclusive classification. Phase 3 remains open for the separately designed Phase 3C `Waiting on Others` projection.
