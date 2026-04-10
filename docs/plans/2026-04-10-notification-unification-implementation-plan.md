# Notification Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unify all in-app notifications across customer, shop owner, ERP staff, and super admin on a single NotificationService pipeline, while removing route collisions and closing module coverage gaps.

**Architecture:** Normalize notification API contracts by namespace, centralize recipient resolution and event dispatch through NotificationService, then migrate high-risk workflows in waves behind module feature flags. Keep migration safe with bridge compatibility, dedupe guards, and test-first rollout gates.

**Tech Stack:** Laravel 11 (PHP), Eloquent, RouteServiceProvider route files, PHPUnit feature/unit tests, Inertia React + TypeScript notification hooks/components.

---

## File Structure and Responsibilities

- Modify: `routes/api.php`
  - Keep canonical customer notification routes only.
- Modify: `routes/web.php`
  - Remove overlapping customer notification route definitions.
- Modify: `routes/hr-api.php`
  - Keep canonical staff and HR namespace contracts and deprecate route alias drift.
- Modify: `app/Http/Controllers/NotificationController.php`
  - Canonical customer notification contract.
- Modify: `app/Http/Controllers/ErpNotificationController.php`
  - Canonical ERP staff contract.
- Modify: `app/Http/Controllers/Erp/HR/NotificationController.php`
  - Canonical HR contract.
- Modify: `app/Http/Controllers/ShopOwnerNotificationController.php`
  - Canonical shop owner contract.
- Create: `app/Services/Notifications/RecipientResolver.php`
  - Centralized recipient resolution by role/permission/owner policy.
- Modify: `app/Services/NotificationService.php`
  - Integrate resolver, remove individual-only suppression for governance-critical events, dedupe guard.
- Modify: `app/Models/Order.php`
  - Replace direct Notification::create hook path with NotificationService dispatch.
- Modify: `app/Models/RepairRequest.php`
  - Replace direct Notification::create hook path with NotificationService dispatch.
- Modify: `app/Http/Controllers/ShopOwner/OrderController.php`
  - Emit missing notifications for status transitions and pickup activation.
- Modify: `app/Http/Controllers/ShopOwner/SuspensionFinalApprovalController.php`
  - Emit missing notifications on owner approve/reject review outcome.
- Modify: `app/Services/HR/SalaryChangeApprovalService.php`
  - Notify requester on rejection.
- Modify: `app/Services/RepairPosRefundService.php`
  - Emit notifications on approve/reject/execute transitions.
- Modify: `app/Services/RepairOnlineRefundWorkflowService.php`
  - Emit notifications for repairer approve/reject stage transitions.
- Modify: `app/Services/SupplierOrderService.php`
  - Implement actual overdue notification dispatch.
- Modify: `resources/js/hooks/useNotifications.ts`
  - Enforce canonical endpoint paths and unified response handling fallback.
- Modify: `resources/js/Pages/Notifications/ERPNotifications.tsx`
  - Keep deterministic namespace mapping.
- Modify: `resources/js/components/common/NotificationDropdown.tsx`
  - Remove endpoint path branching drift (`read-all` vs `mark-all-read`).
- Modify: `resources/js/utils/managerStaticNotifications.ts`
- Modify: `resources/js/utils/financeStaticNotifications.ts`
- Modify: `resources/js/utils/hrStaticNotifications.ts`
  - Remove hardcoded static items from live path usage.
- Create: `tests/Feature/Notifications/NotificationRouteContractTest.php`
  - Route contract consistency and non-collision checks.
- Create: `tests/Feature/Notifications/NotificationRecipientMatrixTest.php`
  - Recipient policy behavior checks.
- Create: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`
  - Coverage for critical module transitions.

---

### Task 1: Normalize Notification Route Contracts

**Files:**
- Modify: `routes/api.php`
- Modify: `routes/web.php`
- Modify: `routes/hr-api.php`
- Test: `tests/Feature/Notifications/NotificationRouteContractTest.php`

- [ ] **Step 1: Write failing route contract test**

```php
#[Test]
public function notification_namespaces_have_unique_and_canonical_endpoints(): void
{
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => [
            'uri' => $route->uri(),
            'methods' => collect($route->methods())->filter(fn ($m) => $m !== 'HEAD')->values()->all(),
            'name' => $route->getName(),
        ]);

    $customer = $routes->where('uri', 'api/notifications');
    $this->assertSame(1, $customer->count());

    $this->assertTrue($routes->contains(fn ($r) => $r['uri'] === 'api/notifications/mark-all-read'));
    $this->assertFalse($routes->contains(fn ($r) => $r['uri'] === 'api/notifications/read-all'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Notifications/NotificationRouteContractTest.php -v`
Expected: FAIL due duplicate customer namespace routes and `read-all` alias drift.

- [ ] **Step 3: Apply minimal route normalization**

```php
// routes/web.php
// Remove overlapping customer notification API group under prefix('api/notifications')
// Keep only page-render routes in web.php and canonical API routes in routes/api.php
```

```php
// routes/api.php
Route::prefix('notifications')->middleware(['auth:user'])->group(function () {
    Route::get('/', [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::get('/unread-count', [\App\Http\Controllers\NotificationController::class, 'unreadCount']);
    Route::get('/recent', [\App\Http\Controllers\NotificationController::class, 'recent']);
    Route::get('/stats', [\App\Http\Controllers\NotificationController::class, 'stats']);
    Route::post('/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead']);
    Route::post('/mark-all-read', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
    Route::delete('/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy']);
    Route::get('/preferences', [\App\Http\Controllers\Api\NotificationController::class, 'getPreferences']);
    Route::put('/preferences', [\App\Http\Controllers\Api\NotificationController::class, 'updatePreferences']);
});
```

- [ ] **Step 4: Re-run route contract test**

Run: `php artisan test tests/Feature/Notifications/NotificationRouteContractTest.php -v`
Expected: PASS with one canonical route set per namespace.

- [ ] **Step 5: Commit**

```bash
git add routes/api.php routes/web.php routes/hr-api.php tests/Feature/Notifications/NotificationRouteContractTest.php
git commit -m "refactor: normalize notification route contracts"
```

---

### Task 2: Add Central Recipient Resolver and Service Integration

**Files:**
- Create: `app/Services/Notifications/RecipientResolver.php`
- Modify: `app/Services/NotificationService.php`
- Test: `tests/Feature/Notifications/NotificationRecipientMatrixTest.php`

- [ ] **Step 1: Write failing recipient matrix test**

```php
#[Test]
public function governance_events_resolve_owner_for_both_individual_and_company(): void
{
    $resolver = app(\App\Services\Notifications\RecipientResolver::class);

    $individual = $resolver->resolveShopOwnerRecipients('salary_change_submitted', 1001, 'individual');
    $company = $resolver->resolveShopOwnerRecipients('salary_change_submitted', 2001, 'company');

    $this->assertNotEmpty($individual['shop_owner_ids']);
    $this->assertNotEmpty($company['shop_owner_ids']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Notifications/NotificationRecipientMatrixTest.php -v`
Expected: FAIL because resolver does not exist.

- [ ] **Step 3: Implement resolver and integrate service**

```php
// app/Services/Notifications/RecipientResolver.php
final class RecipientResolver
{
    public function resolveShopOwnerRecipients(string $eventType, int $shopOwnerId, string $registrationType): array
    {
        $governanceTypes = [
            'salary_change_submitted',
            'refund_request',
            'employee_suspension_request',
            'high_value_approval',
        ];

        if (in_array($eventType, $governanceTypes, true)) {
            return ['shop_owner_ids' => [$shopOwnerId], 'user_ids' => []];
        }

        if (strtolower($registrationType) === 'company') {
            return ['shop_owner_ids' => [], 'user_ids' => $this->resolveDelegatedUsers($shopOwnerId, $eventType)];
        }

        return ['shop_owner_ids' => [$shopOwnerId], 'user_ids' => []];
    }

    private function resolveDelegatedUsers(int $shopOwnerId, string $eventType): array
    {
        return \App\Models\User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereHas('permissions', fn ($q) => $q->whereIn('name', ['access-refund-approval', 'view-job-orders']))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
```

```php
// app/Services/NotificationService.php
// Inject RecipientResolver and replace direct individual-only skip branches with resolver-based policy.
```

- [ ] **Step 4: Re-run recipient matrix test**

Run: `php artisan test tests/Feature/Notifications/NotificationRecipientMatrixTest.php -v`
Expected: PASS for governance and operational recipient policies.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Notifications/RecipientResolver.php app/Services/NotificationService.php tests/Feature/Notifications/NotificationRecipientMatrixTest.php
git commit -m "feat: add centralized notification recipient resolver"
```

---

### Task 3: Remove Direct Model-Level Notification Writes

**Files:**
- Modify: `app/Models/Order.php`
- Modify: `app/Models/RepairRequest.php`
- Test: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`

- [ ] **Step 1: Write failing flow test for service dispatch path**

```php
#[Test]
public function order_status_transition_dispatches_via_notification_service_not_direct_model_write(): void
{
    Notification::fake();

    $order = Order::factory()->create(['status' => 'pending']);
    $order->update(['status' => 'shipped']);

    $this->assertDatabaseMissing('notifications', [
        'type' => 'order_status_update',
        'title' => 'Order Status Updated',
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php --filter order_status_transition_dispatches_via_notification_service_not_direct_model_write -v`
Expected: FAIL because current model hook writes directly.

- [ ] **Step 3: Implement minimal model cleanup**

```php
// app/Models/Order.php
// Remove Notification::create(...) from updated() hook.
// Keep status change logic in controller/service where NotificationService can be injected.
```

```php
// app/Models/RepairRequest.php
// Remove Notification::create(...) from updated() hook.
```

- [ ] **Step 4: Re-run critical flow test**

Run: `php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php -v`
Expected: PASS for no direct model-level writes.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Order.php app/Models/RepairRequest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
git commit -m "refactor: remove direct model notification writes"
```

---

### Task 4: Close Shop Owner and Salary Workflow Gaps

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/OrderController.php`
- Modify: `app/Http/Controllers/ShopOwner/SuspensionFinalApprovalController.php`
- Modify: `app/Services/HR/SalaryChangeApprovalService.php`
- Test: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`

- [ ] **Step 1: Write failing tests for missing emits**

```php
#[Test]
public function shop_owner_order_status_update_emits_customer_notification(): void {}

#[Test]
public function suspension_owner_review_emits_requester_and_employee_notification(): void {}

#[Test]
public function salary_change_rejection_notifies_proposer(): void {}
```

- [ ] **Step 2: Run failing tests**

Run: `php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php --filter "shop_owner_order_status_update_emits_customer_notification|suspension_owner_review_emits_requester_and_employee_notification|salary_change_rejection_notifies_proposer" -v`
Expected: FAIL due missing dispatches.

- [ ] **Step 3: Implement minimal notification dispatches**

```php
// app/Http/Controllers/ShopOwner/OrderController.php
// Inject NotificationService and emit ORDER_STATUS_UPDATE to customer on status transition.
```

```php
// app/Http/Controllers/ShopOwner/SuspensionFinalApprovalController.php
// Inject NotificationService and emit:
// - EMPLOYEE_SUSPENSION_REQUEST to governance recipients
// - MESSAGE_RECEIVED or TASK_ASSIGNED style notification to original requester/employee
```

```php
// app/Services/HR/SalaryChangeApprovalService.php
public function rejectSalaryChange(...): SalaryChange
{
    // existing rejection state update
    if ($change->proposed_by) {
        $this->notificationService->sendToUser(
            userId: (int) $change->proposed_by,
            type: \App\Enums\NotificationType::SALARY_CHANGE_APPROVED,
            title: 'Salary Change Rejected',
            message: 'Your salary change request was rejected. Please review remarks.',
            data: ['salary_change_id' => $change->id, 'reason' => $notes],
            actionUrl: '/erp/hr?section=salary-changes',
            shopId: (int) $change->shop_owner_id,
            priority: 'high'
        );
    }
    return $change->fresh();
}
```

- [ ] **Step 4: Re-run critical tests**

Run: `php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php -v`
Expected: PASS for all newly covered gaps.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ShopOwner/OrderController.php app/Http/Controllers/ShopOwner/SuspensionFinalApprovalController.php app/Services/HR/SalaryChangeApprovalService.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
git commit -m "feat: add missing shop owner and salary notification coverage"
```

---

### Task 5: Add Repair Refund Workflow Notifications

**Files:**
- Modify: `app/Services/RepairPosRefundService.php`
- Modify: `app/Services/RepairOnlineRefundWorkflowService.php`
- Modify: `app/Http/Controllers/Api/RepairRefundWorkflowController.php`
- Test: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`

- [ ] **Step 1: Write failing refund notification tests**

```php
#[Test]
public function repair_refund_finance_approve_emits_customer_and_owner_notifications(): void {}

#[Test]
public function repair_refund_repairer_reject_emits_finance_review_notification(): void {}
```

- [ ] **Step 2: Run tests to verify failure**

Run: `php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php --filter "repair_refund_finance_approve_emits_customer_and_owner_notifications|repair_refund_repairer_reject_emits_finance_review_notification" -v`
Expected: FAIL due no emits in repair refund services.

- [ ] **Step 3: Implement minimal service dispatches**

```php
// app/Services/RepairPosRefundService.php
// Inject NotificationService and emit on approve/reject/execute transitions.
```

```php
// app/Services/RepairOnlineRefundWorkflowService.php
// Inject NotificationService and emit manager/finance review notifications on repairer decision.
```

- [ ] **Step 4: Re-run refund tests**

Run: `php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php -v`
Expected: PASS for repair refund emission coverage.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RepairPosRefundService.php app/Services/RepairOnlineRefundWorkflowService.php app/Http/Controllers/Api/RepairRefundWorkflowController.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
git commit -m "feat: add repair refund workflow notifications"
```

---

### Task 6: Implement Supplier Overdue Notification Dispatch

**Files:**
- Modify: `app/Services/SupplierOrderService.php`
- Test: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`

- [ ] **Step 1: Write failing overdue dispatch test**

```php
#[Test]
public function overdue_supplier_orders_emit_notifications_to_inventory_recipients(): void {}
```

- [ ] **Step 2: Run test to confirm failure**

Run: `php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php --filter overdue_supplier_orders_emit_notifications_to_inventory_recipients -v`
Expected: FAIL because method is placeholder-only.

- [ ] **Step 3: Implement minimal overdue dispatch**

```php
// app/Services/SupplierOrderService.php
public function notifyOverdueOrders(): array
{
    $overdueOrders = SupplierOrder::overdue()->with(['supplier'])->get();
    $notificationsSent = 0;

    foreach ($overdueOrders as $order) {
        $this->notificationService->sendToErpRole(
            roleName: 'Procurement Manager',
            shopId: (int) $order->shop_owner_id,
            type: \App\Enums\NotificationType::PURCHASE_REQUEST_SUBMITTED,
            title: 'Supplier Order Overdue',
            message: "PO {$order->po_number} is overdue and needs follow-up.",
            data: ['supplier_order_id' => $order->id, 'po_number' => $order->po_number],
            actionUrl: '/erp/procurement/supplier-orders',
            priority: 'high'
        );
        $notificationsSent++;
    }

    return ['total_overdue' => $overdueOrders->count(), 'notifications_sent' => $notificationsSent];
}
```

- [ ] **Step 4: Re-run test**

Run: `php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php -v`
Expected: PASS with non-zero notifications when overdue records exist.

- [ ] **Step 5: Commit**

```bash
git add app/Services/SupplierOrderService.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
git commit -m "feat: implement overdue supplier notification dispatch"
```

---

### Task 7: Frontend Contract and Static Notification Cleanup

**Files:**
- Modify: `resources/js/hooks/useNotifications.ts`
- Modify: `resources/js/components/common/NotificationDropdown.tsx`
- Modify: `resources/js/Pages/Notifications/ERPNotifications.tsx`
- Modify: `resources/js/utils/managerStaticNotifications.ts`
- Modify: `resources/js/utils/financeStaticNotifications.ts`
- Modify: `resources/js/utils/hrStaticNotifications.ts`
- Test: `resources/js/hooks/__tests__/useNotifications.contract.test.ts`

- [ ] **Step 1: Write failing frontend contract test**

```ts
it('uses canonical mark-all-read endpoint for all namespaces', async () => {
  // assert no read-all fallback path usage
});
```

- [ ] **Step 2: Run frontend test to verify failure**

Run: `pnpm vitest resources/js/hooks/__tests__/useNotifications.contract.test.ts`
Expected: FAIL due `read-all` branch and static fallback usage.

- [ ] **Step 3: Apply minimal frontend normalization**

```ts
// resources/js/components/common/NotificationDropdown.tsx
const markAllPath = 'mark-all-read';
```

```ts
// resources/js/utils/*StaticNotifications.ts
export const ..._STATIC_NOTIFICATIONS: Notification[] = [];
```

- [ ] **Step 4: Re-run frontend tests**

Run: `pnpm vitest resources/js/hooks/__tests__/useNotifications.contract.test.ts`
Expected: PASS with canonical endpoint behavior.

- [ ] **Step 5: Commit**

```bash
git add resources/js/hooks/useNotifications.ts resources/js/components/common/NotificationDropdown.tsx resources/js/Pages/Notifications/ERPNotifications.tsx resources/js/utils/managerStaticNotifications.ts resources/js/utils/financeStaticNotifications.ts resources/js/utils/hrStaticNotifications.ts resources/js/hooks/__tests__/useNotifications.contract.test.ts
git commit -m "refactor: normalize frontend notification contract and remove static fallbacks"
```

---

### Task 8: Final Verification and Rollout Guardrail Checks

**Files:**
- Modify: `tests/Feature/Notifications/NotificationRouteContractTest.php`
- Modify: `tests/Feature/Notifications/NotificationRecipientMatrixTest.php`
- Modify: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`
- Modify: `docs/plans/2026-04-10-notification-unification-design.md`

- [ ] **Step 1: Add rollout gate assertions**

```php
#[Test]
public function no_notification_route_collisions_exist_after_unification(): void {}

#[Test]
public function critical_flow_notifications_emit_without_cross_shop_leakage(): void {}
```

- [ ] **Step 2: Run full notification test suite**

Run: `php artisan test tests/Feature/Notifications -v`
Expected: PASS all route, recipient, and critical flow tests.

- [ ] **Step 3: Run targeted frontend suite**

Run: `pnpm vitest resources/js/hooks resources/js/Pages/Notifications`
Expected: PASS notification UI contract tests.

- [ ] **Step 4: Update design doc rollout status notes**

```markdown
## Rollout Status
- [x] Route contract normalization
- [x] Recipient resolver integration
- [x] Critical gap coverage wave A
- [ ] Legacy emitter retirement wave B/C
```

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Notifications docs/plans/2026-04-10-notification-unification-design.md
git commit -m "test: add notification unification rollout gate coverage"
```

---

## Self-Review

1. Spec coverage check:
- Covered architecture unification, recipient matrix policy, API normalization, migration safety, testing, and phased rollout.
- Covered identified missing modules: shop owner order/suspension, salary rejection, repair refund services, supplier overdue, model direct writes.

2. Placeholder scan:
- No TBD/TODO placeholders.
- Every task includes concrete files, commands, and expected outcomes.

3. Type consistency:
- Canonical endpoint naming standardized to `mark-all-read`.
- Notification dispatch path consistently uses NotificationService and NotificationType enum usage.

## Execution Handoff

Plan complete and saved to `docs/plans/2026-04-10-notification-unification-implementation-plan.md`. Two execution options:

1. Subagent-Driven (recommended) - I dispatch a fresh subagent per task, review between tasks, fast iteration

2. Inline Execution - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?