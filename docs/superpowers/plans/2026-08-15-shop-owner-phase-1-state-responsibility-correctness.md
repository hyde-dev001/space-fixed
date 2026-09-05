# Shop Owner Phase 1 State and Responsibility Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Canonicalize Shop Owner Phase 1 state, responsibility, Logistics authorization, and sign-in decisions without replacing mature domain services or changing primary owner navigation.

**Architecture:** Add small, side-effect-free domain policies and deterministic owner projections around authoritative records. Existing domain services remain the mutation boundary. Migrate callers incrementally behind report-first reconciliation and characterization tests, then tighten authorization and validation only after data, route, and UI parity gates pass.

**Tech Stack:** PHP 8.2, Laravel 12, PHPUnit, MySQL/SQLite test database, Inertia 2, React 18, TypeScript 5.7, Vitest, Tailwind CSS 4, pnpm.

---

## Source specifications

- `docs/superpowers/specs/2026-08-15-shop-owner-phase-1-state-responsibility-correctness-design.md`
- `docs/superpowers/specs/2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md`

The focused specification is authoritative where it narrows the master design. The master design's Phase 1 Refund and Repair owner-projection obligation remains in scope only as a side-effect-free read-model addition; this plan does not rewrite either workflow.

## Non-negotiable implementation boundaries

- Do not introduce a generic state machine, universal workflow service, new dependency, or duplicate persisted owner-state table.
- Policies answer questions; they do not write, dispatch, notify, or call external systems.
- Mutation endpoints lock or reload current state and re-run policy/authorization checks.
- Preserve existing inventory, payment, Payroll, Refund, Repair, proof, event, notification, and audit services.
- Treat `individual` as an existing registration fact and owner-operated presentation hint, not a new permission category.
- Do not remove the legacy Order `refund` enum value or Employee legacy values until production reconciliation is disposition-complete.
- Do not deploy a narrowing database constraint in the same release that first reports incompatible data.
- Do not change primary Shop Owner navigation in Phase 1.
- Never log credentials, authentication identifiers, proof contents, or personal data in denial/reconciliation telemetry.

## Rollout tracks

This plan produces two independently deployable tracks:

1. **Observe and canonicalize:** characterization tests, report-only reconciliation, additive compatibility schema, policies, projections, caller migration, UI parity.
2. **Enforce:** apply reconciliation, disposition unresolved rows, enable stricter validation/authorization, observe denial rates, and only later add narrowing constraints if justified.

The implementation must stop between tracks if reconciliation, behavioral equivalence, or UI/server parity evidence is incomplete.

## Task 1: Lock current valid behavior and reconciliation inventory

**Files:**

- Create: `tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php`
- Create: `tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php`
- Create: `app/Console/Commands/ReconcileShopOwnerPhaseOneState.php`
- Create: `app/Services/Reconciliation/PhaseOneStateInventory.php`
- Reference: `app/Console/Commands/ReconcileLegacyShopDocuments.php`
- Reference: `app/Services/LegacyShopDocumentReconciler.php`

- [ ] **Step 1: Write characterization tests for facts that must survive refactoring**

Cover at minimum:

- shipped Order confirmation reaches `delivered` and keeps existing COD/payment side effects;
- direct/pickup completion reaches `completed` through the currently valid direct path;
- Refund requests accept the currently supported successful terminal outcomes;
- full Purchase Order receipt posts receipt/inventory effects and reaches `delivered`, not `completed`;
- explicit Purchase Order closure reaches `completed`;
- assigned riders can perform current custody actions and unassigned riders cannot;
- valid Customer/Staff and Shop Owner credentials reach their existing guard-specific destinations and 2FA flows.

- [ ] **Step 2: Run the characterization test and record the baseline**

Run:

```powershell
php artisan test tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php
```

Expected: PASS against current valid behavior. If a proposed assertion fails because current behavior is already defective, move that assertion to the relevant red test below and document the observed baseline in the test name or comment.

- [ ] **Step 3: Write failing tests for a default report-only inventory command**

The command contract is:

```text
shop-owner:reconcile-phase-one-state
  --domain=all|orders|employees
  --shop-owner-id=
  --chunk=500
  --apply
```

Tests must prove:

- no rows change without `--apply`;
- output contains a UUID run identifier and, per domain/shop scope, `examined`, `canonical`, `normalizable`, and `unresolved` counts;
- Order `refund` rows are unresolved unless an authoritative source proves the prior fulfillment outcome;
- Employee `on_leave`/`on-leave` rows are classified as normalizable to `active` while Leave records remain untouched;
- unknown values are unresolved and never guessed;
- a shop filter cannot inspect another shop's rows;
- rerunning report-only produces the same classification.

- [ ] **Step 4: Run the command test to verify RED**

Run:

```powershell
php artisan test tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php
```

Expected: FAIL because the command and inventory service do not exist.

- [ ] **Step 5: Implement the report-only inventory**

Use the existing legacy-document reconciler's command shape, chunking, and per-shop reporting conventions. Keep classification pure:

```php
return match ($order->getRawOriginal('status')) {
    'pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled'
        => ['classification' => 'canonical', 'reason' => null],
    'refund'
        => ['classification' => 'unresolved', 'reason' => 'legacy_refund_fulfillment_unknown'],
    default
        => ['classification' => 'unresolved', 'reason' => 'unknown_order_status'],
};
```

Use this documented two-key result shape in both domain classifiers. Do not add a reconciliation result hierarchy or framework.

- [ ] **Step 6: Re-run focused tests**

Run:

```powershell
php artisan test tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php
```

Expected: PASS, with report-only tests proving zero writes.

- [ ] **Step 7: Commit the observation boundary**

```powershell
git add -- app/Console/Commands/ReconcileShopOwnerPhaseOneState.php app/Services/Reconciliation/PhaseOneStateInventory.php tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php
git commit -m "test: characterize phase one state contracts"
```

## Task 2: Canonicalize Order decisions and owner projections

**Files:**

- Modify: `app/Enums/OrderStatus.php`
- Create: `app/Services/Orders/OrderTransitionPolicy.php`
- Create: `app/Services/Orders/OrderOwnerProjection.php`
- Create: `app/Services/Orders/OrderRefundOwnerProjection.php`
- Create: `app/Services/Repairs/RepairOwnerProjection.php`
- Create: `tests/Unit/Services/Orders/OrderTransitionPolicyTest.php`
- Create: `tests/Unit/Services/Orders/OrderOwnerProjectionTest.php`
- Create: `tests/Unit/Services/Orders/OrderRefundOwnerProjectionTest.php`
- Create: `tests/Unit/Services/Repairs/RepairOwnerProjectionTest.php`
- Reference: `app/Models/Order.php`
- Reference: `app/Models/OrderRefund.php`
- Reference: `app/Models/PosRefund.php`
- Reference: `app/Models/RepairRequest.php`

- [ ] **Step 1: Write the Order transition matrix test**

Use a data provider to cover every source/target pair. Explicitly assert:

```text
pending -> processing                 allowed
processing -> shipped                 allowed
shipped -> delivered                  allowed
pending -> completed                  direct/pickup context only
processing -> completed               direct/pickup context only
delivered <-> completed               denied in normal flow
any terminal -> ordinary nonterminal  denied
any -> refund                         denied as fulfillment transition
```

The direct-completion context must be a small domain value or explicit method argument, not a UI string that can bypass policy.

- [ ] **Step 2: Write projection tests**

`OrderOwnerProjection` must return `business_closed` only when:

- fulfillment is `delivered` or `completed`; and
- no authoritative open Refund, Return, or Payment condition exists.

Start with the statuses already persisted by the current models. Name each blocker in one private constant or method and test it directly. Failed/pending Refund or Return work keeps the case open; successful/cancelled/rejected terminal cases do not unless an existing domain rule says otherwise. Do not infer action authority from the projection.

`OrderRefundOwnerProjection` returns only presentation fields derived from current Refund records:

```php
[
    'case_state' => 'requested|under_review|approved|processing|succeeded|failed|rejected|cancelled',
    'return_state' => 'not_required|awaiting_return|in_transit|received|exception',
    'payout_state' => 'not_started|pending|processing|succeeded|failed',
    'waiting_on' => 'owner|finance|customer|logistics|staff|none',
    'owner_action_required' => true,
    'next_action' => 'review_refund',
    'material_failure_reason' => null,
]
```

`RepairOwnerProjection` maps existing Repair records into the master design's groups: `new_request`, `diagnosis_quote`, `awaiting_approval`, `in_progress`, `awaiting_parts`, `ready_for_customer`, `delivery_or_pickup`, `closed`, or `exception`. It must preserve raw status and decision-critical flags in the returned payload.

- [ ] **Step 3: Run unit tests to verify RED**

Run:

```powershell
php artisan test tests/Unit/Services/Orders/OrderTransitionPolicyTest.php tests/Unit/Services/Orders/OrderOwnerProjectionTest.php tests/Unit/Services/Orders/OrderRefundOwnerProjectionTest.php tests/Unit/Services/Repairs/RepairOwnerProjectionTest.php
```

Expected: FAIL because the policies/projections do not exist and `shipped` lacks complete enum presentation mappings.

- [ ] **Step 4: Implement the smallest pure policies/projections**

Keep transition methods intention-revealing:

```php
final class OrderTransitionPolicy
{
    public function canMarkProcessing(Order $order): bool;
    public function canMarkShipped(Order $order): bool;
    public function canConfirmDelivered(Order $order): bool;
    public function canCompleteDirectly(Order $order, bool $hasAuthoritativeDirectFulfillment): bool;
}
```

The service, not the request payload, derives `hasAuthoritativeDirectFulfillment` from the existing pickup/direct fulfillment record. Add complete `SHIPPED` handling to `OrderStatus::label()` and `badgeClass()`. Keep `REFUND` temporarily for legacy compatibility but exclude it from new canonical transitions.

- [ ] **Step 5: Re-run unit tests**

Run the command from Step 3.

Expected: PASS.

- [ ] **Step 6: Commit canonical Order decisions**

```powershell
git add -- app/Enums/OrderStatus.php app/Services/Orders app/Services/Repairs/RepairOwnerProjection.php tests/Unit/Services/Orders tests/Unit/Services/Repairs/RepairOwnerProjectionTest.php
git commit -m "feat: canonicalize order state decisions"
```

## Task 3: Move Order mutations behind named execution methods

**Files:**

- Create: `app/Services/Orders/OrderFulfillmentService.php`
- Modify: `app/Http/Controllers/ShopOwner/OrderController.php`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php`
- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Modify: `app/Http/Controllers/Api/RepairRefundWorkflowController.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Reference: `app/Models/AuditLog.php`
- Create: `tests/Feature/Orders/OrderFulfillmentPolicyTest.php`
- Create: `tests/Feature/Repair/RepairOwnerProjectionResponseTest.php`
- Modify: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`
- Modify: `tests/Feature/PaymentLifecycleFeatureTest.php`
- Modify: `tests/Feature/ProductOrderCancellationRefundDeadlineTest.php`

- [ ] **Step 1: Write failing endpoint tests for named transitions**

Prove:

- the owner/staff generic update endpoint cannot jump `pending -> shipped`, set `refund`, or alter a terminal outcome;
- shipment creation still occurs exactly once when a valid Order is marked shipped;
- stale concurrent transitions fail without duplicate shipment/payment/notification side effects;
- customer delivery confirmation still requires `shipped` and reaches `delivered`;
- direct completion requires authoritative pickup/direct fulfillment evidence;
- Refund eligibility remains separate and works from both supported terminal outcomes.
- ordinary status update cannot change `delivered` to `completed` or vice versa;
- an owner-only terminal-outcome correction requires a non-empty reason, locks and revalidates the Order, records previous/new state and actor in `AuditLog`, and leaves payment, Refund, Return, inventory, delivery, and proof evidence untouched.

- [ ] **Step 2: Run focused Order feature tests to verify RED**

```powershell
php artisan test tests/Feature/Orders/OrderFulfillmentPolicyTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php tests/Feature/PaymentLifecycleFeatureTest.php tests/Feature/ProductOrderCancellationRefundDeadlineTest.php
```

Expected: new tests FAIL because generic status updates still permit invalid jumps and callers write status independently.

- [ ] **Step 3: Implement `OrderFulfillmentService` as the shared write boundary**

Use explicit methods rather than a public arbitrary-target transition:

```php
final class OrderFulfillmentService
{
    public function markProcessing(Order $order, Authenticatable $actor): Order;
    public function markShipped(Order $order, Authenticatable $actor, array $shippingData): Order;
    public function completeDirectly(Order $order, Authenticatable $actor): Order;
    public function confirmDelivered(Order $order, Authenticatable $actor): Order;
    public function correctTerminalOutcome(Order $order, ShopOwner $actor, OrderStatus $target, string $reason): Order;
}
```

Each method must:

1. enter a transaction;
2. reload the Order with `lockForUpdate()`;
3. authorize tenant and actor action through existing policies/middleware;
4. ask `OrderTransitionPolicy` about current source state;
5. perform existing side effects exactly once;
6. retain existing events/notifications/audit behavior;
7. return the refreshed Order.

Expose terminal correction as a distinct named action through the existing owner Order boundary, not as another generic dropdown value. Permit only `delivered <-> completed`, require the owning authenticated Shop Owner and a reason, and write `AuditLog` in the same transaction. If the correction would require reversing financial, inventory, Refund, Return, delivery, or proof evidence, reject it and direct the caller to that domain's compensating workflow.

- [ ] **Step 4: Migrate the three controllers**

Replace controller-local transition tables and direct status writes. Keep request validation focused on action payloads. If compatibility requires the existing update-status URL, translate only recognized named actions at its boundary and return 422 for arbitrary targets.

- [ ] **Step 5: Wire Refund and Repair projections into existing payload transformers**

Add projected fields without renaming/removing current response keys. In `RepairRefundWorkflowController::transformApprovalRefund()`, merge the Refund projection and leave workflow mutation logic untouched. In `RepairWorkflowController::getWorkflowStatus()`, add the Repair projection under a new `owner_projection` key. Prove in `RepairOwnerProjectionResponseTest` that legacy response keys and the new projection coexist.

- [ ] **Step 6: Re-run focused tests**

Run the command from Step 2 plus:

```powershell
php artisan test tests/Unit/Services/Orders tests/Unit/Services/Repairs/RepairOwnerProjectionTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit the Order execution boundary**

```powershell
git add -- app/Services/Orders/OrderFulfillmentService.php app/Http/Controllers/ShopOwner/OrderController.php app/Http/Controllers/Api/StaffOrderController.php app/Http/Controllers/UserSide/OrderController.php app/Http/Controllers/Api/RepairRefundWorkflowController.php app/Http/Controllers/Api/RepairWorkflowController.php tests/Feature/Orders tests/Feature/Repair/RepairOwnerProjectionResponseTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php tests/Feature/PaymentLifecycleFeatureTest.php tests/Feature/ProductOrderCancellationRefundDeadlineTest.php
git commit -m "refactor: route order transitions through domain service"
```

## Task 4: Align Order frontend types, labels, and commercial-closure consumers

**Files:**

- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- Modify: `resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx`
- Modify: `app/Http/Controllers/ShopOwner/DashboardController.php`
- Modify: `app/Http/Controllers/ShopOwner/DssController.php`
- Modify: `app/Http/Controllers/Api/ReportShopController.php`
- Modify: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`
- Create: `resources/js/Pages/ShopOwner/Orders/order management/__tests__/JobOrders.stateContract.test.tsx`
- Modify: `tests/Feature/ShopOwnerDashboardRevenueTest.php`

- [ ] **Step 1: Add failing UI tests**

Assert that:

- TypeScript status unions contain both `shipped` and `completed`;
- `completed` is not rewritten to `delivered`;
- labels/badges come from one local canonical presentation map where server enum labels are not provided;
- available actions come from server projection/action props, not client-side transition inference;
- dashboard closed-order counts honor `business_closed`, including a terminal Order with an open blocking Refund.

- [ ] **Step 2: Run the focused tests to verify RED**

```powershell
pnpm run test:frontend -- resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts
php artisan test tests/Feature/ShopOwnerDashboardRevenueTest.php
```

Expected: FAIL on the current completed-to-delivered rewrite and locally grouped closure metric.

- [ ] **Step 3: Make the minimum presentation changes**

Remove the `completed -> delivered` conversion. Consume server-provided projected/action fields where available. For aggregate SQL, centralize the same authoritative blocker semantics in an Order query scope rather than loading each Order into PHP.

- [ ] **Step 4: Re-run focused frontend/backend tests**

Run the command from Step 2.

Expected: PASS.

- [ ] **Step 5: Commit Order presentation alignment**

```powershell
git add -- resources/js/Pages/ERP/STAFF/JobOrders.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts "resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx" "resources/js/Pages/ShopOwner/Orders/order management/__tests__/JobOrders.stateContract.test.tsx" app/Http/Controllers/ShopOwner/DashboardController.php app/Http/Controllers/ShopOwner/DssController.php app/Http/Controllers/Api/ReportShopController.php tests/Feature/ShopOwnerDashboardRevenueTest.php app/Models/Order.php
git commit -m "fix: align order fulfillment presentation"
```

## Task 5: Canonicalize Employee operational decisions safely

**Files:**

- Modify: `app/Enums/EmployeeStatus.php`
- Create: `app/Services/HR/EmployeeOperationalPolicy.php`
- Create: `app/Services/HR/EmployeeOwnerProjection.php`
- Create: `database/migrations/2026_08_15_000001_expand_employee_status_enum_for_canonical_states.php`
- Modify: `app/Services/Reconciliation/PhaseOneStateInventory.php`
- Create: `tests/Unit/Services/HR/EmployeeOperationalPolicyTest.php`
- Create: `tests/Unit/Services/HR/EmployeeOwnerProjectionTest.php`
- Modify: `tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php`
- Modify: `tests/Unit/Models/EmployeeTest.php`

- [ ] **Step 1: Write failing policy/projection tests**

Test the matrix:

```text
active      login yes, assignment yes, routine payroll yes
inactive    login no,  assignment no,  routine payroll no, reactivation yes
suspended   login no,  assignment no,  compensation determined elsewhere
terminated  login no permanently, assignment no
on_leave    derived from approved Leave dates; account state remains active
probation   employment attribute; no account/access change by itself
```

The projection may expose `on_leave` and `probation` but must preserve canonical `account_state` and must not authorize mutations.

- [ ] **Step 2: Write migration compatibility tests**

The first migration is additive: MySQL Employee status accepts `terminated` while temporarily retaining `on_leave`. It must not remove legacy values. For SQLite tests, verify behavior through application validation because SQLite cannot reproduce MySQL enum semantics exactly.

- [ ] **Step 3: Run tests to verify RED**

```powershell
php artisan test tests/Unit/Services/HR/EmployeeOperationalPolicyTest.php tests/Unit/Services/HR/EmployeeOwnerProjectionTest.php tests/Unit/Models/EmployeeTest.php tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php
```

Expected: FAIL because the policy/projection and compatibility migration are absent.

- [ ] **Step 4: Implement the pure Employee policy and projection**

```php
final class EmployeeOperationalPolicy
{
    public function canAuthenticate(Employee $employee): bool;
    public function canReceiveNewAssignment(Employee $employee): bool;
    public function isEligibleForRoutinePayroll(Employee $employee): bool;
    public function isOnLeave(Employee $employee, CarbonInterface $date): bool;
}
```

Do not put final/corrective/retroactive compensation decisions in this policy; those remain Payroll-domain decisions.

- [ ] **Step 5: Implement only the additive schema change**

The migration must retain `active`, `inactive`, `on_leave`, `suspended`, and add `terminated`. Its `down()` must refuse or safely handle rows using `terminated`; do not silently remap them. Do not add the later narrowing migration in this task.

- [ ] **Step 6: Re-run focused tests**

Run the command from Step 3.

Expected: PASS.

- [ ] **Step 7: Commit Employee decision boundaries**

```powershell
git add -- app/Enums/EmployeeStatus.php app/Services/HR/EmployeeOperationalPolicy.php app/Services/HR/EmployeeOwnerProjection.php app/Services/Reconciliation/PhaseOneStateInventory.php database/migrations/2026_08_15_000001_expand_employee_status_enum_for_canonical_states.php tests/Unit/Services/HR tests/Unit/Models/EmployeeTest.php tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php
git commit -m "feat: define canonical employee operational state"
```

## Task 6: Migrate Employee access, assignment, Payroll, validation, and UI callers

**Files:**

- Modify: `app/Http/Requests/HR/StoreEmployeeRequest.php`
- Modify: `app/Http/Requests/HR/UpdateEmployeeRequest.php`
- Modify: `app/Http/Controllers/EmployeeController.php`
- Modify: `app/Http/Controllers/Erp/HR/EmployeeController.php`
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `app/Http/Middleware/CheckEmployeeSuspension.php`
- Modify: `app/Services/Logistics/AssignmentService.php`
- Modify: `app/Http/Controllers/Erp/HR/PayrollController.php`
- Modify: `app/Services/HR/PayrollService.php`
- Modify: `resources/js/types/hr.ts`
- Modify: `resources/js/Pages/ERP/HR/EmployeeDirectory.tsx`
- Modify: `tests/Feature/HR/EmployeeControllerTest.php`
- Modify: `tests/Feature/HR/PayrollControllerTest.php`
- Modify: `tests/Feature/Auth/SuspensionSessionEnforcementTest.php`
- Modify: `tests/Feature/Logistics/AssignmentServiceTest.php`
- Modify: `resources/js/Pages/ERP/HR/__tests__/EmployeeDirectory.ownerMode.test.ts`

- [ ] **Step 1: Add failing access and synchronization tests**

Prove deterministic linked-user behavior:

```text
employee active      -> linked user active
employee inactive    -> linked user inactive
employee suspended   -> linked user suspended
employee terminated  -> linked user inactive plus permanent Employee denial
```

Authentication must resolve the Employee through the authenticated User's shop-scoped relationship (matching both linked identity and `shop_owner_id`) and re-read Employee policy so stale linked-user state cannot grant access. Add a negative test proving an Employee row from another shop cannot affect the User's access, even if legacy identity fields collide. Generic login failure should remain privacy-safe.

- [ ] **Step 2: Add failing assignment and Payroll tests**

Prove:

- inactive/suspended/terminated Employees cannot receive a new Logistics assignment;
- existing assignments are not deleted or reassigned by status change;
- routine future Payroll generation excludes inactive Employees;
- corrective, retroactive, final, or already owed compensation paths can still include them when the Payroll workflow explicitly selects them;
- suspension alone does not erase payable compensation.

- [ ] **Step 3: Add failing request/UI tests**

All request validators and TypeScript unions must accept only `active|inactive|suspended|terminated` as account state. Leave and probation remain separate fields/projections. Remove `on-leave` and `on_leave` from writable account-state selectors.

- [ ] **Step 4: Run focused tests to verify RED**

```powershell
php artisan test tests/Feature/HR/EmployeeControllerTest.php tests/Feature/HR/PayrollControllerTest.php tests/Feature/Auth/SuspensionSessionEnforcementTest.php tests/Feature/Logistics/AssignmentServiceTest.php
pnpm run test:frontend -- resources/js/Pages/ERP/HR
```

Expected: FAIL on inconsistent validation, incomplete linked-user synchronization, and assignment policy reuse.

- [ ] **Step 5: Migrate callers to `EmployeeOperationalPolicy`**

Inject the policy into controllers/services. Synchronize linked-user state for every canonical Employee state. Keep existing assignments intact. In Payroll, call the policy only for routine generation; preserve explicit correction/final-pay code paths.

- [ ] **Step 6: Re-run focused tests**

Run the command from Step 4.

Expected: PASS.

- [ ] **Step 7: Commit Employee caller migration**

```powershell
git add -- app/Http/Requests/HR app/Http/Controllers/EmployeeController.php app/Http/Controllers/Erp/HR/EmployeeController.php app/Http/Controllers/UserController.php app/Http/Middleware/CheckEmployeeSuspension.php app/Services/Logistics/AssignmentService.php app/Http/Controllers/Erp/HR/PayrollController.php app/Services/HR/PayrollService.php resources/js/types/hr.ts resources/js/Pages/ERP/HR tests/Feature/HR tests/Feature/Auth/SuspensionSessionEnforcementTest.php tests/Feature/Logistics/AssignmentServiceTest.php
git commit -m "refactor: enforce employee operational policy"
```

## Task 7: Formalize Purchase Order receiving and closure categories

**Files:**

- Modify: `app/Models/PurchaseOrder.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderController.php`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx`
- Modify: `tests/Unit/Models/PurchaseOrderTest.php`
- Modify: `tests/Unit/Services/PurchaseOrderServiceTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx`

The existing `PurchaseOrder` model already contains focused state methods/scopes. Reuse it as the domain-specific decision boundary instead of adding a duplicative `PurchaseOrderStatePolicy` class.

- [ ] **Step 1: Add failing state-category tests**

Assert:

```text
active receiving = sent|confirmed|in_transit|partially_received
awaiting closure = delivered
completed = completed
cancelled = cancelled
```

Also prove:

- repeated partial receipts remain active;
- full receipt reaches `delivered` and posts inventory/receipt effects once;
- only explicit closure moves `delivered -> completed`;
- UI/metrics never infer closure from fully received quantities;
- normal cancellation is limited to draft/sent/confirmed;
- completed Purchase Orders cannot use normal receipt void/reversal;
- reopening is not an ordinary status edit.

- [ ] **Step 2: Run focused tests to verify RED**

```powershell
php artisan test tests/Unit/Models/PurchaseOrderTest.php tests/Unit/Services/PurchaseOrderServiceTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php
pnpm run test:frontend -- resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx
```

Expected: backend lifecycle tests mostly characterize existing behavior; new metric/UI assertions FAIL because `Awaiting Closure` is absent or locally grouped.

- [ ] **Step 3: Centralize category scopes and metrics**

Keep named model methods/scopes such as `active()`, `awaitingClosure()`, `completed()`, and `cancellable()`. Have both controller and service metrics call the same scopes. Add `awaiting_closure_orders` without removing old response keys until all callers migrate.

- [ ] **Step 4: Update the Procurement page**

Show `Active Receiving`, `Awaiting Closure`, `Completed`, and `Cancelled`. Expose explicit closure only for delivered Purchase Orders. Do not add a generic status selector.

- [ ] **Step 5: Re-run focused tests**

Run the command from Step 2.

Expected: PASS.

- [ ] **Step 6: Commit Purchase Order contract alignment**

```powershell
git add -- app/Models/PurchaseOrder.php app/Services/PurchaseOrderService.php app/Services/PurchaseOrderReceiptService.php app/Http/Controllers/Erp/PurchaseOrderController.php resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx tests/Unit/Models/PurchaseOrderTest.php tests/Unit/Services/PurchaseOrderServiceTest.php tests/Feature/Procurement
git commit -m "fix: distinguish purchase order receipt and closure"
```

## Task 8: Establish the Logistics action-capability matrix

**Files:**

- Create: `docs/architecture/shop-owner-phase-1-logistics-action-matrix.md`
- Create: `app/Services/Logistics/LogisticsActorPolicy.php`
- Create: `app/Enums/Logistics/LogisticsAction.php`
- Create: `tests/Unit/Services/Logistics/LogisticsActorPolicyTest.php`
- Reference: `routes/web.php`
- Reference: `routes/shop-owner-erp.php`
- Reference: `config/shop_modules.php`
- Reference: `app/Support/Erp/ErpActorContext.php`
- Reference: `app/Models/Logistics/RiderProfile.php`
- Reference: `app/Models/Logistics/DeliveryAssignment.php`

- [ ] **Step 1: Inventory every Logistics mutation in the matrix document**

For each route/action, record:

```text
route | source state | tenant rule | owner action | employee capability |
rider identity | active assignment | maker/checker | intended UI context
```

Classify at least:

- read/monitor;
- settings and rider administration;
- dispatch/assignment/scheduling/batch actions;
- owner-level exception resolution;
- rider custody progression, attempts, arrival, issue reporting, and proof submission;
- proof approval/rejection;
- return handoff and returned-parcel receipt.

Mark each current broad `shop_owner` bypass in `ShipmentController`, `DeliveryBatchController`, `DeliveryIncidentController`, `LogisticsSettingController`, and `RiderProfileController`.

- [ ] **Step 2: Write the pure policy matrix test**

Use table-driven tests for owner, employee dispatcher, reviewer, assigned rider, unassigned rider, and linked owner-rider. Required invariants:

- owner guard alone grants no mutation;
- owner dispatch/review actions require an explicit allowlisted action, enabled Logistics module, same shop, and valid source state;
- custody actions require an active rider profile representing the authenticated actor plus an active assignment for the exact leg;
- proof submitter is not automatically the proof reviewer;
- cross-shop records fail generically;
- denial results contain a non-sensitive reason category for observability.

- [ ] **Step 3: Run the policy test to verify RED**

```powershell
php artisan test tests/Unit/Services/Logistics/LogisticsActorPolicyTest.php
```

Expected: FAIL because the policy does not exist.

- [ ] **Step 4: Implement the explicit policy**

Use a backed enum shared by the policy, controllers, and tests:

```php
enum LogisticsAction: string
{
    case ASSIGN_RIDER = 'assign_rider';
    case SCHEDULE_DELIVERY = 'schedule_delivery';
    case RESOLVE_EXCEPTION = 'resolve_exception';
    case SUBMIT_PROOF = 'submit_proof';
    case REVIEW_PROOF = 'review_proof';
    case CONFIRM_RETURN_RECEIPT = 'confirm_return_receipt';
}
```

Do not infer owner permission merely from registration type. Existing module eligibility and actor context may establish whether the Logistics surface exists; the action allowlist establishes responsibility. Custody additionally resolves identity/assignment from authoritative rider records.

- [ ] **Step 5: Add structured denial logging at the enforcement boundary**

Log only `domain`, `action`, actor context/type, shop ID, denial category, route, and correlation/request ID. Do not log email, credentials, proof data, customer address, or free-text notes.

- [ ] **Step 6: Re-run policy tests and validate route coverage**

```powershell
php artisan test tests/Unit/Services/Logistics/LogisticsActorPolicyTest.php
php artisan route:list --path=api/logistics
```

Expected: PASS; every mutation in route output has a matrix row.

- [ ] **Step 7: Commit the Logistics contract boundary**

```powershell
git add -- docs/architecture/shop-owner-phase-1-logistics-action-matrix.md app/Services/Logistics/LogisticsActorPolicy.php app/Enums/Logistics/LogisticsAction.php tests/Unit/Services/Logistics/LogisticsActorPolicyTest.php
git commit -m "feat: define logistics actor responsibility policy"
```

## Task 9: Enforce Logistics policy in API mutation endpoints

**Files:**

- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `app/Http/Controllers/Api/Logistics/DeliveryBatchController.php`
- Modify: `app/Http/Controllers/Api/Logistics/DeliveryIncidentController.php`
- Modify: `app/Http/Controllers/Api/Logistics/LogisticsSettingController.php`
- Modify: `app/Http/Controllers/Api/Logistics/RiderProfileController.php`
- Modify: `app/Services/Logistics/ProofService.php`
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php`
- Modify: `tests/Feature/Logistics/RiderTenantAuthorizationTest.php`
- Create: `tests/Feature/Logistics/ShopOwnerLogisticsResponsibilityTest.php`

- [ ] **Step 1: Write endpoint tests from every matrix row**

At minimum prove:

- authorized owner can monitor, assign/schedule, review proof, resolve an owner exception, and confirm return receipt for their shop;
- owner cannot submit rider proof merely because they use the owner guard;
- an owner acting as rider needs a valid owner rider profile and active assignment for that exact leg;
- linked profile without assignment is denied;
- assigned rider can submit but cannot self-approve unless a separate reviewer capability and maker/checker rule explicitly allows it;
- owner and employee reviewer can approve/reject where authorized but cannot fabricate custody progression;
- all cross-shop attempts fail without disclosing record ownership;
- stale/terminal source states fail before any side effect;
- existing proof idempotency remains intact.

- [ ] **Step 2: Run focused tests to verify RED**

```powershell
php artisan test tests/Feature/Logistics/ShopOwnerLogisticsResponsibilityTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php tests/Feature/Logistics/RiderTenantAuthorizationTest.php
```

Expected: new tests FAIL where controllers currently return early for the owner guard.

- [ ] **Step 3: Replace broad owner bypasses endpoint by endpoint**

Inject `LogisticsActorPolicy`; do not add a new broad middleware that obscures source-state/assignment checks. Preserve service transactions and locks. `ProofService::recordProof()` must always receive the resolved assigned rider for custody proof; a null rider is reserved for authoritative non-custody proof types already allowed by domain rules.

- [ ] **Step 4: Keep proof submission and review distinct**

At approval/rejection endpoints, reject the original submitter when current maker/checker rules require separation. Preserve proof evidence and review audit fields. Return 403 for responsibility/capability denial and 404/generic denial for cross-tenant lookup according to current repository convention.

- [ ] **Step 5: Re-run Logistics tests**

Run the command from Step 2 plus:

```powershell
php artisan test tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/AssignmentServiceTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit Logistics endpoint enforcement**

```powershell
git add -- app/Http/Controllers/Api/Logistics app/Services/Logistics/ProofService.php tests/Feature/Logistics
git commit -m "fix: enforce logistics action responsibility"
```

## Task 10: Make Logistics UI and server capabilities agree

**Files:**

- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Dashboard.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Riders.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php`

- [ ] **Step 1: Write failing server-prop and UI tests**

Prove:

- company-owner context exposes authorized dispatch/review actions but not custody controls;
- individual owner with a valid active assigned owner-rider context can reach the existing delivery execution surface and sees only assigned custody actions;
- an owner without that rider context cannot see proof-submission/progression controls;
- frontend does not override a true server capability merely because `ownerMode` is true;
- frontend never manufactures a capability the server set false;
- primary navigation remains unchanged.

- [ ] **Step 2: Run focused tests to verify RED**

```powershell
php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php
pnpm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: FAIL because `ErpLogisticsController` and `Shipments.tsx` blanket-disable owner capabilities.

- [ ] **Step 3: Derive capabilities from the server policy**

Remove `! $ownerMode` and `!ownerMode` blanket suppressions. Return explicit props for dispatch, update, proof submission, proof review, and rider mode. Use an explicit trusted rider-mode context backed by the authenticated owner's RiderProfile and active assignment; never enable rider mode from a query parameter alone.

- [ ] **Step 4: Keep presentation adaptive**

Company owner defaults remain monitoring/review oriented. Individual owner-operated controls may be more prominent only when server capability props permit them. Do not add a new registration value, role, guard, or primary nav entry.

- [ ] **Step 5: Re-run UI/server parity tests**

Run the command from Step 2.

Expected: PASS.

- [ ] **Step 6: Commit Logistics parity**

```powershell
git add -- app/Http/Controllers/Logistics/ErpLogisticsController.php resources/js/Pages/ERP/Logistics tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "fix: align owner logistics capabilities and UI"
```

## Task 11: Separate authentication contexts behind one sign-in presentation

**Files:**

- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `app/Http/Controllers/ShopOwnerAuthController.php`
- Create: `tests/Feature/Auth/UnifiedSignInContextTest.php`
- Modify: `tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php`
- Modify: `tests/Feature/Auth/SuspensionSessionEnforcementTest.php`

- [ ] **Step 1: Write failing backend authentication tests**

Assert:

- both GET entry routes render `UserSide/Auth/UserLogin` with a trusted initial context;
- `/user/login` authenticates only the `user` guard;
- `/shop-owner/login` authenticates only the `shop_owner` guard;
- wrong context and invalid credentials return the same generic message/status;
- no handler probes or falls back to the other guard;
- an owner status-specific response occurs only after password verification;
- user/employee status checks remain guard-specific;
- each successful login regenerates the session;
- owner 2FA remains on the owner flow and user flow behavior is unchanged;
- `remember` is honored by each handler;
- both POST routes are rate limited consistently.

- [ ] **Step 2: Run auth tests to verify RED**

```powershell
php artisan test tests/Feature/Auth/UnifiedSignInContextTest.php tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Feature/Auth/SuspensionSessionEnforcementTest.php
```

Expected: FAIL because `UserController::login()` falls back to Shop Owner auth and the owner handler reveals status before verifying the password.

- [ ] **Step 3: Remove cross-guard fallback**

Delete the Shop Owner authentication branch and duplicate owner 2FA helpers from `UserController`. Keep owner behavior in `ShopOwnerAuthController`. Both GET routes render the same component with server-provided `initialAuthContext`.

- [ ] **Step 4: Make owner failure ordering enumeration-safe**

In `ShopOwnerAuthController`, verify selected-context credentials before returning account-specific pending/rejected/inactive information. Unknown email, wrong password, and wrong guard receive the same generic error. Do not compare credentials against another guard.

- [ ] **Step 5: Preserve session and rate-limit safeguards**

Use existing Laravel session regeneration and 2FA mechanisms. Add equivalent named or route throttle middleware to both POST routes if not already applied globally. Test the actual configured limit without asserting timing-sensitive internals.

- [ ] **Step 6: Re-run authentication tests**

Run the command from Step 2.

Expected: PASS.

- [ ] **Step 7: Commit authentication context separation**

```powershell
git add -- routes/web.php app/Http/Controllers/UserController.php app/Http/Controllers/ShopOwnerAuthController.php tests/Feature/Auth/UnifiedSignInContextTest.php tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Feature/Auth/SuspensionSessionEnforcementTest.php
git commit -m "fix: separate unified sign-in auth contexts"
```

## Task 12: Add the explicit account selector to the shared page

**Files:**

- Modify: `resources/js/Pages/UserSide/Auth/UserLogin.tsx`
- Create: `resources/js/Pages/UserSide/Auth/__tests__/UserLogin.test.tsx`

- [ ] **Step 1: Write failing component tests**

Assert:

- selector labels are `Customer / Staff` and `Shop Owner`;
- server-provided initial context selects the expected tab;
- changing tabs changes only the trusted POST target;
- Customer/Staff submits to `/user/login`;
- Shop Owner submits to `/shop-owner/login`;
- email, password, and remember values are preserved when switching context;
- no request is sent to both endpoints;
- error text does not suggest which account type owns an email;
- selector is keyboard accessible, labelled, and reports selected state.

- [ ] **Step 2: Run the component test to verify RED**

```powershell
pnpm run test:frontend -- resources/js/Pages/UserSide/Auth/__tests__/UserLogin.test.tsx
```

Expected: FAIL because the selector does not exist and the component always posts to `/user/login`.

- [ ] **Step 3: Implement the shared selector**

Use one component and a fixed route map:

```ts
const loginTargets = {
  user: '/user/login',
  shop_owner: '/shop-owner/login',
} as const;
```

The selected value controls routing only. It must not be derived by email lookup or credential probing. Submit `remember` consistently with the existing backend field.

- [ ] **Step 4: Re-run the component and auth tests**

```powershell
pnpm run test:frontend -- resources/js/Pages/UserSide/Auth/__tests__/UserLogin.test.tsx
php artisan test tests/Feature/Auth/UnifiedSignInContextTest.php tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit the unified sign-in presentation**

```powershell
git add -- resources/js/Pages/UserSide/Auth/UserLogin.tsx resources/js/Pages/UserSide/Auth/__tests__/UserLogin.test.tsx
git commit -m "feat: add explicit sign-in account selector"
```

## Task 13: Add reconciliation apply mode and rollout gates

**Files:**

- Modify: `app/Console/Commands/ReconcileShopOwnerPhaseOneState.php`
- Modify: `app/Services/Reconciliation/PhaseOneStateInventory.php`
- Create: `app/Services/Reconciliation/PhaseOneStateReconciler.php`
- Modify: `tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php`
- Create: `docs/runbooks/shop-owner-phase-1-state-rollout.md`

- [ ] **Step 1: Write failing apply/idempotency tests**

Assert:

- `--apply` updates only safe normalizable rows;
- Employee `on_leave` and `on-leave` normalize to `active` without deleting Leave records;
- ambiguous Order `refund` remains unresolved unless a tested authoritative source proves fulfillment;
- each shop/chunk is transactional;
- a failed chunk rolls back that chunk without reverting completed prior chunks;
- a second apply run reports zero additional normalizations;
- unresolved rows include a disposition state and block enforcement;
- output and structured logs include domain, shop, counts, and run ID without PII.

- [ ] **Step 2: Run reconciliation tests to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php
```

Expected: FAIL because apply mode is not implemented.

- [ ] **Step 3: Implement bounded apply mode**

Use `lazyById()`/`chunkById()`, per-shop scoping, transactions, and `lockForUpdate()` where rows are mutated. Re-evaluate each row inside the transaction. Do not let `--apply` mutate unresolved rows.

- [ ] **Step 4: Write the rollout runbook**

Include exact gates:

1. deploy report-only command and additive Employee enum migration;
2. run report by domain and shop;
3. classify every unresolved row as manual correction, accepted legacy exception, deferred with owner/reason, or rollout blocker;
4. deploy policies/projections/caller migrations with compatibility responses;
5. run characterization and equivalence suites;
6. run `--apply` in bounded shop batches;
7. rerun report and require zero undispositioned rows;
8. enable Logistics/auth validation enforcement;
9. monitor denial categories and stop on unexplained spikes;
10. only in a later release, consider a migration removing Employee `on_leave` and eventually Order `refund` after all callers/data are proven canonical.

Document rollback: revert policy/presentation where possible; do not reverse correct normalization; repair incorrect normalization with a reviewed compensating command.

- [ ] **Step 5: Re-run reconciliation tests**

Run the command from Step 2.

Expected: PASS.

- [ ] **Step 6: Exercise report-only locally**

```powershell
php artisan shop-owner:reconcile-phase-one-state --domain=all
```

Expected: command completes without mutation and prints a run ID plus per-domain counts. Do not run `--apply` against a non-test database without explicit user approval.

- [ ] **Step 7: Commit reconciliation and runbook**

```powershell
git add -- app/Console/Commands/ReconcileShopOwnerPhaseOneState.php app/Services/Reconciliation tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php docs/runbooks/shop-owner-phase-1-state-rollout.md
git commit -m "feat: add phase one state reconciliation rollout"
```

## Task 14: Sequential review, broad verification, and documentation closeout

**Files:**

- Modify if durable learning exists: `docs/ai-learning-log.md`
- Review: every file changed by Tasks 1-13

- [ ] **Step 1: Run the required simplification review**

Apply `ponytail` sequentially:

- remove any generic workflow/reconciliation abstraction that serves only one real caller;
- reuse existing Purchase Order scopes/services, proof services, route catalog, and auth mechanisms;
- remove duplicate transition maps and owner-mode capability overrides created obsolete by canonical policy;
- add no dependency;
- remove only dead code made obsolete by this change.

Record: PASS, or finding/resolved with the changed file.

- [ ] **Step 2: Run sequential Standards and Spec reviews**

Standards review:

- Laravel validation, dependency injection, transactions, locks, policies, tenant scopes, migrations, tests, React typing, and repository naming conventions.

Spec review:

- check all 23 focused acceptance criteria;
- confirm inherited Refund/Repair projections are deterministic and did not rewrite workflows;
- confirm primary owner navigation did not change.

Record each criterion as PASS, N/A with reason, or finding/resolved.

- [ ] **Step 3: Run the risk/security review**

Apply `security-review` and relevant Laravel rules:

- no cross-guard credential probing or email enumeration;
- session regeneration, CSRF, 2FA, remember, and rate limiting preserved;
- Logistics mutations are tenant-, action-, state-, identity-, and assignment-scoped;
- proof maker/checker separation remains intact;
- no mass-assignment or validation regression;
- reconciliation cannot cross shops or mutate unresolved rows;
- logs contain no credentials, PII, proof contents, or free-text customer data.

Record findings and resolve them before continuing.

- [ ] **Step 4: Run TypeScript/React and code-splitting review**

For changed TS/TSX, apply the repository's `typescript-advanced-types` and `vercel-react-best-practices` checklist:

- simple discriminated unions for auth context/status/capabilities;
- no `any` added;
- no unsafe assertions masking server data;
- no unnecessary new component split or lazy import;
- capability props have one typed boundary.

Code splitting expected result: N/A unless a genuinely heavy conditional dependency was introduced.

- [ ] **Step 5: Run reuse and dead-code audits**

Check changed areas for unused imports, stale owner fallback helpers, controller-local transition maps, obsolete owner-mode suppressions, unreachable legacy branches, and abandoned TODOs. Confirm runtime references before deletion.

- [ ] **Step 6: Run diff hygiene and focused suites**

```powershell
git diff --check
php artisan test tests/Feature/Auth tests/Feature/Logistics tests/Feature/HR tests/Feature/Procurement tests/Feature/Orders tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php tests/Feature/ShopOwner/PhaseOneStateReconciliationCommandTest.php tests/Unit/Services/Orders tests/Unit/Services/HR tests/Unit/Services/Logistics
pnpm run test:frontend -- resources/js/Pages/UserSide/Auth resources/js/Pages/ERP/Logistics resources/js/Pages/ERP/HR resources/js/Pages/ERP/Procurement resources/js/Pages/ERP/STAFF
```

Expected: PASS and no whitespace errors.

- [ ] **Step 7: Run broad project checks**

```powershell
composer test
pnpm run test:frontend
pnpm run build
git diff --check
```

Expected: PASS. The repository has no committed TypeScript compiler configuration or frontend lint script; do not report standalone type-checking or linting as passed.

- [ ] **Step 8: Gauge improvements with evidence**

Record:

- number of controller/UI transition maps removed or migrated;
- reconciliation counts by domain/shop from a non-mutating run;
- number of Logistics mutation routes covered by the matrix and endpoint tests;
- before/after wrong-context auth test behavior;
- Purchase Order Awaiting Closure metric evidence;
- test/build results.

If a baseline was not captured, say `not measured`; do not invent a percentage.

- [ ] **Step 9: Update durable learning only if warranted**

Add only reusable project knowledge to `docs/ai-learning-log.md`, such as a confirmed schema/runtime mismatch or a stable domain invariant. Do not record one-off implementation notes or sensitive data.

- [ ] **Step 10: Commit final review fixes and documentation**

```powershell
git add -- docs/runbooks/shop-owner-phase-1-state-rollout.md docs/architecture/shop-owner-phase-1-logistics-action-matrix.md docs/ai-learning-log.md
git commit -m "docs: finalize shop owner phase one rollout"
```

Only include `docs/ai-learning-log.md` if this task actually changed it. Stage exact paths; do not stage unrelated working-tree changes.

## Deferred constraint release (separate approved deployment)

Do not implement this section until the rollout runbook's production evidence is complete and the user approves the constraint release.

- Add a new migration that removes Employee `on_leave` only after report/apply runs show zero incompatible or undispositioned rows and all writers accept canonical states.
- Remove Order `refund` from the enum only after every legacy row has an authoritative fulfillment disposition and every caller uses Refund records rather than fulfillment status.
- Run report-only reconciliation immediately before and after each narrowing migration.
- Abort migration when incompatible rows exist; never auto-map inside the constraint migration.
- Keep constraint release independently deployable and reversible at the schema layer where the database permits safe reversal.

## Completion evidence

The implementer may claim Phase 1 complete only with:

- all focused and broad commands above reported with exact pass/fail output;
- a completed 23-criterion spec review;
- a route-complete Logistics action matrix and UI/server parity tests;
- report-only and idempotent-apply reconciliation evidence;
- zero undispositioned rollout-blocking rows in the target environment, or an explicit statement that enforcement/constraint release remains pending;
- security review evidence for auth, authorization, tenancy, proof custody, and reconciliation;
- confirmation that primary Shop Owner navigation was not changed;
- a clean `git diff --check` for the delivered diff.
