# Shop Owner Phase 3A Owner Decisions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let allowlisted Shop Owners discover current Refund, Expense, and Purchase Request decisions from a bounded Home summary and `/shop-owner/action-center`, while existing domain pages remain the only execution surfaces.

**Architecture:** Add a Phase 3 rollout policy nested inside the completed Phase 2 canonical-shell selection, four side-effect-free domain adapters, and one in-memory request-time coordinator. The coordinator normalizes immutable `OwnerAttentionItem` values, isolates adapter failures, applies one ordering/counting/pagination contract, and supplies both Home and the full queue. No Action Center state is persisted and no mutation behavior moves out of the existing domain workflows.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, PHPUnit, Inertia 2, React 18, TypeScript 5.7, Vitest, Tailwind CSS 4, pnpm, local browser/Playwright verification.

---

## Source specifications

- `docs/superpowers/specs/2026-08-15-shop-owner-phase-3-action-center-master-design.md`
- `docs/superpowers/specs/2026-08-15-shop-owner-phase-3a-owner-decisions-design.md`
- `docs/superpowers/specs/2026-08-15-shop-owner-phase-2-canonical-adaptive-shell-foundation-design.md`

The Phase 3A focused specification is authoritative for this plan. This plan completes Phase 3A only. It must not add Phase 3B Material Exception adapters or Phase 3C Waiting on Others adapters.

## Required implementation skills

- Use `@superpowers:test-driven-development` for every implementation task.
- Use `@laravel-best-practices` for query scopes, authorization boundaries, controllers, routes, configuration, and tests.
- Use `@ui-ux-pro-max`, `@design-system`, and `@ui-styling` for the Action Center UI and accessibility.
- Use `@vercel-react-best-practices` for React/TSX changes and `@webapp-testing` for browser-visible verification.
- Use `@ponytail` and `@karpathy-guidelines` during the sequential simplification/review gate.
- Use `@security-review` because tenant scoping, approval discovery, URLs, and operational telemetry are security-sensitive.
- Use `@superpowers:verification-before-completion` before any completion claim.

## Non-negotiable boundaries

- Do not add an Action Center table, migration, model, job, event projection, polling loop, WebSocket, SSE channel, notification-based source, read/unread state, or optimistic resolution.
- Do not move Approve/Reject controls, validation, confirmation, reason capture, locking, side effects, notifications, or audit behavior into the Action Center.
- Do not change domain authorization to make a card appear. A card is discovery metadata, never permission evidence.
- Keep the owner-facing coverage sources exactly `refunds`, `expenses`, and `purchase_requests`.
- Keep the independently queried adapter keys exactly `order_refunds`, `repair_refunds`, `expenses`, and `purchase_requests`.
- Use `attention_key` everywhere for normalized item identity. Do not introduce a parallel `key` field.
- Count owner-facing work only after distinct `attention_key` normalization. Adapter counts remain operational health metadata.
- An item exists if and only if all current inclusion and owner-responsibility predicates remain true.
- Register `/shop-owner/action-center` independently of rollout flags. Flags select presentation, not route existence or domain availability.
- A Phase 3 failure degrades to Phase 2 Home/placeholders and never changes Phase 2 shell selection.
- Keep existing approval pages and URLs functional.
- Do not add 3B/3C buckets, zero-value unsupported filters, or adapters beyond the four launch adapters.

## Locked file structure

### Backend contracts and coordination

- `config/owner_action_center.php` — narrow global flag, stable shop allowlist, adapter-family enablement, and page bounds.
- `app/Enums/OwnerActionCenterRolloutReason.php` — rollout-only reason values.
- `app/Enums/OwnerActionCenterDegradationStatus.php` — runtime composition state values.
- `app/Support/OwnerActionCenter/OwnerActionCenterSelection.php` — immutable rollout decision.
- `app/Support/OwnerActionCenter/OwnerAttentionQuery.php` — validated coverage filter/page/per-page/candidate bound.
- `app/Support/OwnerActionCenter/OwnerAttentionItem.php` — immutable request-time item DTO.
- `app/Support/OwnerActionCenter/OwnerAttentionAdapterResult.php` — candidates plus qualifying count for one adapter.
- `app/Support/OwnerActionCenter/OwnerActionCenterResult.php` — items, coverage counts, health, degradation, and pagination.
- `app/Contracts/OwnerActionCenter/OwnerAttentionAdapter.php` — small domain-adapter read contract.
- `app/Services/OwnerActionCenter/OwnerActionCenterRolloutPolicy.php` — Phase 2 rollout-candidate plus Phase 3 cohort selection; it does not compose the shell.
- `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php` — resolves only configured Phase 3A adapters.
- `app/Services/OwnerActionCenter/OwnerActionCenterService.php` — in-memory merge, normalize, order, count, paginate, and isolate failures.

### Domain adapters

- `app/Services/OwnerActionCenter/Adapters/OrderRefundAttentionAdapter.php`
- `app/Services/OwnerActionCenter/Adapters/RepairRefundAttentionAdapter.php`
- `app/Services/OwnerActionCenter/Adapters/ExpenseAttentionAdapter.php`
- `app/Services/OwnerActionCenter/Adapters/PurchaseRequestAttentionAdapter.php`

Each file contains only one domain query and projection. Do not create a generic status engine or universal approval repository.

### HTTP and frontend

- `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php` — full-queue GET only.
- `app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php` — retain dashboard data boundary and add the shared summary only for selected Phase 3 requests.
- `routes/shop-owner-shell.php` — canonical route registration.
- `resources/js/types/ownerActionCenter.ts` — serialized contract.
- `resources/js/components/owner-action-center/OwnerAttentionList.tsx` — shared semantic list/card presentation.
- `resources/js/components/owner-action-center/OwnerActionCenterAvailability.tsx` — empty, partial, unavailable, and coverage disclosure.
- `resources/js/Pages/ShopOwner/ActionCenter.tsx` — dedicated queue.
- `resources/js/Pages/ShopOwner/Dashboard.tsx` — bounded summary replacing only the Phase 3A Required Actions placeholder for selected owners; keep Exceptions as a later-phase placeholder.

## Task 1: Characterize all four authoritative decision boundaries

**Files:**

- Create: `tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php`
- Reference: `app/Http/Controllers/Api/RefundApprovalController.php`
- Reference: `app/Http/Controllers/Api/RepairRefundWorkflowController.php`
- Reference: `app/Http/Controllers/ShopOwner/ExpenseController.php`
- Reference: `app/Http/Controllers/ShopOwner/PurchaseRequestController.php`
- Reference: `app/Services/ShopOwnerApprovalPolicyService.php`
- Reference: `app/Services/ExpenseApprovalService.php`
- Reference: `app/Services/RepairPosRefundService.php`
- Reference: `app/Services/PurchaseRequestService.php`
- Reference: `app/Models/OrderRefund.php`
- Reference: `app/Models/PosRefund.php`
- Reference: `app/Models/Finance/Expense.php`
- Reference: `app/Models/Approval.php`
- Reference: `app/Models/PurchaseRequest.php`

- [ ] **Step 1: Write characterization fixtures for entry predicates**

Create one same-shop actionable record and one cross-shop/non-actionable record per adapter. Lock the current authoritative facts before extracting shared read logic:

```text
order_refunds:
  flow_type=request_approval
  status in current active request states
  shop_owner_status=pending
  individual finance stage in {pending, approved_initial}
  company finance stage=approved_initial
  requiresOwnerApprovalForRefund=true

repair_refunds:
  module_type=repair
  status=requested
  shop_owner_status=pending
  current Finance stage makes owner responsible
  requiresOwnerApprovalForRefund=true

expenses:
  finance_expenses.shop_id=current shop
  procurement_receipt_id=null
  status=submitted
  linked Approval is pending and current_approver_role=shop_owner

purchase_requests:
  shop_owner_id=current shop
  status=pending_shop_owner
```

Where the current implementation differs, update this test and the adapter task below to the verified authoritative equivalent. Do not change a domain merely to match the design's provisional names.

- [ ] **Step 2: Characterize predicate-based exits**

For every source, mutate one required predicate at a time and assert the existing owner list/decision boundary no longer treats it as actionable. Cover known approval, rejection, cancellation, supersession, responsibility-stage, and terminal transitions that the domain currently supports.

- [ ] **Step 3: Characterize destinations and stale-state rechecks**

Assert the existing page route is owner-authenticated, same-tenant scoped, and its mutation endpoint rejects a cross-shop or no-longer-actionable source. Record these owner-safe destinations:

```text
order_refunds / repair_refunds -> shop-owner.refund-approvals
expenses                       -> shop-owner.expense-approvals
purchase_requests              -> shop-owner.purchase-request-approval
```

- [ ] **Step 4: Run the baseline test**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php --compact
```

Expected: PASS against current behavior. If the test reveals a real authorization or state defect, stop and correct the Phase 1/domain contract separately rather than hiding it in an adapter.

- [ ] **Step 5: Commit the characterization baseline**

```powershell
git add -- tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php
git commit -m "test: characterize phase 3a owner decisions"
```

## Task 2: Add the nested Phase 3 rollout policy

**Files:**

- Create: `config/owner_action_center.php`
- Create: `app/Enums/OwnerActionCenterRolloutReason.php`
- Create: `app/Enums/OwnerActionCenterDegradationStatus.php`
- Create: `app/Support/OwnerActionCenter/OwnerActionCenterSelection.php`
- Create: `app/Services/OwnerActionCenter/OwnerActionCenterRolloutPolicy.php`
- Create: `tests/Unit/Services/OwnerActionCenter/OwnerActionCenterRolloutPolicyTest.php`
- Reference: `app/Services/OwnerShell/OwnerShellRolloutPolicy.php`

- [ ] **Step 1: Write the failing rollout matrix**

Cover:

```text
Phase 2 canonical not selected       -> canonical_shell_not_selected
Phase 3 global flag off              -> action_center_global_disabled
flag on + shop absent                -> shop_not_allowlisted
flag on + same stable shop_id present-> shop_allowlisted
malformed allowlist                  -> safe disabled result
all adapter families disabled        -> rollout_reason=shop_allowlisted + degradation_status=no_enabled_adapters
```

Prove that owner email, account changes, and domain/module authorization do not alter the Phase 3 cohort result. Also prove that `no_enabled_adapters` is not emitted as a rollout reason.

- [ ] **Step 2: Run the rollout test to verify RED**

```powershell
php artisan test tests/Unit/Services/OwnerActionCenter/OwnerActionCenterRolloutPolicyTest.php --compact
```

Expected: FAIL because the Phase 3 configuration and policy do not exist.

- [ ] **Step 3: Add narrow configuration**

Use this shape without editing `.env`:

```php
return [
    'enabled' => (bool) env('SHOP_OWNER_ACTION_CENTER_ENABLED', false),
    'allowlisted_shop_ids' => /* same positive-integer parsing as owner_shell.php */,
    'coverage' => [
        'refunds' => (bool) env('SHOP_OWNER_ACTION_CENTER_REFUNDS_ENABLED', true),
        'expenses' => (bool) env('SHOP_OWNER_ACTION_CENTER_EXPENSES_ENABLED', true),
        'purchase_requests' => (bool) env('SHOP_OWNER_ACTION_CENTER_PURCHASE_REQUESTS_ENABLED', true),
    ],
    'per_page' => 20,
    'max_per_page' => 50,
    'max_page' => 100,
    'home_limit' => 5,
];
```

No database settings, percentages, owner self-opt-in, or generic feature-flag service.

- [ ] **Step 4: Implement the fail-safe selection**

`OwnerActionCenterRolloutPolicy` composes the existing `OwnerShellRolloutPolicy` candidate selection with the Phase 3 flag and allowlist. It must not depend on `CanonicalOwnerShellService`, because the shell service will use this policy to decide whether to include the Action Center navigation item. Use the Shop Owner primary key as the same stable shop identity used by Phase 2. Keep rollout reasons separate from degradation statuses.

The HTTP integration in Task 9 must additionally verify the final `CanonicalOwnerShellService::forOwner($owner)` metadata before reading Action Center data. That second check contains Phase 2 composition failure without creating a service dependency cycle.

- [ ] **Step 5: Re-run and commit**

```powershell
php artisan test tests/Unit/Services/OwnerActionCenter/OwnerActionCenterRolloutPolicyTest.php --compact
git add -- config/owner_action_center.php app/Enums/OwnerActionCenterRolloutReason.php app/Enums/OwnerActionCenterDegradationStatus.php app/Support/OwnerActionCenter/OwnerActionCenterSelection.php app/Services/OwnerActionCenter/OwnerActionCenterRolloutPolicy.php tests/Unit/Services/OwnerActionCenter/OwnerActionCenterRolloutPolicyTest.php
git commit -m "feat: add owner action center rollout policy"
```

Expected: PASS.

## Task 3: Define the immutable read contracts

**Files:**

- Create: `app/Contracts/OwnerActionCenter/OwnerAttentionAdapter.php`
- Create: `app/Support/OwnerActionCenter/OwnerAttentionQuery.php`
- Create: `app/Support/OwnerActionCenter/OwnerAttentionItem.php`
- Create: `app/Support/OwnerActionCenter/OwnerAttentionAdapterResult.php`
- Create: `app/Support/OwnerActionCenter/OwnerActionCenterResult.php`
- Create: `resources/js/types/ownerActionCenter.ts`
- Create: `tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php`

- [ ] **Step 1: Write failing validation/serialization tests**

Test required fields, bounded strings, local destination URLs, non-negative monetary exposure, valid tiers, immutable serialization, unique identity, pagination bounds, and invalid coverage filters. Explicitly reject a payload containing `key` without `attention_key`.

- [ ] **Step 2: Run the contract test to verify RED**

```powershell
php artisan test tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php --compact
```

Expected: FAIL because the contracts do not exist.

- [ ] **Step 3: Implement the minimal adapter interface**

```php
interface OwnerAttentionAdapter
{
    public function adapterKey(): string;
    public function coverageSource(): string;
    public function read(ShopOwner $owner, OwnerAttentionQuery $query): OwnerAttentionAdapterResult;
}
```

Do not put mutation, authorization grants, generic state transitions, or persistence methods on this interface.

- [ ] **Step 4: Implement `OwnerAttentionItem`**

Use one final readonly value with the frozen fields. Generate and expose identity as:

```php
$attentionKey = implode(':', [$sourceType, (string) $sourceId, $category]);
```

Fixed Phase 3A values are `primary_bucket=needs_my_decision`, `owner_action_required=true`, and `waiting_on=shop_owner`. `destination_url` must be a validated local path produced server-side.

- [ ] **Step 5: Implement matching TypeScript types**

Keep coverage and adapter concepts separate:

```ts
export type OwnerAttentionCoverageSource = "refunds" | "expenses" | "purchase_requests";
export type OwnerAttentionAdapterKey = "order_refunds" | "repair_refunds" | "expenses" | "purchase_requests";

export interface OwnerAttentionItem {
  attention_key: string;
  source_type: "order_refund" | "repair_refund" | "expense" | "purchase_request";
  // remaining frozen presentation fields
}
```

- [ ] **Step 6: Re-run and commit**

```powershell
php artisan test tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php --compact
git add -- app/Contracts/OwnerActionCenter/OwnerAttentionAdapter.php app/Support/OwnerActionCenter resources/js/types/ownerActionCenter.ts tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php
git commit -m "feat: define owner attention read contracts"
```

Expected: PASS.

## Task 4: Build the adapter registry and in-memory coordinator

**Files:**

- Create: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Create: `app/Services/OwnerActionCenter/OwnerActionCenterService.php`
- Create: `tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php`

- [ ] **Step 1: Write failing coordinator tests with fake adapters**

Cover:

- configuration family `refunds` resolving two adapter keys;
- filtering before candidate limits;
- duplicate suppression by `attention_key` before owner-facing counts;
- Refund owner-facing aggregation across both Refund adapters;
- priority tier, materiality, shop-currency exposure, `urgency_at`, `actionable_since`, source type, and source ID ordering;
- null urgency last and non-comparable currency excluded from raw-amount comparison;
- page `P` requesting at most `P × N` candidates per participating adapter, capped by configured limits;
- globally interleaved results, deterministic ties, and one source dominating early pages;
- invalid refreshed page normalizing to the last valid page;
- one adapter failure preserving healthy results and marking totals partial;
- all enabled adapters failing as unavailable, never zero;
- no enabled adapters as configuration degradation, never zero.

- [ ] **Step 2: Run the service test to verify RED**

```powershell
php artisan test tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php --compact
```

Expected: FAIL because the registry/coordinator do not exist.

- [ ] **Step 3: Implement the fixed Phase 3A registry**

Resolve only these application-configured classes:

```php
'refunds' => [OrderRefundAttentionAdapter::class, RepairRefundAttentionAdapter::class],
'expenses' => [ExpenseAttentionAdapter::class],
'purchase_requests' => [PurchaseRequestAttentionAdapter::class],
```

Validate that each resolved adapter reports the expected bounded adapter key and coverage source. Do not scan the filesystem or add a plugin mechanism.

- [ ] **Step 4: Implement one coordinator contract for Home and queue**

Expose two thin methods backed by the same private read path:

```php
public function summaryForHome(ShopOwner $owner): OwnerActionCenterResult;
public function queueForActionCenter(ShopOwner $owner, OwnerAttentionQuery $query): OwnerActionCenterResult;
```

Home uses configured `home_limit`; queue uses validated page/per-page. Catch adapter read/composition failures independently, `report()` them, emit bounded structured health telemetry, and contribute no data from the failed adapter. Let invalid shared DTO/common composition fail the whole result so the controller can degrade safely.

- [ ] **Step 5: Re-run and commit**

```powershell
php artisan test tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php --compact
git add -- app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php app/Services/OwnerActionCenter/OwnerActionCenterService.php tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php
git commit -m "feat: coordinate owner attention reads"
```

Expected: PASS.

## Task 5: Implement the Order Refund adapter

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/OrderRefundAttentionAdapter.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/OrderRefundAttentionAdapterTest.php`
- Modify only if characterization requires shared policy extraction: `app/Services/ShopOwnerApprovalPolicyService.php`

- [ ] **Step 1: Write failing adapter tests**

Assert same-shop scoping, individual/company Finance-stage differences, request flow, active status, pending owner status, threshold policy, predicate exits, stable `order_refund:{id}:refund_approval` identity, PHP shop-currency exposure, bounded count/candidates, deterministic order, and a destination containing stable `refund_type=order&refund={id}` focus parameters.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OrderRefundAttentionAdapterTest.php --compact
```

- [ ] **Step 3: Implement one bounded Eloquent query**

Select only fields needed for the card and eligibility, eager-load only required relations, apply tenant and inclusion predicates in SQL, and cap candidates before hydration. Reuse `ShopOwnerApprovalPolicyService` only through side-effect-free bulk-safe logic. If the existing per-row method would cause N+1 queries, extract a small threshold/input method in that service; do not duplicate the rule in the adapter.

- [ ] **Step 4: Prove no N+1 and no mutation**

Add a query-count assertion for 1 versus many qualifying rows and assert no source row changes during `read()`.

- [ ] **Step 5: Re-run and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OrderRefundAttentionAdapterTest.php tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php --compact
git add -- app/Services/OwnerActionCenter/Adapters/OrderRefundAttentionAdapter.php app/Services/ShopOwnerApprovalPolicyService.php tests/Feature/ShopOwner/ActionCenter/OrderRefundAttentionAdapterTest.php
git commit -m "feat: surface owner order refund decisions"
```

Stage `ShopOwnerApprovalPolicyService.php` only if it changed.

## Task 6: Implement the Repair Refund adapter independently

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/RepairRefundAttentionAdapter.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/RepairRefundAttentionAdapterTest.php`
- Modify only if characterization requires side-effect-free extraction: `app/Services/RepairPosRefundService.php`

- [ ] **Step 1: Write failing adapter tests**

Assert `module_type=repair`, same-shop tenant scope, `status=requested`, current owner stage, pending owner status, threshold policy, all predicate exits, stable `repair_refund:{id}:refund_approval` identity, bounded queries, and destination focus `refund_type=repair&refund={id}`. Assert Order Refund adapter failure/absence has no effect on this adapter and vice versa.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/RepairRefundAttentionAdapterTest.php --compact
```

- [ ] **Step 3: Implement the minimum read projection**

Do not call mutation methods on `RepairPosRefundService`. Extract a small side-effect-free eligibility predicate only if the current decision rule cannot be reused safely. Avoid loading proof/evidence blobs, payment screenshots, customer details, or refund legs not required for normalized exposure.

- [ ] **Step 4: Re-run domain regressions and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/RepairRefundAttentionAdapterTest.php tests/Feature/RepairPosRefundFlowTest.php tests/Feature/RepairOnlineRefundWorkflowTest.php --compact
git add -- app/Services/OwnerActionCenter/Adapters/RepairRefundAttentionAdapter.php app/Services/RepairPosRefundService.php tests/Feature/ShopOwner/ActionCenter/RepairRefundAttentionAdapterTest.php
git commit -m "feat: surface owner repair refund decisions"
```

Stage `RepairPosRefundService.php` only if it changed.

## Task 7: Implement the Expense adapter

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/ExpenseAttentionAdapter.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/ExpenseAttentionAdapterTest.php`
- Modify only if needed: `app/Services/ExpenseApprovalService.php`

- [ ] **Step 1: Write failing adapter tests**

Assert authoritative `shop_id` tenant scope, company owner eligibility, `procurement_receipt_id IS NULL`, submitted Expense state, linked pending Approval, current `shop_owner` role, predicate exits, stable identity, amount/due-date normalization, bounded query count, and destination `expense={id}`.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/ExpenseAttentionAdapterTest.php --compact
```

- [ ] **Step 3: Implement the query with `whereHas`/joined eligibility**

Do not load all submitted Expenses and call `canApprove()` per row. Express the linked Approval responsibility predicates in the bounded query, selecting only safe card fields. If a shared policy is needed, add a side-effect-free scope/helper equivalent to the mutation service's current checks.

- [ ] **Step 4: Re-run workflow regression and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/ExpenseAttentionAdapterTest.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php --compact
git add -- app/Services/OwnerActionCenter/Adapters/ExpenseAttentionAdapter.php app/Services/ExpenseApprovalService.php tests/Feature/ShopOwner/ActionCenter/ExpenseAttentionAdapterTest.php
git commit -m "feat: surface owner expense decisions"
```

Stage `ExpenseApprovalService.php` only if it changed.

## Task 8: Implement the Purchase Request adapter

**Files:**

- Create: `app/Services/OwnerActionCenter/Adapters/PurchaseRequestAttentionAdapter.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/PurchaseRequestAttentionAdapterTest.php`
- Modify only if needed: `app/Models/PurchaseRequest.php`

- [ ] **Step 1: Write failing adapter tests**

Assert authoritative `shop_owner_id` tenant scope, company owner eligibility, `pending_shop_owner`, all current responsibility predicates, known transition exits to Finance/rejected/cancelled/superseded states, stable identity, total-cost exposure, bounded query, and existing `purchase_request={id}` destination behavior.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/PurchaseRequestAttentionAdapterTest.php --compact
```

- [ ] **Step 3: Implement the bounded query**

Use persisted authoritative total cost when available; otherwise compute `quantity × unit_cost` in the projection without invoking controller presentation logic or loading inventory variant trees. Do not change Procurement transitions.

- [ ] **Step 4: Re-run workflow regression and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/PurchaseRequestAttentionAdapterTest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Unit/Services/PurchaseRequestServiceTest.php --compact
git add -- app/Services/OwnerActionCenter/Adapters/PurchaseRequestAttentionAdapter.php app/Models/PurchaseRequest.php tests/Feature/ShopOwner/ActionCenter/PurchaseRequestAttentionAdapterTest.php
git commit -m "feat: surface owner purchase request decisions"
```

Stage `PurchaseRequest.php` only if it changed.

## Task 9: Integrate Home and the canonical full-queue route

**Files:**

- Create: `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php`
- Modify: `app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php`
- Modify: `app/Services/OwnerShell/CanonicalOwnerShellService.php`
- Modify: `routes/shop-owner-shell.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php`
- Modify: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerHomeTest.php`
- Modify: `tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php`

- [ ] **Step 1: Write failing route and Home integration tests**

Assert:

- `/shop-owner/action-center` has name `shop-owner.shell.action-center`, `auth:shop_owner`, and exists with flags off;
- non-Phase-3 owners redirect safely to `/shop-owner/home` without a loop;
- selected owners receive the queue component and validated `source`, `page`, and `per_page` props;
- selected owners receive one `Action Center` item under the canonical Home group, while owners outside Phase 3 do not;
- selected canonical Home receives a bounded summary from the same service;
- existing `/shop-owner/dashboard` never queries attention sources;
- Phase 2 canonical Home still shows placeholders outside the Phase 3 cohort;
- individual adapter failure returns partial data;
- all-adapter failure returns unavailable data;
- common coordinator failure on Action Center redirects Home, while Home renders Phase 2 placeholders.

- [ ] **Step 2: Run to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerHomeTest.php --compact
```

- [ ] **Step 3: Register the canonical GET**

```php
Route::get('/action-center', OwnerActionCenterController::class)
    ->name('action-center');
```

Do not conditionally register it and do not put ERP workspace/module middleware on it.

- [ ] **Step 4: Implement trusted controller selection**

Both controllers call `OwnerActionCenterRolloutPolicy` server-side and verify that `CanonicalOwnerShellService::forOwner($owner)` produced the final canonical presentation before reading adapters. `ShopOwnerDashboardController` keeps its current dashboard props and adds `ownerActionCenter` only after successful selection/composition. `OwnerActionCenterController` validates bounded query parameters and redirects to the canonical Home on selection/common composition failure. Do not accept arbitrary source names, URLs, sort columns, or page sizes.

Update `CanonicalOwnerShellService::homeGroup()` to append one available `Action Center` item only when `OwnerActionCenterRolloutPolicy` selects the owner. The URL is always the canonical `shop-owner.shell.action-center` route, and active matching comes only from that canonical route. Do not add approval pages or adapter filters to the sidebar.

- [ ] **Step 5: Re-run and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerHomeTest.php tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php --compact
git add -- app/Http/Controllers/ShopOwner/OwnerActionCenterController.php app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php app/Services/OwnerShell/CanonicalOwnerShellService.php routes/shop-owner-shell.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerHomeTest.php tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php
git commit -m "feat: add canonical owner action center routes"
```

## Task 10: Build the grounded Home summary and full Action Center UI

**Files:**

- Create: `resources/js/components/owner-action-center/OwnerAttentionList.tsx`
- Create: `resources/js/components/owner-action-center/OwnerActionCenterAvailability.tsx`
- Create: `resources/js/Pages/ShopOwner/ActionCenter.tsx`
- Create: `resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx`
- Modify: `resources/js/Pages/ShopOwner/Dashboard.tsx`
- Modify: `resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx`

- [ ] **Step 1: Write failing component/page tests**

Cover:

- Home keeps Business Summary and existing metrics, then renders at most 3–5 decisions and `View all`;
- full page shows only active `Needs My Decision` and enabled coverage filters;
- source badge, decision title, exposure, textual priority, age/due context, and native `Open workflow` link;
- no Approve/Reject control;
- counts marked partial and failed adapter named when degraded;
- all failed is unavailable, not zero; healthy empty is a true empty state;
- unsupported 3B/3C sources are absent from filters and zero counts;
- Refresh uses an Inertia reload/visit preserving valid `source` and `page` query state;
- pagination uses native buttons/links with disabled/current labels;
- existing Phase 3 Exceptions placeholder remains on Home because 3B is not implemented.

- [ ] **Step 2: Run frontend tests to verify RED**

```powershell
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
```

- [ ] **Step 3: Implement reusable semantic rows**

Use the existing canonical shell's blue/neutral tokens, rounded cards, spacing, dark mode, typography, and Lucide icons. `Open workflow` must be a real Inertia `Link`/anchor. Do not use `div onClick`; do not communicate priority by color alone.

- [ ] **Step 4: Implement Home as a bounded projection**

Replace only the Required Actions placeholder when `ownerActionCenter` is present. Keep the existing Business Summary/data fetch untouched. Show coverage disclosure as secondary text and avoid claiming platform-wide completeness.

- [ ] **Step 5: Implement the dedicated page**

Render the complete server-supplied page, source filters, Refresh, pagination, and coverage/health disclosure. Do not re-sort, recount, deduplicate, or filter domain membership in React.

- [ ] **Step 6: Re-run and commit**

```powershell
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
git add -- resources/js/components/owner-action-center resources/js/Pages/ShopOwner/ActionCenter.tsx resources/js/Pages/ShopOwner/Dashboard.tsx resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
git commit -m "feat: render owner action center surfaces"
```

## Task 11: Add stable focused links to existing approval pages

**Files:**

- Modify: `resources/js/Pages/ShopOwner/Approvals/refundApproval.tsx`
- Modify: `resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx`
- Modify: `resources/js/Pages/ShopOwner/Approvals/PurchaseRequestApproval.tsx` only if its existing focus behavior needs hardening
- Create: `resources/js/Pages/ShopOwner/Approvals/__tests__/ActionCenterDeepLinks.test.tsx`

- [ ] **Step 1: Write failing deep-link tests**

Assert:

```text
?refund_type=order&refund=12  -> opens only Order Refund 12 when returned
?refund_type=repair&refund=12 -> opens only Repair Refund 12 when returned
?expense=34                   -> opens Expense 34 when returned
?purchase_request=56          -> opens Purchase Request 56 when returned
```

If a record is absent, stale, or no longer returned by the authoritative API, the page must remain usable and must not manufacture an actionable record. Query parameters must not bypass API tenant/state filters.

- [ ] **Step 2: Run to verify RED**

```powershell
pnpm run test:frontend -- resources/js/Pages/ShopOwner/Approvals/__tests__/ActionCenterDeepLinks.test.tsx
```

- [ ] **Step 3: Add one bounded focus helper per page**

Parse only the expected positive integer and fixed Refund type. After the existing API response arrives, find the matching record and open the existing details modal. Remove the consumed focus parameters with `history.replaceState` while preserving unrelated safe query state. Do not fetch a record from a new unscoped endpoint.

- [ ] **Step 4: Re-run approval-page regressions and commit**

```powershell
pnpm run test:frontend -- resources/js/Pages/ShopOwner/Approvals/__tests__/ActionCenterDeepLinks.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestApproval.test.tsx resources/js/Pages/ERP/Finance/__tests__/refundApproval.return-gates.test.tsx
git add -- resources/js/Pages/ShopOwner/Approvals/refundApproval.tsx resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx resources/js/Pages/ShopOwner/Approvals/PurchaseRequestApproval.tsx resources/js/Pages/ShopOwner/Approvals/__tests__/ActionCenterDeepLinks.test.tsx
git commit -m "feat: focus owner approval workflows from action center"
```

Stage `PurchaseRequestApproval.tsx` only if it changed.

## Task 12: Prove rollout, performance, security, and operational readiness

**Files:**

- Create: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php`
- Create: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php`
- Create: `docs/shop-owner-phase-3a-rollout-guide.md`
- Modify: `docs/ai-learning-log.md` only if implementation discovers a durable repository-wide lesson
- Modify: `scripts/browser-smoke.mjs` only if a narrow local Action Center scenario fits the existing script

- [ ] **Step 1: Add security/telemetry tests**

Prove:

- cross-shop records never appear in items, counts, summaries, or focused destination data;
- arbitrary source/page/per-page values are rejected or deterministically normalized;
- rollout uses the same stable shop identity as Phase 2;
- logs include only `shop_id`, rollout reason, enabled/healthy/failed adapter keys, degradation, duration, result count, validated source, bounded page/per-page, and correlation ID;
- logs exclude titles, people, source references, amounts, reasons, descriptions, and payment/refund details;
- adapter exceptions contribute no data and are reported; authorization/tenant defects never become successful empty results;
- `/action-center` failure redirects once to Home and never loops.

- [ ] **Step 2: Add bounded-query evidence**

For one and many rows per adapter, record query counts and assert they do not grow per item. Verify Home retrieves counts plus at most the configured top candidates and full queue respects candidate ceilings. Capture baseline and final counts in the rollout guide; if latency cannot be measured reliably in test, report it as not measured.

- [ ] **Step 3: Run focused backend coverage**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter tests/Feature/ShopOwner/CanonicalShell --compact
```

Expected: PASS.

- [ ] **Step 4: Run complete frontend tests and build**

```powershell
pnpm run test:frontend
pnpm run build
```

Expected: PASS. The repository has no committed standalone TypeScript type-check or lint script; do not claim either was run.

- [ ] **Step 5: Perform controlled browser verification**

With Phase 2 canonical shell enabled for a test shop and Phase 3 independently enabled/allowlisted, verify desktop and mobile widths for:

- flag off, shop not allowlisted, and shop allowlisted;
- company and individual owners;
- healthy mixed queue, healthy empty, partial Refund adapter failure, all unavailable, and no-enabled configuration;
- Home count/top items agreeing with the full page under fixed source state;
- filters, Refresh, page normalization, semantic links, focus order, contrast, dark/light mode, reduced motion, and no horizontal overflow;
- record-focused navigation and stale record handling;
- Phase 3 kill-switch fallback to Phase 2 placeholders without leaving the canonical shell.

Use the repository's runnable local browser tooling. Save screenshots only as QA evidence, not as a new product design artifact.

- [ ] **Step 6: Write the rollout guide**

Document flags, allowlist identity, adapter-family toggles, launch readiness evidence for all four adapters, safe enable/disable order, expected rollout/health/degradation categories, query-count evidence, partial/all-failure behavior, and rollback. State prominently that supported counts cover only Refunds, Expenses, and Purchase Requests and that Phase 3A completion does not complete Phase 3.

- [ ] **Step 7: Run the required sequential review stack**

Record:

1. simplify with `@ponytail`;
2. Standards review against repository/Laravel/Inertia conventions;
3. Spec review against every Phase 3A acceptance criterion;
4. TS/TSX clean-code and React performance review with `@vercel-react-best-practices`;
5. assumptions/minimum-scope review with `@karpathy-guidelines`;
6. code-splitting review—expected `N/A` unless measured bundle behavior justifies it;
7. gauge improvements using query counts, result bounds, tests, and build evidence; mark unmeasured values honestly;
8. security review for tenancy, authorization recheck, query inputs, links, error isolation, and logs;
9. verification-before-completion evidence review.

Perform reviews sequentially. Do not invoke the parallel `code-review` skill unless the user separately approves the repository's optional parallel-review gate.

- [ ] **Step 8: Perform reuse and dead-code audits**

Confirm reuse of Phase 2 rollout/shell services, existing domain policies/services, current approval pages, canonical layouts, existing tokens, and existing Inertia patterns. Scan changed files for unused imports, duplicate status rules, unbounded queries, stale placeholders, alternate identity fields, direct mutation code, temporary TODOs, and orphaned test helpers.

- [ ] **Step 9: Run final quality gates**

```powershell
git diff --check
php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter tests/Feature/ShopOwner/CanonicalShell --compact
pnpm run test:frontend
pnpm run build
```

Expected: all commands PASS. Run `composer test` as an additional broader gate when practical and report its result separately.

- [ ] **Step 10: Commit rollout evidence**

```powershell
git add -- tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php docs/shop-owner-phase-3a-rollout-guide.md scripts/browser-smoke.mjs docs/ai-learning-log.md
git commit -m "docs: add phase 3a rollout evidence"
```

Stage optional files only if this task actually changed them.

## Final Phase 3A acceptance gate

Do not call Phase 3A complete or expand its allowlist until evidence proves:

- Order Refund, Repair Refund, Expense, and Purchase Request adapters independently pass readiness tests;
- Phase 3 is selected only inside a healthy Phase 2 canonical-shell selection and by its own global flag plus stable shop allowlist;
- Home and `/shop-owner/action-center` use the same inclusion, identity, count, filter, and ordering contract;
- owner-facing counts are distinct by `attention_key` and aggregate by coverage source;
- adapter failures are isolated, partial totals are labeled, and failure is never represented as zero work;
- all adapter queries are bounded, tenant-scoped, side-effect free, and free of per-item N+1 behavior;
- every card opens the existing owner-safe workflow and that destination rechecks tenancy, authorization, and source state;
- the Action Center contains no domain mutation controls or persisted state;
- existing approval pages and mutation behavior remain functional;
- rollback returns only the Phase 3 enhancement to Phase 2 placeholders without changing routes, domain data, authorization, or the selected canonical shell;
- unsupported 3B/3C buckets and sources are not represented as available functionality;
- exact verification commands and results are recorded.

Completion of this gate means **Phase 3A is complete**. Phase 3 remains open for separately designed and implemented Phase 3B and Phase 3C work.
