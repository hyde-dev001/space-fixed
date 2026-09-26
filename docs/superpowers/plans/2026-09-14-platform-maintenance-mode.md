# SoleSpace Platform Maintenance Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a server-authoritative global maintenance mode with scheduled/emergency lifecycle controls, pre-maintenance warnings and critical-operation freeze, privileged recovery access, and safe user restoration.

**Architecture:** Persist maintenance windows in one table, derive effective state from UTC timestamps through one resolver, and enforce it in one global HTTP middleware. Reuse the existing Admin capability/audit/reauthentication stack for lifecycle commands and mount one React provider for warnings, countdowns, maintenance navigation, and recovery. The scheduler only records timestamp-driven transitions; request authorization never waits for it.

**Tech Stack:** Laravel 12, PHP 8.2, MySQL named advisory locks, Eloquent, Laravel Cache/Scheduler, Inertia 2, React 18, TypeScript 5.7, Axios, Vitest, Tailwind CSS 4, pnpm

---

## Scope and implementation constraints

- Implement only Core V1 from `docs/superpowers/specs/2026-09-14-platform-maintenance-mode-design.md`.
- Do not implement Shoe Catch, drafts in browser storage, per-shop maintenance, WebSockets, campaigns, or automatic write replay.
- Reuse `SuperAdmin` fixed capabilities, `PrivilegedAudit`, privileged correlation IDs, recent reauthentication, the Admin shell/sidebar, `ApplicationProviders`, global Axios setup, and existing modal/button styles.
- Add no dependency and no second maintenance audit/state system.
- Keep tests compatible with the repository's SQLite test database. Mock the concrete MySQL advisory-lock helper in ordinary feature tests; add one MySQL-only integration test that skips elsewhere.
- Work around unrelated dirty-tree changes. Stage and commit only files named in each task.

## File map

### Backend state and lifecycle

- Create `app/Enums/MaintenanceStatus.php` — persisted status values and terminal-state check.
- Create `app/Models/MaintenanceWindow.php` — casts, relationships, and factory support only.
- Create `database/factories/MaintenanceWindowFactory.php` — focused lifecycle test states.
- Create `database/migrations/2026_09_14_000001_create_maintenance_windows_table.php` — additive canonical table and indexes.
- Create `app/Services/MaintenanceStateService.php` — effective-state calculation, deterministic selection, cache boundary handling, and safe public projection.
- Create `app/Services/MaintenanceCommandLock.php` — concrete MySQL `GET_LOCK`/`RELEASE_LOCK` helper.
- Create `app/Services/MaintenanceLifecycleService.php` — all lifecycle mutations, overlap/version checks, transactions, audit, and after-commit cache invalidation.
- Create `app/Exceptions/MaintenanceConflictException.php` — one domain conflict carrying a stable public reason code.
- Modify `app/Services/PrivilegedAudit.php` — maintenance human/system events using the existing privileged log.

### HTTP, scheduling, and authorization

- Create `config/platform_maintenance.php` — fixed defaults plus exact bypass/freeze route-name registries.
- Create `app/Http/Middleware/EnforcePlatformMaintenance.php` — active/freeze request-entry decisions and failure-safe responses.
- Create `app/Http/Controllers/SystemMaintenanceController.php` — public status and maintenance page.
- Create `app/Http/Controllers/superAdmin/MaintenanceController.php` — Admin read projection and Super Admin commands.
- Create `app/Console/Commands/ReconcilePlatformMaintenance.php` — timestamp transition persistence.
- Modify `bootstrap/app.php` — place enforcement in web/API stacks before application authorization work.
- Modify `routes/web.php` — public pages, Admin controls, route names, and logistics names.
- Modify `routes/api.php` — PayMongo webhook name and currently unnamed critical route names.
- Modify `routes/console.php` — minute reconciliation schedule.
- Modify `app/Models/SuperAdmin.php` — view/manage maintenance capabilities.

### Frontend

- Create `resources/js/types/maintenance.ts` — public status contract and context types.
- Create `resources/js/providers/MaintenanceProvider.tsx` — one polling source, server clock offset, dirty registration, and single-flight maintenance transition.
- Create `resources/js/components/maintenance/MaintenanceBanner.tsx` — persistent warning/freeze banner.
- Create `resources/js/components/maintenance/MaintenanceWarningModal.tsx` — once-per-window five-minute warning.
- Create `resources/js/Pages/Maintenance.tsx` — active, unavailable, restored, Check Again, and Safe Return states.
- Create `resources/js/Pages/superAdmin/Maintenance/Index.tsx` — capability-aware Admin controls/history.
- Modify `resources/js/app.jsx` — mount the provider once.
- Modify `resources/js/bootstrap.js` — emit one canonical maintenance event for Axios 503 responses.
- Modify `resources/js/layout/AppSidebar.tsx` — capability-gated System Maintenance navigation.
- Modify the existing critical-operation pages listed in Task 10 — disable only initiation controls during freeze.

### Tests and documentation

- Add focused backend tests under `tests/Unit/Maintenance` and `tests/Feature/Maintenance`.
- Extend existing Super Admin capability/audit tests.
- Add frontend tests beside the new provider/components/pages and extend affected page tests.
- Update `docs/ai-learning-log.md` only if implementation reveals a durable repository-specific lesson not already captured by the spec.

## Task 1: Lock the canonical route inventory

**Files:**
- Modify: `routes/api.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Maintenance/MaintenanceRouteRegistryTest.php`

- [ ] **Step 1: Write a failing route-name inventory test**

Create a table-driven feature test that resolves every critical route below by name and asserts it is POST. Add a second provider for the currently unnamed routes that also fixes their exact URI. This is the contract used by middleware; do not use prefix matching.

```php
#[DataProvider('criticalRoutes')]
public function test_critical_initiation_routes_have_stable_names(
    string $name,
): void {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($candidate) => $candidate->getName() === $name);

    $this->assertNotNull($route, "Missing route [{$name}].");
    $this->assertContains('POST', $route->methods());
}
```

Use this exact URI map for names added in this task:

```php
return [
    ['webhooks.paymongo', 'api/webhooks/paymongo'],
    ['payments.paymongo.create', 'api/paymongo-proxy'],
    ['api.orders.update-payment-link', 'api/orders/{id}/update-payment-link'],
    ['api.orders.retry-payment-session', 'api/orders/{id}/retry-payment-session'],
    ['api.customer.repairs.update-payment-link', 'api/customer/repairs/{id}/update-payment-link'],
    ['api.customer.repairs.retry-payment-session', 'api/customer/repairs/{id}/retry-payment-session'],
    ['api.customer.repairs.refunds.store', 'api/customer/repairs/{id}/refunds'],
    ['api.repair-pos.checkout', 'api/repair-pos/checkout'],
    ['api.repair-pos.refunds.store', 'api/repair-pos/refunds'],
    ['api.retail-pos.checkout', 'api/retail-pos/checkout'],
    ['api.retail-pos.refunds.store', 'api/retail-pos/refunds'],
    ['api.repairer.conversations.activate-payment', 'api/repairer/conversations/{conversation}/activate-payment'],
    ['api.repairer.repairs.activate-payment', 'api/repairer/repairs/{id}/activate-payment'],
    ['api.finance.approvals.approve', 'api/finance/approvals/{id}/approve'],
    ['api.finance.approvals.reject', 'api/finance/approvals/{id}/reject'],
    ['logistics.api.batches.store', 'api/logistics/batches'],
    ['logistics.api.legs.schedule', 'api/logistics/legs/schedule'],
    ['logistics.api.batches.offer', 'api/logistics/batches/{batch}/offer'],
];
```

The provider must include these existing named routes:

```text
checkout.create-order
orders.request-refund
hr.payroll.process
hr.payroll.thirteenth.release
hr.payroll.batch.generate
hr.payroll.batch.retry
finance.payslip_approval.disburse
procurement.purchase-requests.submit-finance
shop_owner.premium.checkout
shop_owner.premium.upgrade.confirm
```

It must also require these names for currently unnamed canonical routes:

```text
webhooks.paymongo
payments.paymongo.create
api.orders.update-payment-link
api.orders.retry-payment-session
api.customer.repairs.update-payment-link
api.customer.repairs.retry-payment-session
api.customer.repairs.refunds.store
api.repair-pos.checkout
api.repair-pos.refunds.store
api.retail-pos.checkout
api.retail-pos.refunds.store
api.repairer.conversations.activate-payment
api.repairer.repairs.activate-payment
api.finance.approvals.approve
api.finance.approvals.reject
logistics.api.batches.store
logistics.api.legs.schedule
logistics.api.batches.offer
```

The approval-decision registry is the following current named set:

```text
api.finance.approvals.approve
api.finance.approvals.reject
api.leave.approve
api.leave.reject
api.manager.suspension_requests.review
finance.expenses.approve
finance.expenses.reject
finance.payslip_approval.approve
finance.payslip_approval.batch_approve
finance.payslip_approval.final_approve
finance.payslip_approval.reject
finance.price-changes.approve
finance.price-changes.reject
finance.purchase-requests.approve
finance.purchase-requests.reject
finance.refunds.approve
finance.refunds.reject
finance.repair-price-changes.approve
finance.repair-price-changes.approve-final
finance.repair-price-changes.reject
hr.leave.approve
hr.leave.reject
hr.overtime.approve
hr.overtime.reject
hr.payroll.approve
hr.salary_changes.approve
hr.salary_changes.reject
inventory.request-material-approvals.approve
inventory.request-material-approvals.reject
procurement.purchase-requests.approve
procurement.purchase-requests.reject
procurement.replenishment-requests.accept
procurement.replenishment-requests.reject
procurement.stock-requests.approve
procurement.stock-requests.reject
shop-owner.employees.activate
shop_owner.expenses.approve
shop_owner.expenses.reject
shop_owner.finance.expenses.approve
shop_owner.finance.expenses.reject
shop_owner.payslip_approval.batch_final_approve
shop_owner.payslip_approval.final_approve
shop_owner.price-changes.approve
shop_owner.price-changes.reject
shop_owner.purchase-requests.approve
shop_owner.purchase-requests.reject
shop_owner.refunds.approve
shop_owner.refunds.reject
shop_owner.repair-price-changes.approve
shop_owner.repair-price-changes.reject
shop_owner.repair-refunds.approve
shop_owner.repair-refunds.reject
shop_owner.repairs.approve-high-value
shop_owner.repairs.reject-high-value
shop_owner.salary-changes.approve
shop_owner.salary-changes.reject
shop_owner.suspension_requests.review
```

If a route in this list no longer exists when the plan is executed, stop and reconcile the list with the approved spec rather than silently dropping the workflow. Admin-module approval routes are intentionally absent because all authenticated `admin.*` routes bypass platform maintenance.

Exclude previews, GET queues, payment verification callbacks, shipment progress/completion, receipt posting, refund execution, and PayMongo webhook handling from the freeze list.

- [ ] **Step 2: Run the inventory test and verify it fails**

Run:

```powershell
php artisan test tests/Feature/Maintenance/MaintenanceRouteRegistryTest.php
```

Expected: FAIL only for the listed unnamed routes.

- [ ] **Step 3: Assign names without changing middleware, URI, method, or controller**

Append `->name(...)` to the existing route declarations. Use `webhooks.paymongo` for the callback, but do not change its signature-verification controller or add a general webhook exemption.

- [ ] **Step 4: Re-run the route inventory**

Expected: PASS, and every critical name resolves to the original method/URI.

- [ ] **Step 5: Commit only route-contract changes**

```powershell
git add -- routes/api.php routes/web.php tests/Feature/Maintenance/MaintenanceRouteRegistryTest.php
git commit -m "test: define maintenance route boundaries"
```

## Task 2: Add the canonical maintenance record

**Files:**
- Create: `app/Enums/MaintenanceStatus.php`
- Create: `app/Models/MaintenanceWindow.php`
- Create: `database/factories/MaintenanceWindowFactory.php`
- Create: `database/migrations/2026_09_14_000001_create_maintenance_windows_table.php`
- Create: `tests/Unit/Maintenance/MaintenanceWindowTest.php`

- [ ] **Step 1: Write failing model/schema tests**

Cover enum casting, UTC datetime casts, integer version/freeze fields, nullable actor relationships, no soft-delete column, and factory states for Draft, Scheduled, Active, Ended, and Cancelled.

```php
public function test_maintenance_window_casts_its_canonical_fields(): void
{
    $window = MaintenanceWindow::factory()->scheduled()->create();

    $this->assertSame(MaintenanceStatus::Scheduled, $window->status);
    $this->assertInstanceOf(CarbonInterface::class, $window->starts_at);
    $this->assertIsInt($window->version);
    $this->assertFalse(Schema::hasColumn('maintenance_windows', 'deleted_at'));
}
```

- [ ] **Step 2: Run the model test and verify it fails**

```powershell
php artisan test tests/Unit/Maintenance/MaintenanceWindowTest.php
```

Expected: FAIL because the enum, table, model, and factory do not exist.

- [ ] **Step 3: Add the smallest enum and model**

`MaintenanceStatus` contains only the five persisted cases and:

```php
public function isTerminal(): bool
{
    return $this === self::Ended || $this === self::Cancelled;
}
```

`MaintenanceWindow` uses `HasFactory`, guarded/fillable fields consistent with nearby models, enum/datetime/integer casts, and five `belongsTo(SuperAdmin::class, ...)` actor relationships. Do not put effective-state logic in the model.

- [ ] **Step 4: Add the additive migration and factory**

The migration must create the exact spec fields, nullable `nullOnDelete()` actor foreign keys, indexes on `status`, `starts_at`, and `ends_at`, and no soft deletes. Enforce `starts_at < ends_at` and coherent warning/freeze values in service validation; do not add database syntax that breaks SQLite tests.

- [ ] **Step 5: Re-run the model test**

Expected: PASS.

- [ ] **Step 6: Commit the canonical record**

```powershell
git add -- app/Enums/MaintenanceStatus.php app/Models/MaintenanceWindow.php database/factories/MaintenanceWindowFactory.php database/migrations/2026_09_14_000001_create_maintenance_windows_table.php tests/Unit/Maintenance/MaintenanceWindowTest.php
git commit -m "feat: add maintenance window records"
```

## Task 3: Derive effective state and bounded snapshots

**Files:**
- Create: `config/platform_maintenance.php`
- Create: `app/Services/MaintenanceStateService.php`
- Create: `tests/Unit/Maintenance/MaintenanceStateServiceTest.php`

- [ ] **Step 1: Write failing boundary tests with frozen time**

Cover:

- Draft is never public or enforced.
- Cancelled and Ended override timestamps.
- Before `starts_at` is Scheduled; exactly at start is Active.
- Before `ends_at` is Active; exactly at end is Ended.
- Public selection is Active, otherwise nearest warned Scheduled, otherwise Operational.
- Adjacent windows do not affect state selection.
- `next_transition_at` is the earliest future warning, freeze, start, or end boundary.
- `expires_at = min(now + 30 seconds, next_transition_at)`.
- A snapshot is usable only when `now < expires_at`.
- A cached Operational result is not accepted as authorization after a canonical read failure.
- An unexpired Scheduled/Active snapshot may preserve the same or stricter result only until its next boundary.

Use `Carbon::setTestNow()` and restore it in `tearDown()`.

- [ ] **Step 2: Run the resolver test and verify it fails**

```powershell
php artisan test tests/Unit/Maintenance/MaintenanceStateServiceTest.php
```

Expected: FAIL because the service/config do not exist.

- [ ] **Step 3: Add minimal configuration**

`config/platform_maintenance.php` must contain only fixed operational settings and these exact registries:

```php
return [
    'cache_key' => 'platform_maintenance.snapshot',
    'max_cache_seconds' => 30,
    'notify_before_minutes' => 15,
    'transaction_freeze_minutes' => 3,
    'lock_name' => 'platform_maintenance_command',
    'lock_timeout_seconds' => 5,
    'bypass_route_names' => [
        'system.maintenance',
        'system.maintenance-status',
        'webhooks.paymongo',
    ],
    'bypass_paths' => ['up'],
    'bypass_route_prefixes' => ['admin.', 'superAdmin.'],
    'critical_route_names' => [
        'checkout.create-order',
        'orders.request-refund',
        'payments.paymongo.create',
        'api.orders.update-payment-link',
        'api.orders.retry-payment-session',
        'api.customer.repairs.update-payment-link',
        'api.customer.repairs.retry-payment-session',
        'api.customer.repairs.refunds.store',
        'api.repair-pos.checkout',
        'api.repair-pos.refunds.store',
        'api.retail-pos.checkout',
        'api.retail-pos.refunds.store',
        'api.repairer.conversations.activate-payment',
        'api.repairer.repairs.activate-payment',
        'shop_owner.repairs.activate-payment',
        'shop_owner.conversations.activate-payment',
        'shop_owner.premium.checkout',
        'shop_owner.premium.upgrade.confirm',
        'hr.payroll.process',
        'hr.payroll.thirteenth.release',
        'hr.payroll.batch.generate',
        'hr.payroll.batch.retry',
        'finance.payslip_approval.disburse',
        'procurement.purchase-requests.submit-finance',
        'logistics.api.batches.store',
        'logistics.api.legs.schedule',
        'logistics.api.batches.offer',
        'api.finance.approvals.approve',
        'api.finance.approvals.reject',
        'api.leave.approve',
        'api.leave.reject',
        'api.manager.suspension_requests.review',
        'finance.expenses.approve',
        'finance.expenses.reject',
        'finance.payslip_approval.approve',
        'finance.payslip_approval.batch_approve',
        'finance.payslip_approval.final_approve',
        'finance.payslip_approval.reject',
        'finance.price-changes.approve',
        'finance.price-changes.reject',
        'finance.purchase-requests.approve',
        'finance.purchase-requests.reject',
        'finance.refunds.approve',
        'finance.refunds.reject',
        'finance.repair-price-changes.approve',
        'finance.repair-price-changes.approve-final',
        'finance.repair-price-changes.reject',
        'hr.leave.approve',
        'hr.leave.reject',
        'hr.overtime.approve',
        'hr.overtime.reject',
        'hr.payroll.approve',
        'hr.salary_changes.approve',
        'hr.salary_changes.reject',
        'inventory.request-material-approvals.approve',
        'inventory.request-material-approvals.reject',
        'procurement.purchase-requests.approve',
        'procurement.purchase-requests.reject',
        'procurement.replenishment-requests.accept',
        'procurement.replenishment-requests.reject',
        'procurement.stock-requests.approve',
        'procurement.stock-requests.reject',
        'shop-owner.employees.activate',
        'shop_owner.expenses.approve',
        'shop_owner.expenses.reject',
        'shop_owner.finance.expenses.approve',
        'shop_owner.finance.expenses.reject',
        'shop_owner.payslip_approval.batch_final_approve',
        'shop_owner.payslip_approval.final_approve',
        'shop_owner.price-changes.approve',
        'shop_owner.price-changes.reject',
        'shop_owner.purchase-requests.approve',
        'shop_owner.purchase-requests.reject',
        'shop_owner.refunds.approve',
        'shop_owner.refunds.reject',
        'shop_owner.repair-price-changes.approve',
        'shop_owner.repair-price-changes.reject',
        'shop_owner.repair-refunds.approve',
        'shop_owner.repair-refunds.reject',
        'shop_owner.repairs.approve-high-value',
        'shop_owner.repairs.reject-high-value',
        'shop_owner.salary-changes.approve',
        'shop_owner.salary-changes.reject',
        'shop_owner.suspension_requests.review',
    ],
];
```

No environment variable is needed for fixed lifecycle semantics.

- [ ] **Step 4: Implement effective-state calculation and deterministic selection**

Expose focused methods:

```php
public function effectiveState(MaintenanceWindow $window, CarbonInterface $now): string;
public function resolve(CarbonInterface $now): array;
public function publicProjection(array $snapshot, CarbonInterface $now): array;
public function forget(): void;
```

Implement terminal override and exact `[starts_at, ends_at)` boundaries first. Query only Draft-excluded candidates needed to select the effective Active window and nearest Scheduled window.

- [ ] **Step 5: Add bounded internal snapshot caching**

Cache the internal timing snapshot, not rendered responses. Calculate `next_transition_at` and cap `expires_at` at 30 seconds without crossing warning, freeze, start, or end.

- [ ] **Step 6: Add public projection and failure behavior**

Hide future Scheduled windows until warning begins. On cache miss/unavailability, query the database. If the database fails, accept only an unexpired restrictive snapshot as defined by the spec; otherwise throw a `RuntimeException` consumed by middleware/status handling.

- [ ] **Step 7: Re-run boundary tests**

Expected: PASS.

- [ ] **Step 8: Commit state resolution**

```powershell
git add -- config/platform_maintenance.php app/Services/MaintenanceStateService.php tests/Unit/Maintenance/MaintenanceStateServiceTest.php
git commit -m "feat: resolve effective maintenance state"
```

## Task 4: Serialize and audit lifecycle commands

**Files:**
- Create: `app/Services/MaintenanceCommandLock.php`
- Create: `app/Services/MaintenanceLifecycleService.php`
- Create: `app/Exceptions/MaintenanceConflictException.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Create: `tests/Unit/Maintenance/MaintenanceCommandLockTest.php`
- Create: `tests/Feature/Maintenance/MaintenanceLifecycleServiceTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedAuditTest.php`

- [ ] **Step 1: Write failing lifecycle tests**

Mock `MaintenanceCommandLock` to execute the callback in SQLite tests. Cover create Draft, schedule, edit Draft/Scheduled, cancel, scheduled Start Now, direct emergency Start Now, extend, progress, public update, and End Now. Assert:

- Every command compares the submitted integer `version` and increments it once.
- Every command validates effective state, not persisted state alone.
- Scheduling requires future server time.
- Drafts may overlap.
- Scheduled/effectively Active half-open intervals conflict only when `a.starts_at < b.ends_at && a.ends_at > b.starts_at`.
- Start Now writes one captured server timestamp to `starts_at` and `activated_at`.
- Extension rechecks future conflicts.
- Progress is Active-only; public updates are Scheduled/Active-only.
- Ended/Cancelled records are immutable.
- Repeated activation/ending at the target state is a no-op without another audit row.
- Audit failure rolls back the state change.
- Cache forget runs after commit only.

- [ ] **Step 2: Run lifecycle tests and verify they fail**

```powershell
php artisan test tests/Feature/Maintenance/MaintenanceLifecycleServiceTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
```

- [ ] **Step 3: Implement the concrete MySQL advisory lock helper**

Use the canonical connection and parameter-bound `SELECT GET_LOCK(?, ?)` / `SELECT RELEASE_LOCK(?)`. Acquire before starting the transaction, execute the callback, and release in `finally`. Throw a controlled domain/runtime exception when acquisition fails. Do not create an interface or Redis fallback.

For non-MySQL test connections, fail explicitly unless the helper is mocked. Add a MySQL-only test marked skipped when `DB::connection()->getDriverName() !== 'mysql'` to prove lock acquire/release behavior without making the normal SQLite suite depend on MySQL.

- [ ] **Step 4: Add one stable domain-conflict exception**

`MaintenanceConflictException` carries only an allowlisted reason such as `stale_version`, `invalid_state`, `overlap`, or `lock_timeout`. Controllers map it to a safe 409 response. Do not create one exception class per command.

- [ ] **Step 5: Implement Draft creation, edit, schedule, and cancel commands**

Each public command wraps this sequence:

```text
advisory lock
  -> DB::transaction
  -> lock target and relevant Scheduled/effectively Active rows
  -> resolve effective state with one captured now()
  -> version/transition/overlap validation
  -> persist fields and actor
  -> write privileged audit event
  -> commit
  -> forget resolver cache
```

Capture server time once per command, validate version/effective state, and enforce overlap only when entering or changing Scheduled state.

- [ ] **Step 6: Implement scheduled and emergency activation**

Scheduled activation changes one existing record. Emergency activation creates one Active record in the same lock/transaction, requires an estimated end, and writes `starts_at` and `activated_at` from the same captured server time.

- [ ] **Step 7: Implement extend, progress, public-update, and end commands**

Extension rechecks future Scheduled conflicts. Progress is Active-only, public updates are Scheduled/Active-only, End uses effective state, and target-state repeats return without another audit event.

Throw `MaintenanceConflictException` with stable reasons for stale version, invalid state, overlap, and lock timeout. Keep HTTP concerns out of this service.

- [ ] **Step 8: Extend `PrivilegedAudit` without a second ledger**

Add one human maintenance writer and one scheduler maintenance writer that delegate to the existing private `write()` and `writeConsoleEvent()` paths. Accept only the allowlisted event names from the spec and safe previous/new lifecycle properties; omit unrestricted `internal_note` content.

- [ ] **Step 9: Re-run lifecycle/audit/lock tests**

Expected: PASS; the MySQL integration test is SKIPPED under SQLite and PASS under a MySQL test connection.

- [ ] **Step 10: Commit lifecycle behavior**

```powershell
git add -- app/Services/MaintenanceCommandLock.php app/Services/MaintenanceLifecycleService.php app/Exceptions/MaintenanceConflictException.php app/Services/PrivilegedAudit.php tests/Unit/Maintenance/MaintenanceCommandLockTest.php tests/Feature/Maintenance/MaintenanceLifecycleServiceTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
git commit -m "feat: add maintenance lifecycle commands"
```

## Task 5: Reconcile timestamp transitions without authorizing them

**Files:**
- Create: `app/Console/Commands/ReconcilePlatformMaintenance.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Maintenance/ReconcilePlatformMaintenanceTest.php`

- [ ] **Step 1: Write failing command tests**

Assert that the command:

- Persists due Scheduled to Active with `activated_at = starts_at`.
- Persists due Scheduled/Active to Ended with `ended_at = ends_at`.
- When one delayed run first sees a Scheduled window already past `ends_at`, records both canonical activation and ending transitions once before leaving the row Ended.
- Uses null actor foreign keys and a console/system privileged audit source.
- Writes each real transition once on repeated runs.
- Invalidates state cache only after a committed transition.
- Does not change access semantics before it runs; resolver tests already prove timestamp authority.

- [ ] **Step 2: Run and verify failure**

```powershell
php artisan test tests/Feature/Maintenance/ReconcilePlatformMaintenanceTest.php
```

- [ ] **Step 3: Add the command by reusing lifecycle locking/transaction rules**

Use the same concrete command lock and the same state/overlap rules. Generate one UUID correlation ID per command run. Avoid a fake Super Admin. Log only failures or material reconciliation lag.

- [ ] **Step 4: Schedule it once per minute**

Add to `routes/console.php`:

```php
Schedule::command('maintenance:reconcile')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
```

- [ ] **Step 5: Re-run the command test**

Expected: PASS.

- [ ] **Step 6: Commit reconciliation**

```powershell
git add -- app/Console/Commands/ReconcilePlatformMaintenance.php routes/console.php tests/Feature/Maintenance/ReconcilePlatformMaintenanceTest.php
git commit -m "feat: reconcile maintenance history"
```

## Task 6: Expose safe status and enforce requests centrally

**Files:**
- Create: `app/Http/Middleware/EnforcePlatformMaintenance.php`
- Create: `app/Http/Controllers/SystemMaintenanceController.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Maintenance/MaintenanceStatusEndpointTest.php`
- Create: `tests/Feature/Maintenance/MaintenanceEnforcementTest.php`

- [ ] **Step 1: Write failing status-contract tests**

Assert `GET /system/maintenance-status` returns `Cache-Control: no-store`, `server_time`, and only the approved public fields. Cover Active, warned Scheduled, Operational, and resolver unavailable. Assert internal notes and actor/audit fields are absent.

- [ ] **Step 2: Write failing enforcement tests**

Use temporary named test routes to assert:

- Protected browser navigation receives Inertia/HTML 503 maintenance content.
- API/JSON/write receives 503 with `code = MAINTENANCE_ACTIVE`, safe timing metadata, and `Retry-After` when useful.
- Active responses carry `X-SoleSpace-Maintenance: active` so the shared Inertia client can recognize the 503 without parsing HTML.
- A frozen exact route receives 409 `MAINTENANCE_FREEZE_ACTIVE`.
- A similarly prefixed but unlisted route remains allowed.
- Requests entering before maintenance are not interrupted mid-handler.
- Cache/database resolution failure returns generic 503, never Operational.
- `/maintenance`, status, health, Admin, legacy Super Admin, and named PayMongo webhook bypass.
- An unnamed callback is not automatically bypassed.

- [ ] **Step 3: Run and verify failure**

```powershell
php artisan test tests/Feature/Maintenance/MaintenanceStatusEndpointTest.php tests/Feature/Maintenance/MaintenanceEnforcementTest.php
```

- [ ] **Step 4: Add the public controller and routes**

Use one controller:

```text
GET /system/maintenance-status -> status() -> system.maintenance-status
GET /maintenance               -> page()   -> system.maintenance
```

`status()` returns 503 with `state: unavailable` if canonical resolution and a usable restrictive snapshot are both unavailable. `page()` renders `Maintenance` with the safe projection and canonical actor Safe Return destination; it does not accept a previous URL.

- [ ] **Step 5: Implement one enforcement middleware**

Decision order must exactly match the spec: route name/path bypass, resolve state, Active 503, exact freeze-name 409, otherwise continue. Bypass matching may use only the two approved privileged prefixes plus exact route names/paths from config. Do not exempt all API routes.

- [ ] **Step 6: Register middleware in both stacks**

Add it to the web and API middleware stacks in `bootstrap/app.php` before `CheckEmployeeSuspension`, ERP audience resolution, authentication, throttling, and route bindings where Laravel's routing phase still provides the matched route name. Add it to middleware priority immediately after session startup and before application authenticators. Keep a regression test proving named route access is available at enforcement time.

- [ ] **Step 7: Re-run status and enforcement tests**

Expected: PASS.

- [ ] **Step 8: Commit HTTP enforcement**

```powershell
git add -- app/Http/Middleware/EnforcePlatformMaintenance.php app/Http/Controllers/SystemMaintenanceController.php bootstrap/app.php routes/web.php tests/Feature/Maintenance/MaintenanceStatusEndpointTest.php tests/Feature/Maintenance/MaintenanceEnforcementTest.php
git commit -m "feat: enforce platform maintenance"
```

## Task 7: Add Admin capabilities and lifecycle endpoints

**Files:**
- Modify: `app/Models/SuperAdmin.php`
- Create: `app/Http/Controllers/superAdmin/MaintenanceController.php`
- Modify: `routes/web.php`
- Modify: `tests/Unit/Models/SuperAdminCapabilityTest.php`
- Create: `tests/Feature/SuperAdmin/PlatformMaintenanceManagementTest.php`

- [ ] **Step 1: Extend the capability matrix test**

Assert Admin has `view_platform_maintenance` but not `manage_platform_maintenance`; Super Admin has both.

- [ ] **Step 2: Write failing route authorization tests**

Cover:

- Active Admin/Super Admin with completed MFA can view `admin.maintenance.index`.
- Admin receives 403 for every mutation.
- Super Admin can mutate with the manage capability.
- Scheduled Start, direct emergency Start Now, and End require `privileged.recent`.
- Suspended, unauthenticated, incomplete-MFA, stale-version, and malformed requests retain existing controlled behavior.
- Admin routes remain reachable during Active maintenance but still enforce their own auth/MFA/capability middleware.

- [ ] **Step 3: Run and verify failure**

```powershell
php artisan test tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PlatformMaintenanceManagementTest.php
```

- [ ] **Step 4: Add two fixed capabilities**

```php
public const CAP_VIEW_PLATFORM_MAINTENANCE = 'view_platform_maintenance';
public const CAP_MANAGE_PLATFORM_MAINTENANCE = 'manage_platform_maintenance';
```

Assign view to both roles and manage only to Super Admin.

- [ ] **Step 5: Add Admin read projection and Draft commands**

`index()` returns current/upcoming/history plus `can_manage`. Add create, edit, schedule, and cancel methods that validate request shape, resolve the authenticated `SuperAdmin`, and call `MaintenanceLifecycleService`.

- [ ] **Step 6: Add activation and Active-state commands**

Add direct emergency start, scheduled start, extend, progress, public-update, and end methods. Map known domain conflicts to 409. Unexpected failures use the existing privileged failure response pattern and never expose SQL/stack details.

Use these routes:

```text
GET    /admin/maintenance                                  admin.maintenance.index
POST   /admin/maintenance                                  admin.maintenance.store
POST   /admin/maintenance/start-now                        admin.maintenance.start-now
PATCH  /admin/maintenance/{maintenanceWindow}              admin.maintenance.update
POST   /admin/maintenance/{maintenanceWindow}/schedule     admin.maintenance.schedule
POST   /admin/maintenance/{maintenanceWindow}/cancel       admin.maintenance.cancel
POST   /admin/maintenance/{maintenanceWindow}/start        admin.maintenance.start
POST   /admin/maintenance/{maintenanceWindow}/extend       admin.maintenance.extend
POST   /admin/maintenance/{maintenanceWindow}/progress     admin.maintenance.progress
POST   /admin/maintenance/{maintenanceWindow}/public-update admin.maintenance.public-update
POST   /admin/maintenance/{maintenanceWindow}/end          admin.maintenance.end
```

All mutations require `manage_platform_maintenance`; both Start routes and End additionally require `privileged.recent`. `start-now` creates an emergency Active record atomically with `starts_at = activated_at = server now` and a required estimated `ends_at`; it does not create a Draft in a separate request. Validation permits only approved warning/freeze choices, approved progress values, UTC-convertible timestamps, and coherent start/end values. The service remains authoritative for lifecycle/version/overlap.

- [ ] **Step 7: Re-run capability and authorization tests**

Expected: PASS.

- [ ] **Step 8: Commit privileged controls**

```powershell
git add -- app/Models/SuperAdmin.php app/Http/Controllers/superAdmin/MaintenanceController.php routes/web.php tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PlatformMaintenanceManagementTest.php
git commit -m "feat: add maintenance admin controls"
```

## Task 8: Build the capability-aware Admin page

**Files:**
- Create: `resources/js/Pages/superAdmin/Maintenance/Index.tsx`
- Create: `resources/js/Pages/superAdmin/Maintenance/__tests__/Index.test.tsx`
- Modify: `resources/js/layout/AppSidebar.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar.test.tsx`

- [ ] **Step 1: Write failing UI tests**

Cover Operational/Scheduled/Active summaries, Asia/Manila presentation, history, Admin read-only mode, Super Admin controls, approved select values, stale/conflict feedback, and confirmation/reauthentication behavior for Start/End. Assert no mutation control appears without manage capability. Also cover the complete Admin-page filtering contract: the current summary automatically selects Active first, then the nearest Scheduled window whose warning threshold is active, then the nearest future Scheduled window; history exposes status, text search, date-from/date-to, clear, and pagination controls; filter changes are submitted through the server-backed query and preserve the existing Super Admin page layout/state conventions.

- [ ] **Step 2: Run the focused frontend tests and verify failure**

```powershell
pnpm exec vitest run resources/js/Pages/superAdmin/Maintenance/__tests__/Index.test.tsx resources/js/layout/__tests__
```

- [ ] **Step 3: Implement read-only status, upcoming-window, and history sections**

Use existing neutral cards/tables and display returned UTC timestamps in Asia/Manila. Ensure Admin receives no hidden or disabled mutation controls. Show status summary cards for Operational, Scheduled, Active, Ended, and Cancelled records; let the server provide the deterministic current/upcoming projection so the page does not make authorization decisions from stale client state.

- [ ] **Step 4: Add server-backed history filters and automatic selection**

Accept only allowlisted `status`, `search`, `date_from`, `date_to`, and `per_page` query values in the Admin controller. Return filtered, newest-first history with pagination metadata. The page submits filters with `router.get('/admin/maintenance', ..., { preserveState: true, preserveScroll: true, replace: true })`, keeps the active filter values after paging, and provides an explicit Clear filters action. The summary must continue to show the canonical effective state even when the history table is filtered.

- [ ] **Step 5: Add Super Admin forms and command controls**

Use Inertia forms/router, one selected-window form, native `datetime-local` inputs, existing modals, and current flash/error conventions. Convert Manila input to ISO UTC before submission. After success use scoped Inertia reload for current/upcoming/history rather than `window.location.reload()`.

- [ ] **Step 6: Add sidebar navigation**

Add route fallback `/admin/maintenance` and one `System Maintenance` item gated by `view_platform_maintenance`.

- [ ] **Step 7: Re-run focused frontend tests**

Expected: PASS.

- [ ] **Step 8: Commit Admin UI**

```powershell
git add -- resources/js/Pages/superAdmin/Maintenance/Index.tsx resources/js/Pages/superAdmin/Maintenance/__tests__/Index.test.tsx resources/js/layout/AppSidebar.tsx resources/js/layout/__tests__/AppSidebar.test.tsx
git commit -m "feat: add maintenance admin page"
```

## Task 9: Add one global maintenance client state

**Files:**
- Create: `resources/js/types/maintenance.ts`
- Create: `resources/js/providers/MaintenanceProvider.tsx`
- Create: `resources/js/components/maintenance/MaintenanceBanner.tsx`
- Create: `resources/js/components/maintenance/MaintenanceWarningModal.tsx`
- Create: `resources/js/providers/__tests__/MaintenanceProvider.test.tsx`
- Modify: `resources/js/app.jsx`
- Modify: `resources/js/__tests__/appProviderBoundary.test.ts`
- Modify: `resources/js/bootstrap.js`

- [ ] **Step 1: Write failing provider tests with fake timers**

Assert:

- One initial status fetch and one 30-second poll source.
- Recheck after Inertia navigation and visible `visibilitychange`.
- Offset is `server_time - Date.now()` and resynchronizes on every success.
- Last successful state remains when polling fails; failure never produces restored.
- Banner appears at warned Scheduled and remains through freeze.
- Warning modal appears once per window ID near five minutes; local storage contains only the acknowledgment key/ID.
- Countdown text updates locally but its live region does not announce each second.
- Dirty registration reports unsaved work without storing field values.
- Concurrent Axios `MAINTENANCE_ACTIVE` events trigger one navigation to `/maintenance`.
- An Inertia 503 carrying `X-SoleSpace-Maintenance: active` triggers the same single-flight navigation.
- No request body is retained or replayed.

- [ ] **Step 2: Run and verify failure**

```powershell
pnpm exec vitest run resources/js/providers/__tests__/MaintenanceProvider.test.tsx resources/js/__tests__/appProviderBoundary.test.ts
```

- [ ] **Step 3: Define the public TypeScript contract**

Use a discriminated union for `operational`, `scheduled`, `active`, and `unavailable`; avoid `any`. Keep only fields exposed by the public endpoint.

- [ ] **Step 4: Implement status fetching and server-clock synchronization**

Use `window.axios.get('/system/maintenance-status')`, one interval, router navigation subscription, visibility listener, and cleanup. Store the latest successful projection and server/client offset.

- [ ] **Step 5: Add derived warning/freeze state and dirty registration**

Export `useMaintenance()` from this same provider file and expose:

```ts
state
serverNow()
refresh()
isFreezeActive
isRouteFrozen(routeName)
registerDirtySource(id, isDirty)
```

Store dirty booleans in memory only. The one-time modal key is `maintenance-warning:<window-id>` with no form/business payload.

- [ ] **Step 6: Extend Axios/Inertia handling without owning React navigation**

When an Axios response is 503 with `MAINTENANCE_ACTIVE`, dispatch one `solespace:maintenance-active` browser event carrying only safe response metadata, then reject the original promise unchanged. In `app.jsx`, bridge an Inertia invalid/error response carrying the maintenance header to the same event. The provider owns the single-flight Inertia GET navigation. Do not intercept generic 503s as restoration and do not replay the request.

- [ ] **Step 7: Mount the provider once**

Wrap the existing provider tree once inside `ApplicationProviders`; do not duplicate it per layout or page. Render banner/modal from that provider while excluding the maintenance page itself from duplicate warning UI.

- [ ] **Step 8: Re-run provider tests**

Expected: PASS.

- [ ] **Step 9: Commit shared client state**

```powershell
git add -- resources/js/types/maintenance.ts resources/js/providers/MaintenanceProvider.tsx resources/js/components/maintenance/MaintenanceBanner.tsx resources/js/components/maintenance/MaintenanceWarningModal.tsx resources/js/providers/__tests__/MaintenanceProvider.test.tsx resources/js/app.jsx resources/js/__tests__/appProviderBoundary.test.ts resources/js/bootstrap.js
git commit -m "feat: add global maintenance warnings"
```

## Task 10: Apply freeze UX to existing initiation controls

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/Checkout.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- Modify: `resources/js/Pages/ShopOwner/Premium/premuimBenefits.tsx`
- Modify: `resources/js/Pages/ERP/cashier/POS.tsx`
- Modify: `resources/js/Pages/ERP/repairer/POS.tsx`
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx`
- Modify: `resources/js/Pages/ERP/HR/generateSlip.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx`
- Modify: `resources/js/Pages/ERP/inventory/StockRequest.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/components/owner-action-center/ApprovalDecisionFooter.tsx`
- Modify: `resources/js/Pages/ERP/Finance/InlineApprovalUtils.tsx`
- Create: `resources/js/Pages/UserSide/Orders/__tests__/Checkout.maintenance.test.tsx`
- Create: `resources/js/Pages/UserSide/Orders/__tests__/payment.maintenance.test.tsx`
- Create: `resources/js/Pages/UserSide/Orders/__tests__/MyOrders.maintenance.test.tsx`
- Create: `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.maintenance.test.tsx`
- Create: `resources/js/Pages/ShopOwner/Premium/__tests__/premuimBenefits.maintenance.test.tsx`
- Create: `resources/js/Pages/ERP/cashier/__tests__/POS.maintenance.test.tsx`
- Create: `resources/js/Pages/ERP/repairer/__tests__/POS.maintenance.test.tsx`
- Modify: `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx`
- Create: `resources/js/Pages/ShopOwner/Repairs/service management/__tests__/POS.maintenance.test.tsx`
- Create: `resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.maintenance.test.tsx`
- Create: `resources/js/Pages/ERP/HR/__tests__/generateSlip.maintenance.test.tsx`
- Create: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequest.maintenance.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Modify: `resources/js/components/owner-action-center/__tests__/ApprovalDecisionFooter.test.tsx`
- Create: `resources/js/Pages/ERP/Finance/__tests__/InlineApprovalUtils.maintenance.test.tsx`
- Create: `resources/js/Pages/ERP/inventory/__tests__/StockRequest.maintenance.test.tsx`

- [ ] **Step 1: Add failing interaction tests at shared boundaries**

Prioritize one test per shared initiation surface rather than one per page:

- Checkout/payment initiation.
- Refund initiation.
- Retail and repair POS checkout.
- Payroll generation/process.
- Procurement submit-to-finance.
- Logistics batch create/schedule/offer.
- Shared owner and Finance approval decisions.

Each test must assert the button is disabled with a visible reason when `isRouteFrozen(canonicalName)` is true and remains usable otherwise. Existing loading/idempotency guards must still work.

- [ ] **Step 2: Run the focused tests and verify failure**

Run the exact test files listed above:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Orders/__tests__/Checkout.maintenance.test.tsx resources/js/Pages/UserSide/Orders/__tests__/payment.maintenance.test.tsx resources/js/Pages/UserSide/Orders/__tests__/MyOrders.maintenance.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.maintenance.test.tsx resources/js/Pages/ShopOwner/Premium/__tests__/premuimBenefits.maintenance.test.tsx resources/js/Pages/ERP/cashier/__tests__/POS.maintenance.test.tsx resources/js/Pages/ERP/repairer/__tests__/POS.maintenance.test.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/POS.maintenance.test.tsx" "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.maintenance.test.tsx" resources/js/Pages/ERP/HR/__tests__/generateSlip.maintenance.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequest.maintenance.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/components/owner-action-center/__tests__/ApprovalDecisionFooter.test.tsx resources/js/Pages/ERP/Finance/__tests__/InlineApprovalUtils.maintenance.test.tsx resources/js/Pages/ERP/inventory/__tests__/StockRequest.maintenance.test.tsx
```

- [ ] **Step 3: Guard customer checkout, payment retry, and refund controls**

In `Checkout.tsx`, `payment.tsx`, `MyOrders.tsx`, `myRepairs.tsx`, and `premuimBenefits.tsx`, combine the existing disabled expressions with `isRouteFrozen(exactRouteName)` and render one short reason. Leave verification/return completion calls enabled.

- [ ] **Step 4: Guard POS and repair payment activation controls**

Apply the same small hook call in the three POS pages and the two `JobOrdersRepair.tsx` pages. Do not change existing loading, payment settlement, or status-transition logic.

- [ ] **Step 5: Guard payroll, procurement, logistics, and approval controls**

Apply exact route names in `generateSlip.tsx`, `PurchaseRequest.tsx`, `Batches.tsx`, `Shipments.tsx`, `ApprovalDecisionFooter.tsx`, and `InlineApprovalUtils.tsx`. Do not disable completion/callback/progress controls.

- [ ] **Step 6: Register existing dirty forms in memory**

Use the existing dirty booleans in `PurchaseRequest.tsx` and `StockRequest.tsx` with `registerDirtySource`; do not copy their form data into new storage. Add `resources/js/Pages/ERP/inventory/StockRequest.tsx` to this task only for that registration. Existing page-specific draft behavior remains intentionally unchanged and is not adopted by maintenance mode.

- [ ] **Step 7: Re-run all focused interaction tests**

Expected: PASS.

- [ ] **Step 8: Commit freeze UX**

```powershell
git add -- resources/js/Pages/UserSide/Orders/Checkout.tsx resources/js/Pages/UserSide/Orders/payment.tsx resources/js/Pages/UserSide/Orders/MyOrders.tsx resources/js/Pages/UserSide/Repairs/myRepairs.tsx resources/js/Pages/ShopOwner/Premium/premuimBenefits.tsx resources/js/Pages/ERP/cashier/POS.tsx resources/js/Pages/ERP/repairer/POS.tsx resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx "resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx" "resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx" resources/js/Pages/ERP/HR/generateSlip.tsx resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx resources/js/Pages/ERP/inventory/StockRequest.tsx resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/components/owner-action-center/ApprovalDecisionFooter.tsx resources/js/Pages/ERP/Finance/InlineApprovalUtils.tsx resources/js/Pages/UserSide/Orders/__tests__/Checkout.maintenance.test.tsx resources/js/Pages/UserSide/Orders/__tests__/payment.maintenance.test.tsx resources/js/Pages/UserSide/Orders/__tests__/MyOrders.maintenance.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.maintenance.test.tsx resources/js/Pages/ShopOwner/Premium/__tests__/premuimBenefits.maintenance.test.tsx resources/js/Pages/ERP/cashier/__tests__/POS.maintenance.test.tsx resources/js/Pages/ERP/repairer/__tests__/POS.maintenance.test.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/POS.maintenance.test.tsx" "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.maintenance.test.tsx" resources/js/Pages/ERP/HR/__tests__/generateSlip.maintenance.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequest.maintenance.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/components/owner-action-center/__tests__/ApprovalDecisionFooter.test.tsx resources/js/Pages/ERP/Finance/__tests__/InlineApprovalUtils.maintenance.test.tsx resources/js/Pages/ERP/inventory/__tests__/StockRequest.maintenance.test.tsx
git commit -m "feat: freeze critical initiations before maintenance"
```

## Task 11: Add maintenance and safe recovery experience

**Files:**
- Create: `resources/js/Pages/Maintenance.tsx`
- Create: `resources/js/Pages/__tests__/Maintenance.test.tsx`
- Modify: `app/Http/Controllers/SystemMaintenanceController.php`
- Modify: `tests/Feature/Maintenance/MaintenanceStatusEndpointTest.php`

- [ ] **Step 1: Write failing page tests**

Cover active title/message/progress/ETA/update, Check Again, unavailable status, Service Restored live announcement, no automatic redirect, and Safe Return mappings:

```text
Customer -> /
Shop Owner -> /shop-owner/home
Employee -> /erp/time-in (or /erp/profile when force_password_change is set)
Rider -> /erp/time-in, because Rider is currently an employee role under the user guard
Admin/Super Admin -> /admin/system-monitoring
Guest -> /
```

Assert Safe Return performs a GET navigation only and ignores arbitrary referrer/previous URL.

- [ ] **Step 2: Run and verify failure**

```powershell
pnpm exec vitest run resources/js/Pages/__tests__/Maintenance.test.tsx
```

- [ ] **Step 3: Implement the page with existing SoleSpace styles**

Consume `MaintenanceProvider`; do not create a second poller. `Check Again` calls `refresh()`. Show the last known state plus a refresh warning on network failure. When state becomes Ended/Operational, show Service Restored and a button; do not redirect automatically.

- [ ] **Step 4: Resolve Safe Return server-side**

Mirror the existing canonical login rule in `app/Http/Controllers/UserController.php`: a user with `shop_owner_id` returns to `route('erp.time-in')` unless forced to `route('erp.profile')`; a customer returns to `route('landing')`. A Rider follows the employee rule because there is no separate Rider guard/home route in the current repository. Shop Owner returns to `/shop-owner/home`, privileged actors return to `route('admin.system-monitoring')`, and guests return to `route('landing')`. Pass only this same-origin GET path to the page. Never accept `return_to` from query/body and never replay a failed request.

- [ ] **Step 5: Re-run frontend and status tests**

```powershell
pnpm exec vitest run resources/js/Pages/__tests__/Maintenance.test.tsx
php artisan test tests/Feature/Maintenance/MaintenanceStatusEndpointTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit recovery UI**

```powershell
git add -- resources/js/Pages/Maintenance.tsx resources/js/Pages/__tests__/Maintenance.test.tsx app/Http/Controllers/SystemMaintenanceController.php tests/Feature/Maintenance/MaintenanceStatusEndpointTest.php
git commit -m "feat: add safe maintenance recovery"
```

## Task 12: Security, regression, and production-readiness verification

**Files:**
- Modify: `tests/Feature/Maintenance/MaintenanceEnforcementTest.php`
- Modify: `tests/Feature/Maintenance/MaintenanceRouteRegistryTest.php`
- Modify: `tests/Feature/SuperAdmin/PlatformMaintenanceManagementTest.php`
- Modify: `docs/ai-learning-log.md` only if a durable new lesson exists

- [ ] **Step 1: Complete the backend bypass/freeze matrix**

Add table-driven coverage for every exact configured bypass and critical route name. Assert no wildcard `/api/*` exemption, callback authentication/signature remains enforced, Admin access does not bypass MFA/capabilities, and unknown route names remain subject to normal maintenance behavior.

- [ ] **Step 2: Run narrow backend suites**

```powershell
php artisan test tests/Unit/Maintenance
php artisan test tests/Feature/Maintenance
php artisan test tests/Feature/SuperAdmin/PlatformMaintenanceManagementTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
```

Expected: all pass; only the documented MySQL lock integration test may skip under SQLite.

- [ ] **Step 3: Run narrow frontend suites**

```powershell
pnpm exec vitest run resources/js/providers/__tests__/MaintenanceProvider.test.tsx resources/js/Pages/__tests__/Maintenance.test.tsx resources/js/Pages/superAdmin/Maintenance/__tests__/Index.test.tsx resources/js/__tests__/appProviderBoundary.test.ts
```

Expected: PASS.

- [ ] **Step 4: Perform the required sequential review stack**

Record each result before completion:

1. Simplify/Ponytail: remove speculative abstractions, duplicate state, and unused configurability.
2. Standards review: Laravel/React/repository conventions.
3. Spec review: every acceptance criterion and explicit exclusion.
4. TypeScript review: typed boundaries, cleanup, stale closure/listener safety, no unnecessary `any`.
5. Performance/code-splitting review: one poller, no duplicate route/page imports, no unjustified lazy split.
6. Security review: privileged access, input validation, callback bypass, fail-closed behavior, no write replay.
7. Reuse/dead-code review: existing audit/reauth/provider/UI patterns; no orphan imports, routes, or TODOs.

Fix findings and re-run only evidence invalidated by the fix.

- [ ] **Step 5: Run broad quality gates**

```powershell
php artisan test
pnpm run test:frontend
pnpm run build
git diff --check
git status --short
```

Expected: tests and build pass, `git diff --check` is silent, and status contains only intended maintenance files plus preserved unrelated user changes. Do not report TypeScript lint/typecheck because the repository has no committed scripts for them.

- [ ] **Step 6: Run browser QA where the local application is available**

Use `@webapp-testing` to verify desktop and narrow viewport flows for Guest, Customer, Shop Owner, one ERP employee, Rider, Admin, and Super Admin:

```text
warned Scheduled -> five-minute modal once -> exact freeze -> Active 503
Active -> Check Again -> extend/public update -> manual or timestamp Ended
Ended -> Service Restored -> canonical Safe Return GET
poll failure -> retain last known state
concurrent 503s -> one maintenance navigation
PayMongo callback -> reachable and still signature protected
```

If credentials or services prevent a lane, report it as not executed rather than passed.

- [ ] **Step 7: Write only durable project guidance**

Update `docs/ai-learning-log.md` only for a reusable repository fact discovered during implementation (for example, the verified canonical employee/rider landing resolver). Do not copy the feature report or temporary debug notes into the learning log.

- [ ] **Step 8: Commit final test/document corrections**

```powershell
git add -- tests/Feature/Maintenance/MaintenanceEnforcementTest.php tests/Feature/Maintenance/MaintenanceRouteRegistryTest.php tests/Feature/SuperAdmin/PlatformMaintenanceManagementTest.php docs/ai-learning-log.md
git commit -m "test: verify platform maintenance mode"
```

Omit `docs/ai-learning-log.md` from staging if it did not need a durable update.

## Completion report checklist

The implementation handoff must report:

- Persisted vs effective lifecycle behavior and half-open overlap boundary.
- Exact freeze and bypass route-name registries, including newly named routes.
- MySQL advisory lock behavior and SQLite test strategy.
- Cache expiry/fail-closed behavior at every decision boundary.
- Admin vs Super Admin permissions and recent-reauthentication gates.
- Scheduler system-actor behavior and canonical transition timestamps.
- Shared polling/countdown/single-flight behavior.
- Dirty-form opt-in scope and confirmation that maintenance stores no draft payload.
- Safe Return mapping and confirmation that rejected writes are never replayed.
- Files changed, migrations, audit events, tests, commands, pass/fail/skip counts, browser QA gaps, remaining risks, and intentionally deferred Phase 2 scope.

Do not claim completion until fresh command output supports every reported result.
