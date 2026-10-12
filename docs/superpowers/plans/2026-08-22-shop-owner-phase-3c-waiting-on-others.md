# Shop Owner Phase 3C Waiting on Others Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `Waiting on Others` as the third Shop Owner Action Center bucket for materially important Compliance, Refund, and Logistics work whose next step belongs to another legitimate actor or team.

**Architecture:** Extend the existing immutable `OwnerAttentionItem`, bucket-aware adapter registry, and request-time `OwnerActionCenterService`; do not create another coordinator, task table, workflow engine, or mutation surface. Dedicated Phase 3C adapters consume authoritative Compliance lifecycle policy, Refund recovery responsibility, and Logistics responsibility projection, while Home and `/shop-owner/action-center` present independent bucket summaries and queues.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, PHPUnit, Inertia 2, React 18, TypeScript 5.7, Vitest, Tailwind CSS 4, pnpm, Playwright/local browser verification.

---

## Source specifications

- `docs/superpowers/specs/2026-08-15-shop-owner-phase-3-action-center-master-design.md`
- `docs/superpowers/specs/2026-08-15-shop-owner-phase-3a-owner-decisions-design.md`
- `docs/superpowers/specs/2026-08-16-shop-owner-phase-3b-material-exceptions-design.md`
- `docs/superpowers/specs/2026-08-22-shop-owner-phase-3c-waiting-on-others-design.md`

The focused Phase 3C design is authoritative. This is the single implementation plan for all approved Phase 3C work.

## Required implementation skills

- Use `@superpowers:test-driven-development` before implementation changes.
- Use `@laravel-best-practices` for migrations, Eloquent queries, services, controllers, authorization, and tests.
- Use `@ui-ux-pro-max`, `@design-system`, and `@ui-styling` for the third-bucket UI.
- Use `@vercel-react-best-practices` for React/TSX changes and `@webapp-testing` for browser-visible verification.
- Use `@ponytail` and `@karpathy-guidelines` for the sequential simplification and scope review.
- Use `@security-review` for tenant scoping, sensitive Compliance and Refund data, destinations, and telemetry.
- Use `@superpowers:verification-before-completion` before any completion claim.

Repository policy requires one main agent and sequential execution. Do not dispatch implementation subagents unless repository policy is explicitly changed.

## Non-negotiable boundaries

- Keep one shared Action Center coordinator and one request-time live-read model.
- Do not add Action Center persistence, jobs, polling, WebSockets, SSE, notification coupling, or optimistic resolution.
- Domain workflows remain the only execution surfaces. Do not add Approve, Reject, Remind, Reassign, Escalate, Dismiss, Acknowledge, Snooze, or Resolve controls.
- Preserve classification precedence: owner decision, then legitimate other-party responsibility, then material unowned exception, otherwise no item.
- A concern can appear in only one primary bucket at a time.
- Aging may change source-owned priority but must not change responsibility classification.
- Do not invent materiality thresholds in the coordinator, adapters, or `config/owner_action_center.php`.
- Use role/team labels only; do not expose reviewer, employee, rider, or dispatcher names in the initial UI.
- Use always-on committed thesis defaults. Do not edit `.env` and do not add an allowlist requirement.
- Preserve Phase 3A and Phase 3B inclusion, ordering, filters, pagination, failure isolation, and authorization.
- Remaining approval families are Phase 4 work, not Phase 3C.
- Preserve unrelated working-tree changes and generated artifacts. Stage only the exact paths listed for each commit.

## Execution preflight

Before Task 1, run `git status --short` and inspect `git diff` for every file this plan will modify. This worktree already contains approved, uncommitted always-on rollout changes and generated build/cache artifacts. Do not reset, delete, or silently absorb them. For an overlapping dirty file such as `config/owner_action_center.php` or the Phase 3 master design, either commit the already-approved prerequisite change separately first or use `git add -p -- <path>` so each Phase 3C commit contains only its intended hunks. Never stage `public/build/assets/`, `storage/framework/cache/`, `.env`, or unrelated owner-shell files as part of this plan.

## Locked file structure

### Shared Action Center contract and HTTP composition

- `app/Support/OwnerActionCenter/OwnerAttentionItem.php` — add bounded Phase 3C adapter and responsibility keys.
- `app/Support/OwnerActionCenter/OwnerAttentionQuery.php` — restrict Waiting filters to Compliance, Refunds, and Logistics.
- `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php` — resolve configured Waiting adapters instead of returning an empty registry.
- `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php` — accept and summarize the third bucket.
- `app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php` — provide a bounded Waiting Home summary.
- `config/owner_action_center.php` — enable the third bucket and its approved adapters with no domain thresholds.

### Compliance

- `app/Services/OwnerActionCenter/Adapters/PendingComplianceRenewalAttentionAdapter.php` — project material pending renewals waiting on Compliance Review.

### Refund responsibility evidence and adapters

- `database/migrations/2026_08_22_000001_add_recovery_assigned_at_to_refunds.php` — add nullable assignment evidence to Order and Repair/POS refunds.
- `app/Models/OrderRefund.php` and `app/Models/PosRefund.php` — expose the assignment timestamp.
- `app/Services/OrderRefundRecoveryService.php` and `app/Services/RepairRefundRecoveryService.php` — record assignment boundaries atomically without rewriting them on retries.
- `app/Console/Commands/ReportPhaseThreeCRefundAssignmentGaps.php` — report legacy in-progress assignments that cannot be safely dated.
- `app/Services/OwnerActionCenter/Adapters/WaitingOrderRefundRecoveryAttentionAdapter.php` — project Order Refund recovery responsibility.
- `app/Services/OwnerActionCenter/Adapters/WaitingRepairRefundRecoveryAttentionAdapter.php` — project Repair/POS Refund recovery responsibility.

### Logistics

- `app/Services/OwnerActionCenter/Adapters/ActiveLogisticsRecoveryAttentionAdapter.php` — reuse `LogisticsResponsibilityProjection` for Rider/Dispatcher-owned recovery.

### Frontend

- `resources/js/types/ownerActionCenter.ts` — add Phase 3C adapter and responsibility unions.
- `resources/js/components/owner-action-center/OwnerAttentionList.tsx` — render `Waiting on: <role/team>` metadata.
- `resources/js/components/owner-action-center/OwnerActionCenterAvailability.tsx` — render Waiting healthy, partial, and unavailable states.
- `resources/js/Pages/ShopOwner/ActionCenter.tsx` — add the third tab and bucket-scoped filters.
- `resources/js/Pages/ShopOwner/Dashboard.tsx` — add the third independent Home summary, capped at three items.

## Gate A — Shared Contract and Third-Bucket Surfaces

### Task 1: Characterize Phase 3A and Phase 3B before shared changes

**Files:**

- Create: `tests/Feature/ShopOwner/ActionCenter/PhaseThreeCCharacterizationTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/PhaseThreeBCharacterizationTest.php`
- Reference: `app/Services/OwnerActionCenter/OwnerActionCenterService.php`
- Reference: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`

- [ ] **Step 1: Add fixed-state characterization tests**

Lock the current `needs_my_decision` and `urgent_exceptions` adapter keys, inclusion, stable identities, ordering, counts, filters, pagination, workflow links, healthy-zero behavior, partial failures, and all-source failures. Add a baseline assertion that `waiting_on_others` currently returns no adapters and is not rendered.

- [ ] **Step 2: Add mutually exclusive classification fixtures**

Create representative Refund, Compliance, and Logistics fixtures proving that current 3A decisions and 3B unowned exceptions do not overlap. These fixtures become the comparison baseline for later 3C adapters.

- [ ] **Step 3: Run the baseline**

Run:

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeBCharacterizationTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeCCharacterizationTest.php --compact
```

Expected: PASS against current behavior. Stop if characterization exposes an existing tenant, identity, or overlap defect.

- [ ] **Step 4: Commit the baseline**

```powershell
git add -- tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeBCharacterizationTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeCCharacterizationTest.php
git commit -m "test: characterize phase 3c action center baseline"
```

### Task 2: Extend the bounded DTO and query vocabulary

**Files:**

- Modify: `app/Support/OwnerActionCenter/OwnerAttentionItem.php`
- Modify: `app/Support/OwnerActionCenter/OwnerAttentionQuery.php`
- Modify: `resources/js/types/ownerActionCenter.ts`
- Modify: `tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php`

- [ ] **Step 1: Write failing contract tests**

Require these new bounded values:

```text
adapter_key:
  pending_compliance_renewals
  waiting_order_refund_recovery
  waiting_repair_refund_recovery
  active_logistics_recovery

waiting_on:
  payment_recovery
  rider
  dispatcher
```

Require `waiting_on_others + owner_action_required=false + waiting_on in {super_admin, finance, payment_recovery, rider, dispatcher}`. Reject `shop_owner`, `none`, unknown keys, and `owner_action_required=true` for this bucket. Restrict Waiting coverage filters to `all`, `compliance`, `refunds`, and `logistics`.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php --compact
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx
```

Expected: FAIL because the new keys and bucket-scoped coverage are not accepted yet.

- [ ] **Step 3: Implement the minimum shared vocabulary**

Keep the DTO readonly and request-scoped. Add only the approved constants/unions and query coverage map; do not add a new DTO, inferred bucket logic, personal identity, or persistence fields.

- [ ] **Step 4: Re-run and commit**

```powershell
php artisan test tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php --compact
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx
git add -- app/Support/OwnerActionCenter/OwnerAttentionItem.php app/Support/OwnerActionCenter/OwnerAttentionQuery.php resources/js/types/ownerActionCenter.ts tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php
git commit -m "feat: add waiting on others attention vocabulary"
```

Expected: PASS with no behavior change to existing buckets.

### Task 3: Register and compose the third bucket server-side

**Files:**

- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Modify: `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php`
- Modify: `app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php`
- Modify: `config/owner_action_center.php`
- Modify: `tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/PhaseThreeCCharacterizationTest.php`

- [ ] **Step 1: Write failing registry and route tests**

Assert that:

```text
bucket=waiting_on_others
source=all|compliance|refunds|logistics
→ validated server query
→ configured bucket-specific adapters only
```

Assert that `/shop-owner/action-center` still defaults to `needs_my_decision`, bucket changes reset invalid pagination, each bucket has an independent summary, and disabling only Waiting leaves 3A/3B unchanged.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeCCharacterizationTest.php --compact
```

Expected: FAIL because the registry currently returns no Waiting adapters and controllers recognize only two buckets.

- [ ] **Step 3: Generalize existing configuration lookup**

Replace the hard-coded registry/controller branch with a small bucket-aware lookup that preserves the Phase 3A root coverage compatibility and reads `buckets.waiting_on_others`. Do not create a generic feature-flag framework.

Configure committed defaults conceptually as:

```php
'home_limit' => 3,
'buckets' => [
    'waiting_on_others' => [
        'enabled' => true,
        'coverage' => [
            'compliance' => true,
            'refunds' => true,
            'logistics' => true,
        ],
    ],
],
```

The global Home limit becomes three to match the approved bounded Home contract for every bucket. Characterization must explicitly approve this presentation-only reduction from five; inclusion and full-queue behavior remain unchanged.

- [ ] **Step 4: Return third-bucket summaries**

Add `ownerWaitingOnOthers` to Home props and `waiting_on_others` to full-page bucket summaries. A disabled bucket is absent, a healthy empty bucket is present with zero, and an unavailable bucket is not presented as healthy zero.

- [ ] **Step 5: Re-run and commit**

```powershell
php artisan test tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeCCharacterizationTest.php --compact
git add -- app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php app/Http/Controllers/ShopOwner/OwnerActionCenterController.php app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeCCharacterizationTest.php
git add -p -- config/owner_action_center.php
git commit -m "feat: compose waiting on others bucket"
```

### Task 4: Add the third-bucket operational queue UI

**Files:**

- Modify: `resources/js/components/owner-action-center/OwnerAttentionList.tsx`
- Modify: `resources/js/components/owner-action-center/OwnerActionCenterAvailability.tsx`
- Modify: `resources/js/Pages/ShopOwner/ActionCenter.tsx`
- Modify: `resources/js/Pages/ShopOwner/Dashboard.tsx`
- Modify: `resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx`
- Modify: `resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx`

- [ ] **Step 1: Write failing UI tests**

Assert:

- three bucket tabs with independent counts;
- `Needs My Decision` remains the default when healthy and empty;
- Waiting filters are only All, Compliance, Refunds, and Logistics;
- changing bucket or source resets page to 1;
- Home renders at most three rows per bucket;
- Waiting rows show `Waiting on: Compliance Review|Finance|Payment Recovery|Rider|Dispatcher`;
- no personal name or forbidden mutation control is rendered;
- healthy empty, partial, all-failed, and disabled states use distinct copy.

- [ ] **Step 2: Run to verify RED**

```powershell
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
```

Expected: FAIL because only two tabs and summaries exist.

- [ ] **Step 3: Implement the compact UI**

Use one responsibility label map:

```ts
const waitingOnLabels = {
  super_admin: 'Compliance Review',
  finance: 'Finance',
  payment_recovery: 'Payment Recovery',
  rider: 'Rider',
  dispatcher: 'Dispatcher',
} as const;
```

Render structured rows, semantic Inertia links, textual priority, visible focus, existing SoleSpace blue, conventional pagination, and mobile stacking. Keep bucket tabs visually dominant over source filters. Do not add team filters or a mixed queue.

- [ ] **Step 4: Re-run and commit**

```powershell
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
git add -- resources/js/components/owner-action-center/OwnerAttentionList.tsx resources/js/components/owner-action-center/OwnerActionCenterAvailability.tsx resources/js/Pages/ShopOwner/ActionCenter.tsx resources/js/Pages/ShopOwner/Dashboard.tsx resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
git commit -m "feat: add waiting on others queue interface"
```

## Gate B — Compliance Renewal Review

### Task 5: Add the pending Compliance renewal adapter

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/PendingComplianceRenewalAttentionAdapter.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/PendingComplianceRenewalAttentionAdapterTest.php`
- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Modify: `config/owner_action_center.php`
- Reference: `app/Models/ShopDocument.php`
- Reference: `app/Services/ShopDocumentValidityService.php`

- [ ] **Step 1: Write fixed-time adapter tests**

Using the authoritative business timezone, test:

```text
31+ days → excluded
30–8 days + one pending successor → normal / medium
7–1 days + one pending successor → high / high
expires today or expired + one pending successor → critical / critical
pending successor outside material window → excluded
approved/rejected/withdrawn successor → exits Waiting
multiple or cross-tenant successors → health failure, no item
owner decision state → excluded from Waiting
```

Assert stable identity `compliance_document:<pending-id>:renewal_review_waiting`, `waiting_on=super_admin`, destination under `/shop-owner/settings/policies-compliance`, predecessor expiry as `urgency_at`, and the later of window opening/submission as `actionable_since`.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/PendingComplianceRenewalAttentionAdapterTest.php --compact
```

Expected: FAIL because the adapter is missing.

- [ ] **Step 3: Implement one bounded tenant query**

Select only current approved, reviewer-verified, dated predecessors inside the authoritative material window and their single valid pending successor. Reuse `ShopDocumentValidityService`; do not duplicate 30/7/0 thresholds or load document contents/storage paths.

- [ ] **Step 4: Register and verify isolation**

Register only under `waiting_on_others/compliance`. Make malformed lifecycle rows contribute a bounded health reason and no data. Confirm a Compliance adapter failure leaves Refund and Logistics adapters usable.

- [ ] **Step 5: Re-run and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/PendingComplianceRenewalAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/ComplianceDocumentAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeCCharacterizationTest.php --compact
git add -- app/Services/OwnerActionCenter/Adapters/PendingComplianceRenewalAttentionAdapter.php app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php tests/Feature/ShopOwner/ActionCenter/PendingComplianceRenewalAttentionAdapterTest.php
git add -p -- config/owner_action_center.php
git commit -m "feat: surface pending compliance renewal review"
```

## Gate C — Refund Responsibility Evidence and Waiting Adapters

### Task 6: Record authoritative Refund assignment boundaries

**Files:**

- Create: `database/migrations/2026_08_22_000001_add_recovery_assigned_at_to_refunds.php`
- Modify: `app/Models/OrderRefund.php`
- Modify: `app/Models/PosRefund.php`
- Modify: `app/Services/OrderRefundRecoveryService.php`
- Modify: `app/Services/RepairRefundRecoveryService.php`
- Modify: `tests/Feature/OrderRefundRecoveryLifecycleTest.php`
- Modify: `tests/Feature/RepairRefundRecoveryLifecycleTest.php`

- [ ] **Step 1: Write failing lifecycle evidence tests**

For both Order and Repair refunds, require:

```text
first claim → party + recovery_assigned_at written atomically
same-party idempotent claim → timestamp preserved
Finance ↔ Payment Recovery claim → new timestamp
retry → assignment timestamp preserved
resolve/supersede → historical assignment timestamp preserved
owner decision pending → claim rejected
terminal recovery → claim/retry rejected
failed_at and failure_reason → unchanged
```

Use explicit `CarbonImmutable` times so the assignment boundary is deterministic.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Feature/RepairRefundRecoveryLifecycleTest.php --compact
```

Expected: FAIL because `recovery_assigned_at` does not exist and responsibility changes are not recorded.

- [ ] **Step 3: Add the schema and model casts**

Add one nullable indexed timestamp to `order_refunds` and `pos_refunds`. Do not backfill from `updated_at`. Add the field to fillable/casts while retaining all existing recovery and immutable failure evidence.

- [ ] **Step 4: Evolve the existing atomic claim transition**

Keep `claim()` as the authoritative execution boundary:

```php
same party + in progress
→ idempotent, preserve recovery_assigned_at

different valid party + non-terminal failed recovery
→ update party and recovery_assigned_at together under row lock

retry
→ update attempt evidence only
```

Do not add a controller, route, or Action Center mutation. Preserve transaction locking, tenant-independent domain validation, owner-decision precedence, and terminal-state checks.

- [ ] **Step 5: Re-run and commit**

```powershell
php artisan test tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Feature/RepairRefundRecoveryLifecycleTest.php --compact
git add -- database/migrations/2026_08_22_000001_add_recovery_assigned_at_to_refunds.php app/Models/OrderRefund.php app/Models/PosRefund.php app/Services/OrderRefundRecoveryService.php app/Services/RepairRefundRecoveryService.php tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Feature/RepairRefundRecoveryLifecycleTest.php
git commit -m "feat: record refund recovery assignment time"
```

### Task 7: Report unresolved legacy Refund assignment evidence

**Files:**

- Create: `app/Console/Commands/ReportPhaseThreeCRefundAssignmentGaps.php`
- Create: `tests/Feature/Console/ReportPhaseThreeCRefundAssignmentGapsTest.php`

- [ ] **Step 1: Write failing command tests**

Cover Order and Repair rows where:

```text
status=failed
recovery_status=in_progress
recovery_responsible_party in {finance, payment_recovery}
recovery_assigned_at is null
```

Assert tenant/shop counts and stable record IDs are reported, valid rows are excluded, no customer/refund reasons or payment details are logged, and no data is modified.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/Console/ReportPhaseThreeCRefundAssignmentGapsTest.php --compact
```

Expected: FAIL because the command is missing.

- [ ] **Step 3: Implement report-only reconciliation**

Use a bounded/chunked query and a command name such as:

```powershell
php artisan shop-owner:report-phase-3c-refund-assignment-gaps
```

Do not provide an unsafe automatic backfill and do not infer assignment age from `updated_at`, retry timestamps, or failure timestamps. The operational disposition is manual correction through the authoritative recovery service or accepted blocked rollout for the affected rows.

- [ ] **Step 4: Re-run and commit**

```powershell
php artisan test tests/Feature/Console/ReportPhaseThreeCRefundAssignmentGapsTest.php --compact
git add -- app/Console/Commands/ReportPhaseThreeCRefundAssignmentGaps.php tests/Feature/Console/ReportPhaseThreeCRefundAssignmentGapsTest.php
git commit -m "feat: report phase 3c refund assignment gaps"
```

### Task 8: Add Order Refund recovery waiting projection

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/WaitingOrderRefundRecoveryAttentionAdapter.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/WaitingOrderRefundRecoveryAttentionAdapterTest.php`
- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Modify: `config/owner_action_center.php`

- [ ] **Step 1: Write failing adapter tests**

Require failed, in-progress, materially owner-relevant Order Refunds with Finance or Payment Recovery responsibility and non-null assignment evidence. Assert tenant SQL scoping, bounded candidates/counts, stable identity `order_refund:<id>:refund_recovery_waiting`, amount exposure, role label key, assignment time, owner-safe workflow link, and no customer/refund reason exposure.

Assert these exits/reclassifications:

```text
owner decision pending → 3A only
party becomes none + material unresolved failure → 3B only
resolved/superseded/succeeded → no item
missing/stale/invalid assignment evidence → health failure, no item
```

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/WaitingOrderRefundRecoveryAttentionAdapterTest.php --compact
```

Expected: FAIL because the adapter is missing.

- [ ] **Step 3: Implement and register the adapter**

Use a bounded attention query; do not load all refunds and filter in PHP. Reuse Refund recovery constants/policy and emit only `waiting_on_others/refunds` items.

- [ ] **Step 4: Re-run and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/WaitingOrderRefundRecoveryAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/FailedOrderRefundAttentionAdapterTest.php --compact
git add -- app/Services/OwnerActionCenter/Adapters/WaitingOrderRefundRecoveryAttentionAdapter.php app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php tests/Feature/ShopOwner/ActionCenter/WaitingOrderRefundRecoveryAttentionAdapterTest.php
git add -p -- config/owner_action_center.php
git commit -m "feat: surface order refund recovery responsibility"
```

### Task 9: Add Repair Refund recovery waiting projection

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/WaitingRepairRefundRecoveryAttentionAdapter.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/WaitingRepairRefundRecoveryAttentionAdapterTest.php`
- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Modify: `config/owner_action_center.php`

- [ ] **Step 1: Write failing adapter tests**

Mirror Task 8 using authoritative `PosRefund`/Repair recovery state, stable identity `repair_refund:<id>:refund_recovery_waiting`, and the existing owner-safe Repair Refund workflow. Keep Order and Repair source queries, identities, and health independent while sharing the `refunds` coverage family.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/WaitingRepairRefundRecoveryAttentionAdapterTest.php --compact
```

Expected: FAIL because the adapter is missing.

- [ ] **Step 3: Implement, register, and verify independent failure isolation**

An Order adapter failure must not hide healthy Repair items, and vice versa. Defensive deduplication must retain distinct source types.

- [ ] **Step 4: Re-run and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/WaitingOrderRefundRecoveryAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/WaitingRepairRefundRecoveryAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/FailedRepairRefundAttentionAdapterTest.php --compact
git add -- app/Services/OwnerActionCenter/Adapters/WaitingRepairRefundRecoveryAttentionAdapter.php app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php tests/Feature/ShopOwner/ActionCenter/WaitingRepairRefundRecoveryAttentionAdapterTest.php
git add -p -- config/owner_action_center.php
git commit -m "feat: surface repair refund recovery responsibility"
```

## Gate D — Active Logistics Recovery

### Task 10: Add Rider and Dispatcher waiting projection

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/ActiveLogisticsRecoveryAttentionAdapter.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/ActiveLogisticsRecoveryAttentionAdapterTest.php`
- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Modify: `config/owner_action_center.php`
- Reference: `app/Services/Logistics/LogisticsResponsibilityProjection.php`
- Reference: `app/Support/Logistics/LogisticsResponsibility.php`

- [ ] **Step 1: Write failing projection tests**

Require a healthy responsibility projection with:

```text
material_exception_active=true
owner_action_required=false
deterministic_responsible_party=rider|dispatcher
recovery_path_active=true
recovery_path_exhausted=false
```

Assert stable identity `logistics_failure:<leg-id>:logistics_recovery_waiting`, current role key, current assignment/recovery boundary, owner-safe destination, tenant SQL scoping, and source-owned severity.

Cover owner-action precedence, reassignment, unowned exhausted 3B reclassification, delivered/returned/cancelled/resolved exits, stale or inactive rider assignments, invalid batches, superseded legs, contradictory assignments, and historical events without current material state.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/ActiveLogisticsRecoveryAttentionAdapterTest.php --compact
```

Expected: FAIL because the adapter is missing.

- [ ] **Step 3: Implement a bulk-safe adapter over the existing projection**

Do not duplicate attempt, rider validity, dispatcher, batch, return, or exhaustion rules. Preload the bounded leg/assignment data required by `LogisticsResponsibilityProjection` and avoid N+1 queries.

- [ ] **Step 4: Re-run and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/ActiveLogisticsRecoveryAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/UnownedLogisticsFailureAttentionAdapterTest.php --compact
git add -- app/Services/OwnerActionCenter/Adapters/ActiveLogisticsRecoveryAttentionAdapter.php app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php tests/Feature/ShopOwner/ActionCenter/ActiveLogisticsRecoveryAttentionAdapterTest.php
git add -p -- config/owner_action_center.php
git commit -m "feat: surface active logistics recovery responsibility"
```

## Gate E — Integrated Completion

### Task 11: Prove cross-bucket correctness, security, and performance

**Files:**

- Modify: `tests/Feature/ShopOwner/ActionCenter/PhaseThreeCCharacterizationTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php`
- Modify: `tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php`

- [ ] **Step 1: Add integrated classification tests**

For each domain, drive authoritative transitions and assert exactly one outcome:

```text
owner decision → Needs My Decision only
legitimate other party → Waiting on Others only
material and genuinely unowned → Urgent Exceptions only
resolved/non-material → no item
```

Assert aging changes ordering only, duplicate identities are suppressed defensively, and reclassification retains equivalent source-owned materiality unless the domain state itself changes.

- [ ] **Step 2: Add interleaved ordering and independent pagination tests**

Create globally interleaved Compliance, Order Refund, Repair Refund, and Logistics rows across multiple pages. Verify priority/materiality, urgency, actionable age, stable identity tie-breakers, one-source dominance, source filters before candidate limits, page normalization, and separate page state per bucket.

- [ ] **Step 3: Add failure and security tests**

Verify one adapter failure produces a partial Waiting result, all Waiting adapters failing produces unavailable—not zero—and disabling Waiting leaves 3A/3B unchanged. Verify cross-tenant rows never appear, forged bucket/source/page inputs normalize or reject safely, all destinations are local `/shop-owner/...` routes, destination authorization is rechecked, and operational logs contain no sensitive row contents.

- [ ] **Step 4: Add bounded-query evidence**

Assert query counts remain bounded for each adapter and the merged queue. Fail on loading full domain histories or N+1 predecessor, refund, rider, assignment, batch, leg, attempt, or return queries.

- [ ] **Step 5: Run the integrated backend gate**

```powershell
php artisan test tests/Unit/Support/OwnerActionCenter tests/Unit/Services/OwnerActionCenter tests/Feature/ShopOwner/ActionCenter tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Feature/RepairRefundRecoveryLifecycleTest.php tests/Feature/Console/ReportPhaseThreeCRefundAssignmentGapsTest.php --compact
```

Expected: PASS with all three buckets mutually exclusive and independently usable.

- [ ] **Step 6: Commit**

```powershell
git add -- tests/Unit/Support/OwnerActionCenter tests/Unit/Services/OwnerActionCenter tests/Feature/ShopOwner/ActionCenter tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Feature/RepairRefundRecoveryLifecycleTest.php tests/Feature/Console/ReportPhaseThreeCRefundAssignmentGapsTest.php
git commit -m "test: verify phase 3c responsibility classification"
```

### Task 12: Document rollout, run UI verification, and complete reviews

**Files:**

- Create: `docs/shop-owner-phase-3c-rollout-guide.md`
- Modify: `docs/superpowers/specs/2026-08-15-shop-owner-phase-3-action-center-master-design.md`
- Modify: `docs/ai-learning-log.md` only if a genuinely durable repository lesson was discovered
- Verify: `resources/js/Pages/ShopOwner/ActionCenter.tsx`
- Verify: `resources/js/Pages/ShopOwner/Dashboard.tsx`

- [ ] **Step 1: Write the rollout and rollback guide**

Document:

- always-on committed thesis defaults and no `.env` edits;
- migration and report-only Refund evidence check;
- source readiness and health checks;
- disabling only `buckets.waiting_on_others.enabled` as the presentation rollback;
- the fact that rollback does not remove canonical routes or change domain state;
- exact healthy-zero, partial, unavailable, disabled, and unsupported meanings;
- Phase 4 ownership of remaining approvals and Phase 5 ownership of final navigation/ERP retirement.

- [ ] **Step 2: Run focused frontend tests and build**

```powershell
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
pnpm run build
```

Expected: tests PASS and Vite production build exits 0. If `pnpm` is unavailable in the execution environment, use the repository's installed package-manager-compatible Vitest/Vite binaries, record the substitution, and do not claim the exact `pnpm` command passed.

- [ ] **Step 3: Verify the browser-visible contract**

Using `@webapp-testing`, verify an authenticated Shop Owner at `/shop-owner/home` and `/shop-owner/action-center`:

- sees three independent summaries/tabs;
- sees no more than three Home rows per bucket;
- can filter Waiting by Compliance, Refunds, and Logistics only;
- sees role/team labels, semantic links, and no mutation controls;
- retains filters on Refresh and gets deterministic page normalization;
- can use keyboard focus, 200% zoom, mobile viewport, dark/light themes, and reduced motion without horizontal page scrolling;
- sees partial-source status without a large blocking error panel.

Capture screenshots only as test evidence; do not commit generated screenshots unless repository convention requires it.

- [ ] **Step 4: Run the sequential required review stack**

Record results in the execution notes:

1. `@ponytail`: remove avoidable duplication/speculative abstractions.
2. Standards review: Laravel/React/repository conventions.
3. Spec review: all 18 Phase 3C acceptance criteria.
4. TypeScript review: focused types, safe narrowing, no unnecessary `any` or assertions.
5. `@karpathy-guidelines`: assumptions, minimum scope, surgical diff, verifiable criteria.
6. Code-splitting: N/A unless a new heavy dependency or conditional bundle was introduced.
7. Gauge improvements: report behavior/query evidence; mark unmeasured metrics honestly.
8. `@security-review`: tenant, authorization, input, sensitive data, and destinations.
9. Reuse/dead-code audit: confirm existing DTO, coordinator, policies, projection, components, and routes are reused; remove only orphans created by this change.

- [ ] **Step 5: Run final quality gates**

```powershell
git diff --check
php artisan test tests/Unit/Support/OwnerActionCenter tests/Unit/Services/OwnerActionCenter tests/Feature/ShopOwner/ActionCenter tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Feature/RepairRefundRecoveryLifecycleTest.php tests/Feature/Console/ReportPhaseThreeCRefundAssignmentGapsTest.php --compact
pnpm run test:frontend
pnpm run build
```

Expected: all focused tests PASS, frontend suite PASS, build exits 0, and `git diff --check` emits no output. Attempt `composer test` only if practical; if an existing repository-wide issue prevents completion, report the exact command and failure without weakening focused evidence.

- [ ] **Step 6: Commit documentation**

```powershell
git add -- docs/shop-owner-phase-3c-rollout-guide.md
git add -p -- docs/superpowers/specs/2026-08-15-shop-owner-phase-3-action-center-master-design.md
git commit -m "docs: add phase 3c rollout guidance"
```

If `docs/ai-learning-log.md` received a genuinely durable lesson, stage only that file immediately before the documentation commit. Otherwise leave it untouched.

## Completion invariant

Phase 3C is complete only when Compliance Review, Order and Repair Refund recovery, and active Logistics recovery independently pass their readiness gates; the shared coordinator presents them only under `Waiting on Others`; Home and the full Action Center expose the approved three-bucket UX; authoritative responsibility changes reclassify or remove items without Action Center-owned state; Phase 3A and Phase 3B remain intact; and the recorded tenant, privacy, accessibility, performance, rollback, test, and build evidence is current.
