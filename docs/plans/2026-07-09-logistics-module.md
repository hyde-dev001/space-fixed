# Logistics Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand existing `LogisticsTask` into a shared logistics domain covering retail deliveries, retail returns, repair intake, repair return, customer tracking, notifications, and logistics employee access.

**Architecture:** Reuse the existing `LogisticsTask` model, logistics queue, provider types, staff permissions, and notification system. Add nullable fields and focused service methods so retail and repair workflows call one shared logistics path instead of duplicating tracking fields.

**Tech Stack:** Laravel, MySQL, PHPUnit, React, Inertia, TypeScript, Axios/fetch, existing notification system, existing Spatie permissions.

## Global Constraints

- Do not add new dependencies.
- Reuse existing `LogisticsTask`, logistics queue, routes, permissions, and UI patterns.
- Cover retail order delivery, repair intake, repair return, and retail return/refund logistics together.
- Support `third_party`, `customer_pickup`, and `customer_arranged` for all shops.
- Support `shop_logistics` only for shops with employee assignees.
- Support `owner_delivery` only for `individual` registration type shops; company/business shops must use `shop_logistics` or `third_party`.
- Keep business workflow status separate from logistics movement status.
- Use existing staff/user access control for logistics employee accounts.
- Use existing notification system for customer logistics notifications.
- Do not implement live GPS tracking, route optimization, courier API booking, fleet maintenance, rider payroll automation, warehouse packing, or SLA penalty automation.
- Execution prerequisite: restore backend source directories `app/` and `database/` before starting implementation. Current workspace has routes, tests, and frontend, but not backend source.

---

## File Structure

Backend files expected after backend source is restored:

- Modify: `database/migrations/*_create_logistics_tasks_table.php` or add `database/migrations/YYYY_MM_DD_HHMMSS_expand_logistics_tasks_for_shared_domain.php`
- Modify: `app/Models/LogisticsTask.php`
- Create: `app/Services/Logistics/LogisticsTaskService.php`
- Create: `app/Services/Logistics/LogisticsNotificationService.php`
- Modify: `app/Http/Controllers/Api/LogisticsTaskController.php`
- Create: `app/Http/Controllers/Api/Customer/CustomerLogisticsTaskController.php`
- Modify: `app/Http/Controllers/ShopOwner/OrderController.php`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `routes/web.php`
- Modify: permission seeder file that currently creates `access-logistics-queue` and `manage-logistics-shipments`

Frontend files:

- Modify: `resources/js/Pages/ERP/Logistics/LogisticsQueue.tsx`
- Create: `resources/js/Pages/UserSide/Logistics/DeliveryTracking.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- Modify: `resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx` only if logistics employee menu visibility is missing

Tests:

- Modify: `tests/Unit/Logistics/LogisticsTaskModelTest.php`
- Modify: `tests/Feature/Logistics/LogisticsTaskApiTest.php`
- Modify: `tests/Feature/Logistics/OutboundOrderLogisticsProviderTest.php`
- Modify: `tests/Feature/Logistics/RepairDeliveryLogisticsTaskTest.php`
- Modify: `tests/Feature/Logistics/RetailReturnLogisticsTaskTest.php`
- Create: `tests/Feature/Logistics/RepairIntakeLogisticsTaskTest.php`
- Create: `tests/Feature/Logistics/CustomerLogisticsTrackingTest.php`
- Create: `tests/Feature/Logistics/LogisticsNotificationTest.php`
- Create: `tests/Feature/ShopOwner/LogisticsEmployeeAccountTest.php`

---

### Task 1: Expand `LogisticsTask` Schema And Model Constants

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_expand_logistics_tasks_for_shared_domain.php`
- Modify: `app/Models/LogisticsTask.php`
- Modify: `tests/Unit/Logistics/LogisticsTaskModelTest.php`

**Interfaces:**
- Produces constants on `App\Models\LogisticsTask`:
  - `PURPOSE_RETAIL_DELIVERY`
  - `PURPOSE_RETAIL_RETURN`
  - `PURPOSE_REPAIR_INTAKE`
  - `PURPOSE_REPAIR_RETURN`
  - `METHOD_CUSTOMER_PICKUP`
  - `METHOD_CUSTOMER_ARRANGED`
  - `METHOD_THIRD_PARTY`
  - `METHOD_SHOP_LOGISTICS`
  - `METHOD_OWNER_DELIVERY`
  - `STATUS_READY_FOR_PICKUP`
  - `STATUS_ASSIGNED`
  - `STATUS_FAILED`
  - `STATUS_RESCHEDULED`
  - `STATUS_CANCELLED`
- Following tasks consume these constants and fields.

- [ ] **Step 1: Write failing model test**

Add to `tests/Unit/Logistics/LogisticsTaskModelTest.php`:

```php
public function test_shared_logistics_fields_have_defaults_and_casts(): void
{
    $shopOwner = ShopOwner::factory()->create();

    $task = LogisticsTask::create([
        'shop_owner_id' => $shopOwner->id,
        'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
        'module_id' => 1001,
        'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
        'direction' => LogisticsTask::DIRECTION_OUTBOUND,
        'fulfillment_method' => LogisticsTask::METHOD_THIRD_PARTY,
        'customer_shipping_fee' => 80,
        'actual_logistics_cost' => 120,
        'origin_name' => 'SoleSpace Shop',
        'destination_name' => 'Customer A',
    ]);

    $this->assertSame(LogisticsTask::PURPOSE_RETAIL_DELIVERY, $task->purpose);
    $this->assertSame(LogisticsTask::DIRECTION_OUTBOUND, $task->direction);
    $this->assertSame(LogisticsTask::METHOD_THIRD_PARTY, $task->fulfillment_method);
    $this->assertSame(80.0, (float) $task->customer_shipping_fee);
    $this->assertSame(120.0, (float) $task->actual_logistics_cost);
    $this->assertNull($task->delivered_at);
    $this->assertNull($task->received_at);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Logistics/LogisticsTaskModelTest.php --filter=shared_logistics_fields`

Expected: fail because columns/constants are missing.

- [ ] **Step 3: Add migration**

Create `database/migrations/YYYY_MM_DD_HHMMSS_expand_logistics_tasks_for_shared_domain.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->string('purpose')->nullable()->after('module_id');
            $table->string('direction')->nullable()->after('purpose');
            $table->string('fulfillment_method')->nullable()->after('provider_type');

            $table->string('origin_name')->nullable();
            $table->string('origin_phone')->nullable();
            $table->string('origin_address_line')->nullable();
            $table->string('origin_barangay')->nullable();
            $table->string('origin_city')->nullable();
            $table->string('origin_region')->nullable();
            $table->string('origin_postal_code')->nullable();

            $table->string('destination_name')->nullable();
            $table->string('destination_phone')->nullable();
            $table->string('destination_address_line')->nullable();
            $table->string('destination_barangay')->nullable();
            $table->string('destination_city')->nullable();
            $table->string('destination_region')->nullable();
            $table->string('destination_postal_code')->nullable();

            $table->decimal('customer_shipping_fee', 10, 2)->nullable();
            $table->decimal('actual_logistics_cost', 10, 2)->nullable();

            $table->string('failure_reason')->nullable();
            $table->text('failure_notes')->nullable();
            $table->timestamp('rescheduled_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->index(['shop_owner_id', 'purpose', 'status']);
            $table->index(['shop_owner_id', 'fulfillment_method', 'status']);
            $table->index(['module_type', 'module_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->dropIndex(['shop_owner_id', 'purpose', 'status']);
            $table->dropIndex(['shop_owner_id', 'fulfillment_method', 'status']);
            $table->dropIndex(['module_type', 'module_id', 'purpose']);

            $table->dropColumn([
                'purpose',
                'direction',
                'fulfillment_method',
                'origin_name',
                'origin_phone',
                'origin_address_line',
                'origin_barangay',
                'origin_city',
                'origin_region',
                'origin_postal_code',
                'destination_name',
                'destination_phone',
                'destination_address_line',
                'destination_barangay',
                'destination_city',
                'destination_region',
                'destination_postal_code',
                'customer_shipping_fee',
                'actual_logistics_cost',
                'failure_reason',
                'failure_notes',
                'rescheduled_at',
                'delivered_at',
                'received_at',
                'cancelled_at',
            ]);
        });
    }
};
```

- [ ] **Step 4: Update model constants, fillable, casts**

In `app/Models/LogisticsTask.php`, add:

```php
public const PURPOSE_RETAIL_DELIVERY = 'retail_delivery';
public const PURPOSE_RETAIL_RETURN = 'retail_return';
public const PURPOSE_REPAIR_INTAKE = 'repair_intake';
public const PURPOSE_REPAIR_RETURN = 'repair_return';

public const DIRECTION_OUTBOUND = 'outbound';
public const DIRECTION_INBOUND = 'inbound';
public const DIRECTION_RETURN = 'return';

public const METHOD_CUSTOMER_PICKUP = 'customer_pickup';
public const METHOD_CUSTOMER_ARRANGED = 'customer_arranged';
public const METHOD_THIRD_PARTY = 'third_party';
public const METHOD_SHOP_LOGISTICS = 'shop_logistics';
public const METHOD_OWNER_DELIVERY = 'owner_delivery';

public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
public const STATUS_ASSIGNED = 'assigned';
public const STATUS_FAILED = 'failed';
public const STATUS_RESCHEDULED = 'rescheduled';
public const STATUS_CANCELLED = 'cancelled';
```

Add fields to `$fillable`:

```php
'purpose',
'direction',
'fulfillment_method',
'origin_name',
'origin_phone',
'origin_address_line',
'origin_barangay',
'origin_city',
'origin_region',
'origin_postal_code',
'destination_name',
'destination_phone',
'destination_address_line',
'destination_barangay',
'destination_city',
'destination_region',
'destination_postal_code',
'customer_shipping_fee',
'actual_logistics_cost',
'failure_reason',
'failure_notes',
'rescheduled_at',
'delivered_at',
'received_at',
'cancelled_at',
```

Add casts:

```php
'customer_shipping_fee' => 'decimal:2',
'actual_logistics_cost' => 'decimal:2',
'rescheduled_at' => 'datetime',
'delivered_at' => 'datetime',
'received_at' => 'datetime',
'cancelled_at' => 'datetime',
```

- [ ] **Step 5: Run test**

Run: `php artisan test tests/Unit/Logistics/LogisticsTaskModelTest.php`

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/LogisticsTask.php tests/Unit/Logistics/LogisticsTaskModelTest.php
git commit -m "feat: expand logistics task domain"
```

---

### Task 2: Add Shared Logistics Task Service

**Files:**
- Create: `app/Services/Logistics/LogisticsTaskService.php`
- Create: `tests/Unit/Logistics/LogisticsTaskServiceTest.php`

**Interfaces:**
- Consumes `LogisticsTask` constants from Task 1.
- Produces:
  - `createOrUpdateTask(array $attributes): LogisticsTask`
  - `validateFulfillmentMethodForShop(int $shopOwnerId, string $method): void`
  - `markReadyForPickup(LogisticsTask $task): LogisticsTask`
  - `assign(LogisticsTask $task, int $userId): LogisticsTask`
  - `markInTransit(LogisticsTask $task, array $tracking): LogisticsTask`
  - `markDelivered(LogisticsTask $task, array $proofUrls): LogisticsTask`
  - `markReceived(LogisticsTask $task): LogisticsTask`
  - `markFailed(LogisticsTask $task, string $reason, ?string $notes = null): LogisticsTask`
  - `reschedule(LogisticsTask $task, string $date): LogisticsTask`
  - `cancel(LogisticsTask $task): LogisticsTask`

- [ ] **Step 1: Write failing service test**

Create `tests/Unit/Logistics/LogisticsTaskServiceTest.php`:

```php
<?php

namespace Tests\Unit\Logistics;

use App\Models\LogisticsTask;
use App\Models\ShopOwner;
use App\Services\Logistics\LogisticsTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsTaskServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_or_update_task_is_idempotent_per_module_and_purpose(): void
    {
        $shopOwner = ShopOwner::factory()->create(['registration_type' => 'individual']);
        $service = app(LogisticsTaskService::class);

        $first = $service->createOrUpdateTask([
            'shop_owner_id' => $shopOwner->id,
            'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
            'module_id' => 10,
            'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
            'direction' => LogisticsTask::DIRECTION_OUTBOUND,
            'fulfillment_method' => LogisticsTask::METHOD_THIRD_PARTY,
            'provider_type' => LogisticsTask::PROVIDER_THIRD_PARTY,
        ]);

        $second = $service->createOrUpdateTask([
            'shop_owner_id' => $shopOwner->id,
            'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
            'module_id' => 10,
            'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
            'direction' => LogisticsTask::DIRECTION_OUTBOUND,
            'fulfillment_method' => LogisticsTask::METHOD_OWNER_DELIVERY,
            'provider_type' => LogisticsTask::PROVIDER_OWNER_DELIVERY,
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(LogisticsTask::METHOD_OWNER_DELIVERY, $second->fresh()->fulfillment_method);
        $this->assertSame(1, LogisticsTask::count());
    }

    public function test_owner_delivery_is_rejected_for_company_shop(): void
    {
        $shopOwner = ShopOwner::factory()->create(['registration_type' => 'company']);
        $service = app(LogisticsTaskService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Owner delivery is only available for individual shops.');

        $service->createOrUpdateTask([
            'shop_owner_id' => $shopOwner->id,
            'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
            'module_id' => 11,
            'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
            'direction' => LogisticsTask::DIRECTION_OUTBOUND,
            'fulfillment_method' => LogisticsTask::METHOD_OWNER_DELIVERY,
            'provider_type' => LogisticsTask::PROVIDER_OWNER_DELIVERY,
        ]);
    }

    public function test_failed_task_can_be_rescheduled(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $service = app(LogisticsTaskService::class);

        $task = LogisticsTask::create([
            'shop_owner_id' => $shopOwner->id,
            'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
            'module_id' => 20,
            'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
            'status' => LogisticsTask::STATUS_IN_TRANSIT,
        ]);

        $failed = $service->markFailed($task, 'customer_unavailable', 'No answer on phone');
        $rescheduled = $service->reschedule($failed, '2026-07-15');

        $this->assertSame(LogisticsTask::STATUS_RESCHEDULED, $rescheduled->status);
        $this->assertSame('customer_unavailable', $rescheduled->failure_reason);
        $this->assertSame('2026-07-15', $rescheduled->rescheduled_at->toDateString());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Logistics/LogisticsTaskServiceTest.php`

Expected: fail because service is missing.

- [ ] **Step 3: Implement service**

Create `app/Services/Logistics/LogisticsTaskService.php`:

```php
<?php

namespace App\Services\Logistics;

use App\Models\LogisticsTask;
use App\Models\ShopOwner;
use Illuminate\Support\Arr;

class LogisticsTaskService
{
    public function createOrUpdateTask(array $attributes): LogisticsTask
    {
        $keys = Arr::only($attributes, ['shop_owner_id', 'module_type', 'module_id', 'purpose']);
        $values = Arr::except($attributes, array_keys($keys));

        if (empty($values['fulfillment_method']) && !empty($attributes['provider_type'])) {
            $values['fulfillment_method'] = $attributes['provider_type'];
        }

        if (empty($values['provider_type']) && !empty($attributes['fulfillment_method'])) {
            $values['provider_type'] = $attributes['fulfillment_method'];
        }

        $this->validateFulfillmentMethodForShop(
            (int) $attributes['shop_owner_id'],
            (string) ($values['fulfillment_method'] ?? $values['provider_type'] ?? '')
        );

        return LogisticsTask::query()->updateOrCreate($keys, $values);
    }

    public function validateFulfillmentMethodForShop(int $shopOwnerId, string $method): void
    {
        if ($method !== LogisticsTask::METHOD_OWNER_DELIVERY) {
            return;
        }

        $registrationType = strtolower((string) ShopOwner::query()->whereKey($shopOwnerId)->value('registration_type'));

        if ($registrationType !== 'individual') {
            throw new \InvalidArgumentException('Owner delivery is only available for individual shops.');
        }
    }

    public function markReadyForPickup(LogisticsTask $task): LogisticsTask
    {
        return $this->setStatus($task, LogisticsTask::STATUS_READY_FOR_PICKUP);
    }

    public function assign(LogisticsTask $task, int $userId): LogisticsTask
    {
        $task->forceFill([
            'assigned_to_user_id' => $userId,
            'status' => LogisticsTask::STATUS_ASSIGNED,
        ])->save();

        return $task->refresh();
    }

    public function markInTransit(LogisticsTask $task, array $tracking): LogisticsTask
    {
        $task->fill($tracking);
        $task->status = LogisticsTask::STATUS_IN_TRANSIT;
        $task->save();

        return $task->refresh();
    }

    public function markDelivered(LogisticsTask $task, array $proofUrls): LogisticsTask
    {
        $existingProofs = is_array($task->proof_urls) ? $task->proof_urls : [];

        $task->forceFill([
            'status' => LogisticsTask::STATUS_DELIVERED,
            'delivered_at' => now(),
            'proof_urls' => array_values(array_merge($existingProofs, $proofUrls)),
        ])->save();

        return $task->refresh();
    }

    public function markReceived(LogisticsTask $task): LogisticsTask
    {
        $task->forceFill([
            'status' => LogisticsTask::STATUS_RECEIVED,
            'received_at' => now(),
        ])->save();

        return $task->refresh();
    }

    public function markFailed(LogisticsTask $task, string $reason, ?string $notes = null): LogisticsTask
    {
        $task->forceFill([
            'status' => LogisticsTask::STATUS_FAILED,
            'failure_reason' => $reason,
            'failure_notes' => $notes,
        ])->save();

        return $task->refresh();
    }

    public function reschedule(LogisticsTask $task, string $date): LogisticsTask
    {
        $task->forceFill([
            'status' => LogisticsTask::STATUS_RESCHEDULED,
            'rescheduled_at' => $date,
        ])->save();

        return $task->refresh();
    }

    public function cancel(LogisticsTask $task): LogisticsTask
    {
        $task->forceFill([
            'status' => LogisticsTask::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        return $task->refresh();
    }

    private function setStatus(LogisticsTask $task, string $status): LogisticsTask
    {
        $task->forceFill(['status' => $status])->save();

        return $task->refresh();
    }
}
```

- [ ] **Step 4: Run test**

Run: `php artisan test tests/Unit/Logistics/LogisticsTaskServiceTest.php`

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/LogisticsTaskService.php tests/Unit/Logistics/LogisticsTaskServiceTest.php
git commit -m "feat: add logistics task service"
```

---

### Task 3: Extend Logistics Staff And Shop Owner APIs

**Files:**
- Modify: `app/Http/Controllers/Api/LogisticsTaskController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Logistics/LogisticsTaskApiTest.php`

**Interfaces:**
- Consumes `LogisticsTaskService` from Task 2.
- Produces routes for:
  - `POST /api/staff/logistics/tasks/{task}/mark-in-transit`
  - `POST /api/staff/logistics/tasks/{task}/mark-failed`
  - `POST /api/staff/logistics/tasks/{task}/reschedule`
  - `POST /api/staff/logistics/tasks/{task}/cancel`
  - same actions under `/api/shop-owner/logistics/...`

- [ ] **Step 1: Write failing API tests**

Add to `tests/Feature/Logistics/LogisticsTaskApiTest.php`:

```php
public function test_staff_can_mark_task_failed_and_reschedule(): void
{
    $shopOwner = ShopOwner::factory()->create();
    $staff = $this->makeStaff($shopOwner);
    $task = LogisticsTask::create([
        'shop_owner_id' => $shopOwner->id,
        'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
        'module_id' => 501,
        'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
        'status' => LogisticsTask::STATUS_IN_TRANSIT,
    ]);

    $this->actingAs($staff, 'user')
        ->postJson("/api/staff/logistics/tasks/{$task->id}/mark-failed", [
            'failure_reason' => 'customer_unavailable',
            'failure_notes' => 'No answer at gate',
        ])
        ->assertOk()
        ->assertJsonPath('task.status', LogisticsTask::STATUS_FAILED);

    $this->actingAs($staff, 'user')
        ->postJson("/api/staff/logistics/tasks/{$task->id}/reschedule", [
            'rescheduled_at' => '2026-07-15',
        ])
        ->assertOk()
        ->assertJsonPath('task.status', LogisticsTask::STATUS_RESCHEDULED);
}

public function test_staff_cannot_manage_other_shop_task(): void
{
    $shopOwner = ShopOwner::factory()->create();
    $otherShop = ShopOwner::factory()->create();
    $staff = $this->makeStaff($shopOwner);

    $task = LogisticsTask::create([
        'shop_owner_id' => $otherShop->id,
        'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
        'module_id' => 502,
        'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
    ]);

    $this->actingAs($staff, 'user')
        ->postJson("/api/staff/logistics/tasks/{$task->id}/cancel")
        ->assertForbidden();
}
```

- [ ] **Step 2: Run tests to verify failure**

Run: `php artisan test tests/Feature/Logistics/LogisticsTaskApiTest.php --filter=failed`

Expected: fail because routes/actions are missing.

- [ ] **Step 3: Add controller methods**

In `app/Http/Controllers/Api/LogisticsTaskController.php`, inject `LogisticsTaskService` or call `app(LogisticsTaskService::class)`. Add:

```php
public function markFailed(Request $request, LogisticsTask $task)
{
    $this->authorizeTaskShop($task);

    $validated = $request->validate([
        'failure_reason' => ['required', 'string', 'max:100'],
        'failure_notes' => ['nullable', 'string', 'max:1000'],
    ]);

    $task = app(\App\Services\Logistics\LogisticsTaskService::class)
        ->markFailed($task, $validated['failure_reason'], $validated['failure_notes'] ?? null);

    return response()->json(['task' => $task]);
}

public function reschedule(Request $request, LogisticsTask $task)
{
    $this->authorizeTaskShop($task);

    $validated = $request->validate([
        'rescheduled_at' => ['required', 'date'],
    ]);

    $task = app(\App\Services\Logistics\LogisticsTaskService::class)
        ->reschedule($task, $validated['rescheduled_at']);

    return response()->json(['task' => $task]);
}

public function cancel(LogisticsTask $task)
{
    $this->authorizeTaskShop($task);

    $task = app(\App\Services\Logistics\LogisticsTaskService::class)->cancel($task);

    return response()->json(['task' => $task]);
}
```

If `authorizeTaskShop()` does not exist, add:

```php
private function authorizeTaskShop(LogisticsTask $task): void
{
    $user = auth('user')->user();
    $shopOwner = auth('shop_owner')->user();
    $shopOwnerId = $shopOwner?->id ?? $user?->shop_owner_id;

    abort_unless((int) $task->shop_owner_id === (int) $shopOwnerId, 403);
}
```

- [ ] **Step 4: Add routes**

In both logistics route groups in `routes/web.php`, add:

```php
Route::post('/tasks/{task}/mark-failed', [\App\Http\Controllers\Api\LogisticsTaskController::class, 'markFailed'])
    ->middleware('permission:manage-logistics-shipments');
Route::post('/tasks/{task}/reschedule', [\App\Http\Controllers\Api\LogisticsTaskController::class, 'reschedule'])
    ->middleware('permission:manage-logistics-shipments');
Route::post('/tasks/{task}/cancel', [\App\Http\Controllers\Api\LogisticsTaskController::class, 'cancel'])
    ->middleware('permission:manage-logistics-shipments');
```

For shop owner group, omit permission middleware only if current shop-owner logistics routes do not use permission middleware.

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Logistics/LogisticsTaskApiTest.php`

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/LogisticsTaskController.php routes/web.php tests/Feature/Logistics/LogisticsTaskApiTest.php
git commit -m "feat: extend logistics task actions"
```

---

### Task 4: Move Retail And Repair Task Creation Through Shared Service

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/OrderController.php`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `tests/Feature/Logistics/OutboundOrderLogisticsProviderTest.php`
- Modify: `tests/Feature/Logistics/RetailReturnLogisticsTaskTest.php`
- Modify: `tests/Feature/Logistics/RepairDeliveryLogisticsTaskTest.php`
- Create: `tests/Feature/Logistics/RepairIntakeLogisticsTaskTest.php`

**Interfaces:**
- Consumes `LogisticsTaskService::createOrUpdateTask()`.
- Produces consistent `purpose`, `direction`, and `fulfillment_method` values for existing retail/repair flows.

- [ ] **Step 1: Extend existing tests for purpose and method**

In `OutboundOrderLogisticsProviderTest`, assert:

```php
$this->assertDatabaseHas('logistics_tasks', [
    'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
    'module_id' => $order->id,
    'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
    'direction' => LogisticsTask::DIRECTION_OUTBOUND,
    'fulfillment_method' => LogisticsTask::METHOD_THIRD_PARTY,
]);
```

Also add:

```php
public function test_company_shop_cannot_use_owner_delivery_for_order(): void
{
    $shopOwner = ShopOwner::factory()->create(['registration_type' => 'company']);

    $order = Order::create([
        'shop_owner_id' => $shopOwner->id,
        'order_number' => 'ORD-LOG-COMPANY-001',
        'total_amount' => 1000,
        'status' => 'processing',
        'payment_status' => 'paid',
    ]);

    $this->actingAs($shopOwner, 'shop_owner')
        ->postJson("/api/shop-owner/orders/{$order->id}/logistics-provider", [
            'provider_type' => LogisticsTask::PROVIDER_OWNER_DELIVERY,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Owner delivery is only available for individual shops.');
}
```

In `RetailReturnLogisticsTaskTest`, assert:

```php
$this->assertDatabaseHas('logistics_tasks', [
    'module_type' => LogisticsTask::MODULE_RETAIL_RETURN,
    'module_id' => $refund->id,
    'purpose' => LogisticsTask::PURPOSE_RETAIL_RETURN,
    'direction' => LogisticsTask::DIRECTION_RETURN,
    'fulfillment_method' => LogisticsTask::METHOD_THIRD_PARTY,
]);
```

In `RepairDeliveryLogisticsTaskTest`, assert:

```php
$this->assertDatabaseHas('logistics_tasks', [
    'module_type' => LogisticsTask::MODULE_REPAIR_DELIVERY,
    'module_id' => $repair->id,
    'purpose' => LogisticsTask::PURPOSE_REPAIR_RETURN,
    'direction' => LogisticsTask::DIRECTION_OUTBOUND,
    'fulfillment_method' => LogisticsTask::METHOD_THIRD_PARTY,
]);
```

- [ ] **Step 2: Add repair intake test**

Create `tests/Feature/Logistics/RepairIntakeLogisticsTaskTest.php`:

```php
<?php

namespace Tests\Feature\Logistics;

use App\Models\LogisticsTask;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairIntakeLogisticsTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_delivery_repair_intake_creates_logistics_task(): void
    {
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->create(['business_type' => 'repair']);

        $repair = RepairRequest::factory()->create([
            'user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'intake_delivery_method' => 'customer_delivery',
            'status' => 'pending',
        ]);

        app(\App\Services\Logistics\LogisticsTaskService::class)->createOrUpdateTask([
            'shop_owner_id' => $shopOwner->id,
            'module_type' => LogisticsTask::MODULE_REPAIR_DELIVERY,
            'module_id' => $repair->id,
            'purpose' => LogisticsTask::PURPOSE_REPAIR_INTAKE,
            'direction' => LogisticsTask::DIRECTION_INBOUND,
            'fulfillment_method' => LogisticsTask::METHOD_CUSTOMER_ARRANGED,
            'provider_type' => LogisticsTask::PROVIDER_THIRD_PARTY,
        ]);

        $this->assertDatabaseHas('logistics_tasks', [
            'module_id' => $repair->id,
            'purpose' => LogisticsTask::PURPOSE_REPAIR_INTAKE,
            'direction' => LogisticsTask::DIRECTION_INBOUND,
            'fulfillment_method' => LogisticsTask::METHOD_CUSTOMER_ARRANGED,
        ]);
    }
}
```

- [ ] **Step 3: Run tests to verify failure**

Run: `php artisan test tests/Feature/Logistics`

Expected: fail until controller/service integration writes new fields.

- [ ] **Step 4: Update controllers**

In each existing place that creates `LogisticsTask::create()` or `updateOrCreate()`, replace direct write with:

```php
try {
    $task = app(\App\Services\Logistics\LogisticsTaskService::class)->createOrUpdateTask([
        'shop_owner_id' => $shopOwnerId,
        'module_type' => $moduleType,
        'module_id' => $moduleId,
        'purpose' => $purpose,
        'direction' => $direction,
        'fulfillment_method' => $fulfillmentMethod,
        'provider_type' => $providerType,
        'status' => $status,
        'customer_shipping_fee' => $customerShippingFee ?? null,
        'actual_logistics_cost' => $actualLogisticsCost ?? null,
        'origin_name' => $originName ?? null,
        'origin_phone' => $originPhone ?? null,
        'origin_address_line' => $originAddressLine ?? null,
        'origin_barangay' => $originBarangay ?? null,
        'origin_city' => $originCity ?? null,
        'origin_region' => $originRegion ?? null,
        'origin_postal_code' => $originPostalCode ?? null,
        'destination_name' => $destinationName ?? null,
        'destination_phone' => $destinationPhone ?? null,
        'destination_address_line' => $destinationAddressLine ?? null,
        'destination_barangay' => $destinationBarangay ?? null,
        'destination_city' => $destinationCity ?? null,
        'destination_region' => $destinationRegion ?? null,
        'destination_postal_code' => $destinationPostalCode ?? null,
    ]);
} catch (\InvalidArgumentException $exception) {
    return response()->json(['message' => $exception->getMessage()], 422);
}
```

Use these mappings:

```php
// Retail outbound
$purpose = LogisticsTask::PURPOSE_RETAIL_DELIVERY;
$direction = LogisticsTask::DIRECTION_OUTBOUND;

// Retail return
$purpose = LogisticsTask::PURPOSE_RETAIL_RETURN;
$direction = LogisticsTask::DIRECTION_RETURN;

// Repair intake
$purpose = LogisticsTask::PURPOSE_REPAIR_INTAKE;
$direction = LogisticsTask::DIRECTION_INBOUND;

// Repair return
$purpose = LogisticsTask::PURPOSE_REPAIR_RETURN;
$direction = LogisticsTask::DIRECTION_OUTBOUND;
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Logistics`

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers tests/Feature/Logistics
git commit -m "feat: unify logistics task creation"
```

---

### Task 5: Add Customer Logistics Tracking API

**Files:**
- Create: `app/Http/Controllers/Api/Customer/CustomerLogisticsTaskController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Logistics/CustomerLogisticsTrackingTest.php`

**Interfaces:**
- Produces:
  - `GET /api/customer/logistics/tasks`
  - `GET /api/customer/logistics/tasks/{task}`
- Response shape:
  - `id`
  - `module_type`
  - `module_id`
  - `purpose`
  - `status`
  - `fulfillment_method`
  - `carrier_company`
  - `carrier_name`
  - `carrier_phone`
  - `tracking_number`
  - `tracking_link`
  - `estimated_delivery_date`
  - `origin`
  - `destination`
  - `failure_reason`
  - `failure_notes`
  - `proof_urls`

- [ ] **Step 1: Write failing customer API test**

Create `tests/Feature/Logistics/CustomerLogisticsTrackingTest.php`:

```php
<?php

namespace Tests\Feature\Logistics;

use App\Models\LogisticsTask;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLogisticsTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_list_own_order_logistics_tasks(): void
    {
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->create();
        $order = Order::create([
            'customer_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'order_number' => 'ORD-CUST-LOG-001',
            'total_amount' => 1000,
            'status' => 'shipped',
            'payment_status' => 'paid',
        ]);

        LogisticsTask::create([
            'shop_owner_id' => $shopOwner->id,
            'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
            'module_id' => $order->id,
            'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
            'status' => LogisticsTask::STATUS_IN_TRANSIT,
            'tracking_number' => 'TRACK123',
        ]);

        $this->actingAs($customer, 'user')
            ->getJson('/api/customer/logistics/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tracking_number', 'TRACK123');
    }

    public function test_customer_cannot_view_other_customer_task(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->create();
        $order = Order::create([
            'customer_id' => $otherCustomer->id,
            'shop_owner_id' => $shopOwner->id,
            'order_number' => 'ORD-OTHER-001',
            'total_amount' => 1000,
            'status' => 'shipped',
            'payment_status' => 'paid',
        ]);

        $task = LogisticsTask::create([
            'shop_owner_id' => $shopOwner->id,
            'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
            'module_id' => $order->id,
            'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
        ]);

        $this->actingAs($customer, 'user')
            ->getJson("/api/customer/logistics/tasks/{$task->id}")
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Feature/Logistics/CustomerLogisticsTrackingTest.php`

Expected: fail because route/controller missing.

- [ ] **Step 3: Implement customer controller**

Create `app/Http/Controllers/Api/Customer/CustomerLogisticsTaskController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\LogisticsTask;
use App\Models\Order;
use App\Models\RepairRequest;

class CustomerLogisticsTaskController extends Controller
{
    public function index()
    {
        $customer = auth('user')->user();

        $tasks = LogisticsTask::query()
            ->where(function ($query) use ($customer) {
                $orderIds = Order::query()->where('customer_id', $customer->id)->pluck('id');
                $repairIds = RepairRequest::query()->where('user_id', $customer->id)->pluck('id');

                $query->where(function ($q) use ($orderIds) {
                    $q->where('module_type', LogisticsTask::MODULE_RETAIL_ORDER)
                        ->whereIn('module_id', $orderIds);
                })->orWhere(function ($q) use ($repairIds) {
                    $q->where('module_type', LogisticsTask::MODULE_REPAIR_DELIVERY)
                        ->whereIn('module_id', $repairIds);
                });
            })
            ->latest()
            ->get()
            ->map(fn (LogisticsTask $task) => $this->serializeTask($task));

        return response()->json(['data' => $tasks]);
    }

    public function show(LogisticsTask $task)
    {
        abort_unless($this->belongsToCustomer($task, auth('user')->id()), 403);

        return response()->json(['data' => $this->serializeTask($task)]);
    }

    private function belongsToCustomer(LogisticsTask $task, int $customerId): bool
    {
        if ($task->module_type === LogisticsTask::MODULE_RETAIL_ORDER) {
            return Order::query()
                ->where('id', $task->module_id)
                ->where('customer_id', $customerId)
                ->exists();
        }

        if ($task->module_type === LogisticsTask::MODULE_REPAIR_DELIVERY) {
            return RepairRequest::query()
                ->where('id', $task->module_id)
                ->where('user_id', $customerId)
                ->exists();
        }

        return false;
    }

    private function serializeTask(LogisticsTask $task): array
    {
        return [
            'id' => $task->id,
            'module_type' => $task->module_type,
            'module_id' => $task->module_id,
            'purpose' => $task->purpose,
            'status' => $task->status,
            'fulfillment_method' => $task->fulfillment_method ?? $task->provider_type,
            'carrier_company' => $task->carrier_company,
            'carrier_name' => $task->carrier_name,
            'carrier_phone' => $task->carrier_phone,
            'tracking_number' => $task->tracking_number,
            'tracking_link' => $task->tracking_link,
            'estimated_delivery_date' => optional($task->estimated_delivery_date)->toDateString(),
            'origin' => [
                'name' => $task->origin_name,
                'phone' => $task->origin_phone,
                'address_line' => $task->origin_address_line,
                'barangay' => $task->origin_barangay,
                'city' => $task->origin_city,
                'region' => $task->origin_region,
                'postal_code' => $task->origin_postal_code,
            ],
            'destination' => [
                'name' => $task->destination_name,
                'phone' => $task->destination_phone,
                'address_line' => $task->destination_address_line,
                'barangay' => $task->destination_barangay,
                'city' => $task->destination_city,
                'region' => $task->destination_region,
                'postal_code' => $task->destination_postal_code,
            ],
            'failure_reason' => $task->failure_reason,
            'failure_notes' => $task->failure_notes,
            'proof_urls' => $task->proof_urls ?? [],
        ];
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add under customer-auth API routes:

```php
Route::prefix('api/customer/logistics')
    ->middleware(['auth:user', 'customer.account'])
    ->group(function () {
        Route::get('/tasks', [\App\Http\Controllers\Api\Customer\CustomerLogisticsTaskController::class, 'index']);
        Route::get('/tasks/{task}', [\App\Http\Controllers\Api\Customer\CustomerLogisticsTaskController::class, 'show']);
    });
```

- [ ] **Step 5: Run test**

Run: `php artisan test tests/Feature/Logistics/CustomerLogisticsTrackingTest.php`

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Customer/CustomerLogisticsTaskController.php routes/web.php tests/Feature/Logistics/CustomerLogisticsTrackingTest.php
git commit -m "feat: add customer logistics tracking api"
```

---

### Task 6: Add Customer Delivery Tracking Page

**Files:**
- Create: `resources/js/Pages/UserSide/Logistics/DeliveryTracking.tsx`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`

**Interfaces:**
- Consumes `GET /api/customer/logistics/tasks` and `GET /api/customer/logistics/tasks/{task}` from Task 5.
- Produces customer route `/delivery-tracking` rendering `UserSide/Logistics/DeliveryTracking`.

- [ ] **Step 1: Create page**

Create `resources/js/Pages/UserSide/Logistics/DeliveryTracking.tsx`:

```tsx
import React, { useEffect, useState } from "react";
import { Head, Link } from "@inertiajs/react";

type LogisticsTask = {
  id: number;
  module_type: string;
  module_id: number;
  purpose: string | null;
  status: string;
  fulfillment_method: string | null;
  carrier_company?: string | null;
  carrier_name?: string | null;
  carrier_phone?: string | null;
  tracking_number?: string | null;
  tracking_link?: string | null;
  estimated_delivery_date?: string | null;
  failure_reason?: string | null;
  failure_notes?: string | null;
  proof_urls?: string[];
};

const label = (value?: string | null) =>
  String(value || "-").replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

export default function DeliveryTracking() {
  const [tasks, setTasks] = useState<LogisticsTask[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    fetch("/api/customer/logistics/tasks", {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((response) => response.ok ? response.json() : Promise.reject(response))
      .then((data) => {
        if (!cancelled) setTasks(Array.isArray(data?.data) ? data.data : []);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <>
      <Head title="Delivery Tracking - SoleSpace" />
      <main className="mx-auto max-w-5xl px-4 py-8">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-semibold text-gray-900">Delivery Tracking</h1>
            <p className="mt-1 text-sm text-gray-600">Track shipped products and repair deliveries.</p>
          </div>
          <Link href="/my-orders" className="text-sm font-medium text-blue-600 hover:text-blue-700">
            My Orders
          </Link>
        </div>

        {loading ? (
          <p className="text-sm text-gray-600">Loading deliveries...</p>
        ) : tasks.length === 0 ? (
          <div className="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600">
            No deliveries to track yet.
          </div>
        ) : (
          <div className="space-y-4">
            {tasks.map((task) => (
              <article key={task.id} className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <h2 className="text-base font-semibold text-gray-900">
                      {label(task.purpose)} #{task.module_id}
                    </h2>
                    <p className="text-sm text-gray-600">{label(task.fulfillment_method)}</p>
                  </div>
                  <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                    {label(task.status)}
                  </span>
                </div>

                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                  <div>
                    <dt className="text-gray-500">Carrier/Rider</dt>
                    <dd className="font-medium text-gray-900">{task.carrier_company || task.carrier_name || "-"}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Phone</dt>
                    <dd className="font-medium text-gray-900">{task.carrier_phone || "-"}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Tracking Number</dt>
                    <dd className="font-medium text-gray-900">{task.tracking_number || "-"}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Estimated Delivery</dt>
                    <dd className="font-medium text-gray-900">{task.estimated_delivery_date || "-"}</dd>
                  </div>
                </dl>

                {task.tracking_link && (
                  <a
                    href={task.tracking_link}
                    target="_blank"
                    rel="noreferrer"
                    className="mt-4 inline-flex text-sm font-medium text-blue-600 hover:text-blue-700"
                  >
                    Open tracking link
                  </a>
                )}

                {(task.failure_reason || task.failure_notes) && (
                  <p className="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                    {label(task.failure_reason)} {task.failure_notes ? `- ${task.failure_notes}` : ""}
                  </p>
                )}
              </article>
            ))}
          </div>
        )}
      </main>
    </>
  );
}
```

- [ ] **Step 2: Add page route**

In `routes/web.php`, add:

```php
Route::get('/delivery-tracking', function () {
    return Inertia::render('UserSide/Logistics/DeliveryTracking');
})->middleware(['auth:user', 'customer.account'])->name('customer.delivery-tracking');
```

- [ ] **Step 3: Link from existing customer pages**

In `resources/js/Pages/UserSide/Orders/MyOrders.tsx`, add a link near order tracking/status actions:

```tsx
<Link href="/delivery-tracking" className="text-sm font-medium text-blue-600 hover:text-blue-700">
  Track delivery
</Link>
```

In `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`, add the same link near repair logistics details:

```tsx
<Link href="/delivery-tracking" className="text-sm font-medium text-blue-600 hover:text-blue-700">
  Track delivery
</Link>
```

- [ ] **Step 4: Run frontend type check or build**

Run: `npm run build`

Expected: build succeeds.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/UserSide/Logistics/DeliveryTracking.tsx resources/js/Pages/UserSide/Orders/MyOrders.tsx resources/js/Pages/UserSide/Repairs/myRepairs.tsx routes/web.php
git commit -m "feat: add customer delivery tracking page"
```

---

### Task 7: Send Customer Logistics Notifications

**Files:**
- Create: `app/Services/Logistics/LogisticsNotificationService.php`
- Modify: `app/Services/Logistics/LogisticsTaskService.php`
- Create: `tests/Feature/Logistics/LogisticsNotificationTest.php`

**Interfaces:**
- Consumes existing `App\Models\Notification` model.
- Produces `notifyCustomer(LogisticsTask $task, string $event): void`.

- [ ] **Step 1: Write failing notification test**

Create `tests/Feature/Logistics/LogisticsNotificationTest.php`:

```php
<?php

namespace Tests\Feature\Logistics;

use App\Models\LogisticsTask;
use App\Models\Notification;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\LogisticsTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_is_notified_when_delivery_moves_in_transit(): void
    {
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->create();
        $order = Order::create([
            'customer_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'order_number' => 'ORD-NOTIFY-001',
            'total_amount' => 1000,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);

        $task = LogisticsTask::create([
            'shop_owner_id' => $shopOwner->id,
            'module_type' => LogisticsTask::MODULE_RETAIL_ORDER,
            'module_id' => $order->id,
            'purpose' => LogisticsTask::PURPOSE_RETAIL_DELIVERY,
            'status' => LogisticsTask::STATUS_SCHEDULED,
        ]);

        app(LogisticsTaskService::class)->markInTransit($task, [
            'carrier_company' => 'Lalamove',
            'tracking_number' => 'TRK1',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'type' => 'logistics_status_update',
        ]);

        $notification = Notification::where('user_id', $customer->id)->latest()->first();
        $this->assertStringContainsString('/delivery-tracking', json_encode($notification->data ?? []));
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php`

Expected: fail because service integration missing.

- [ ] **Step 3: Add notification service**

Create `app/Services/Logistics/LogisticsNotificationService.php`:

```php
<?php

namespace App\Services\Logistics;

use App\Models\LogisticsTask;
use App\Models\Notification;
use App\Models\Order;
use App\Models\RepairRequest;

class LogisticsNotificationService
{
    public function notifyCustomer(LogisticsTask $task, string $event): void
    {
        $customerId = $this->customerIdForTask($task);

        if (!$customerId) {
            return;
        }

        Notification::create([
            'user_id' => $customerId,
            'type' => 'logistics_status_update',
            'title' => 'Delivery update',
            'message' => $this->messageFor($task, $event),
            'data' => [
                'task_id' => $task->id,
                'module_type' => $task->module_type,
                'module_id' => $task->module_id,
                'purpose' => $task->purpose,
                'status' => $task->status,
                'url' => '/delivery-tracking',
            ],
            'is_read' => false,
        ]);
    }

    private function customerIdForTask(LogisticsTask $task): ?int
    {
        if ($task->module_type === LogisticsTask::MODULE_RETAIL_ORDER) {
            return Order::query()->whereKey($task->module_id)->value('customer_id');
        }

        if ($task->module_type === LogisticsTask::MODULE_REPAIR_DELIVERY) {
            return RepairRequest::query()->whereKey($task->module_id)->value('user_id');
        }

        return null;
    }

    private function messageFor(LogisticsTask $task, string $event): string
    {
        return match ($event) {
            'created' => 'A delivery task was created for your order or repair.',
            'ready_for_pickup' => 'Your item is ready for pickup or handoff.',
            'assigned' => 'A rider or courier has been assigned.',
            'in_transit' => 'Your delivery is now in transit.',
            'delivered' => 'Your delivery was marked delivered.',
            'received' => 'Your delivery receipt was confirmed.',
            'failed' => 'Delivery attempt failed. Please check tracking details.',
            'rescheduled' => 'Your delivery was rescheduled.',
            'cancelled' => 'Your delivery task was cancelled.',
            default => 'Your delivery status was updated.',
        };
    }
}
```

- [ ] **Step 4: Call notification service from logistics task service**

In `LogisticsTaskService`, after status-changing saves, call:

```php
app(\App\Services\Logistics\LogisticsNotificationService::class)
    ->notifyCustomer($task->refresh(), 'in_transit');
```

Use event names matching each method:

```php
'created'
'ready_for_pickup'
'assigned'
'in_transit'
'delivered'
'received'
'failed'
'rescheduled'
'cancelled'
```

- [ ] **Step 5: Run test**

Run: `php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php`

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Logistics tests/Feature/Logistics/LogisticsNotificationTest.php
git commit -m "feat: notify customers of logistics updates"
```

---

### Task 8: Add Logistics Employee Account Option

**Files:**
- Modify: permission seeder file that creates staff permissions
- Modify: role template service or position template service used by `UserAccessControl`
- Modify: `resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx`
- Create: `tests/Feature/ShopOwner/LogisticsEmployeeAccountTest.php`

**Interfaces:**
- Consumes existing user access control/invitation flow.
- Produces role/position option with permissions:
  - `access-logistics-queue`
  - `manage-logistics-shipments`

- [ ] **Step 1: Write failing account test**

Create `tests/Feature/ShopOwner/LogisticsEmployeeAccountTest.php`:

```php
<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LogisticsEmployeeAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_logistics_role_has_logistics_permissions(): void
    {
        Permission::firstOrCreate(['name' => 'access-logistics-queue', 'guard_name' => 'user']);
        Permission::firstOrCreate(['name' => 'manage-logistics-shipments', 'guard_name' => 'user']);

        $role = Role::firstOrCreate(['name' => 'Logistics', 'guard_name' => 'user']);
        $role->syncPermissions(['access-logistics-queue', 'manage-logistics-shipments']);

        $shopOwner = ShopOwner::factory()->create(['registration_type' => 'company']);
        $employee = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $employee->assignRole($role);

        $this->assertTrue($employee->can('access-logistics-queue'));
        $this->assertTrue($employee->can('manage-logistics-shipments'));
    }
}
```

- [ ] **Step 2: Run test**

Run: `php artisan test tests/Feature/ShopOwner/LogisticsEmployeeAccountTest.php`

Expected: pass if permission package works, but fail if role/permission seeding is missing in app flow.

- [ ] **Step 3: Add role to backend templates**

Find existing role/position template source:

Run: `rg -n "position|template|Cashier|Repairer|Manager|access-logistics-queue" app database routes`

Add `Logistics` to the same template list:

```php
[
    'name' => 'Logistics',
    'permissions' => [
        'access-logistics-queue',
        'manage-logistics-shipments',
    ],
]
```

- [ ] **Step 4: Add UI option**

In `resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx`, add a `Logistics` position/role option wherever existing options are defined:

```tsx
{
  value: "Logistics",
  label: "Logistics",
  permissions: ["access-logistics-queue", "manage-logistics-shipments"],
}
```

- [ ] **Step 5: Verify route access**

Run: `php artisan test tests/Feature/ShopOwner/LogisticsEmployeeAccountTest.php tests/Feature/ErpAccessTest.php`

Expected: pass.

- [ ] **Step 6: Build frontend**

Run: `npm run build`

Expected: build succeeds.

- [ ] **Step 7: Commit**

```bash
git add app database resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx tests/Feature/ShopOwner/LogisticsEmployeeAccountTest.php
git commit -m "feat: add logistics employee role"
```

---

### Task 9: Update Logistics Queue UI For Purpose, Fees, Failure, Reschedule

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/LogisticsQueue.tsx`

**Interfaces:**
- Consumes extended logistics API from Tasks 1 and 3.
- Produces filters and actions for purpose, method/provider, failed, rescheduled, cancelled.
- Keeps `owner_delivery` visible only when `registration_type` is `individual`.

- [ ] **Step 1: Extend TypeScript type**

In `LogisticsQueue.tsx`, extend `type LogisticsTask`:

```tsx
purpose?: string | null;
direction?: string | null;
fulfillment_method?: string | null;
customer_shipping_fee?: number | string | null;
actual_logistics_cost?: number | string | null;
failure_reason?: string | null;
failure_notes?: string | null;
rescheduled_at?: string | null;
delivered_at?: string | null;
received_at?: string | null;
cancelled_at?: string | null;
origin_name?: string | null;
destination_name?: string | null;
destination_city?: string | null;
```

- [ ] **Step 2: Add display helpers**

Add near existing helpers:

```tsx
const humanize = (value?: string | null) =>
  String(value || "-").replace(/_/g, " ").replace(/\b\w/g, (char) => char.toUpperCase());

const formatPeso = (value?: number | string | null) => {
  const amount = Number(value ?? 0);
  return Number.isFinite(amount) && amount > 0 ? `₱${amount.toLocaleString()}` : "-";
};
```

- [ ] **Step 2A: Keep owner delivery hidden for company shops**

Ensure `showOwnerDelivery` stays equivalent to:

```tsx
const showOwnerDelivery = registrationType === "individual";
```

Ensure `showShopLogistics` stays equivalent to:

```tsx
const showShopLogistics = registrationType !== "individual";
```

For company/business accounts, provider options must not include `owner_delivery`. For individual accounts, provider options must not include `shop_logistics` because individual shops have no employee assignees.

- [ ] **Step 3: Add purpose filter state and API param**

Add:

```tsx
const [purposeFilter, setPurposeFilter] = useState("");
```

Add to `axios.get` params:

```tsx
purpose: purposeFilter || undefined,
```

Add dependency:

```tsx
}, [statusFilter, providerFilter, purposeFilter]);
```

- [ ] **Step 4: Add filter select**

Add next to status/provider filters:

```tsx
<select
  value={purposeFilter}
  onChange={(e) => setPurposeFilter(e.target.value)}
  className="px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm"
>
  <option value="">All Purposes</option>
  <option value="retail_delivery">Retail Delivery</option>
  <option value="retail_return">Retail Return</option>
  <option value="repair_intake">Repair Intake</option>
  <option value="repair_return">Repair Return</option>
</select>
```

- [ ] **Step 5: Add columns**

Add columns for purpose, destination, fees. Keep table width stable by replacing lower-value columns rather than making table too wide:

```tsx
<td className="box-border px-4 py-4 align-top">
  <span className="text-sm text-gray-700 dark:text-gray-300">{humanize(task.purpose)}</span>
</td>
<td className="box-border px-4 py-4 align-top">
  <span className="text-sm text-gray-700 dark:text-gray-300">
    {task.destination_name || task.destination_city || "-"}
  </span>
</td>
<td className="box-border px-4 py-4 align-top">
  <span className="text-sm text-gray-700 dark:text-gray-300">
    {formatPeso(task.customer_shipping_fee)}
  </span>
</td>
```

- [ ] **Step 6: Add failed/reschedule actions**

Add buttons for `in_transit` and `failed` statuses:

```tsx
{task.status === "in_transit" && (
  <button
    onClick={() => openFailureModal(task)}
    className="px-2.5 py-1.5 text-xs rounded-lg border border-red-200 text-red-700 bg-red-50 hover:bg-red-100"
    disabled={isSubmitting}
  >
    Mark Failed
  </button>
)}
{task.status === "failed" && (
  <button
    onClick={() => openRescheduleModal(task)}
    className="px-2.5 py-1.5 text-xs rounded-lg border border-amber-200 text-amber-700 bg-amber-50 hover:bg-amber-100"
    disabled={isSubmitting}
  >
    Reschedule
  </button>
)}
```

Implement modals using existing modal pattern in this file. Submit to:

```tsx
`${logisticsApiBase}/tasks/${selectedTask.id}/mark-failed`
`${logisticsApiBase}/tasks/${selectedTask.id}/reschedule`
```

- [ ] **Step 7: Build**

Run: `npm run build`

Expected: build succeeds.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/LogisticsQueue.tsx
git commit -m "feat: extend logistics queue controls"
```

---

### Task 10: Final Verification And Documentation Update

**Files:**
- Modify: `docs/superpowers/specs/2026-07-08-logistics-module-design.md` only if implementation decisions changed.
- Modify: `docs/superpowers/plans/2026-07-09-logistics-module.md` only if execution revealed wrong paths.

**Interfaces:**
- Consumes all prior tasks.
- Produces verified feature branch.

- [ ] **Step 1: Run backend logistics tests**

Run:

```bash
php artisan test tests/Unit/Logistics tests/Feature/Logistics tests/Feature/ShopOwner/LogisticsEmployeeAccountTest.php
```

Expected: all pass.

- [ ] **Step 2: Run notification regression tests**

Run:

```bash
php artisan test tests/Feature/Notifications
```

Expected: all pass.

- [ ] **Step 3: Run frontend build**

Run:

```bash
npm run build
```

Expected: build succeeds.

- [ ] **Step 4: Manual smoke test**

Use local app:

1. Login as shop owner.
2. Create logistics employee account with Logistics role.
3. Login as logistics employee.
4. Open ERP logistics queue.
5. Ship retail order and confirm task appears.
6. Mark task in transit.
7. Login as customer.
8. Open `/delivery-tracking`.
9. Confirm retail delivery appears with status and tracking.
10. Mark task failed and rescheduled as logistics employee.
11. Confirm customer notification appears.
12. Repeat one repair return delivery.

Expected: retail and repair logistics both visible, status changes persist, notification links point to `/delivery-tracking`.

- [ ] **Step 5: Commit final docs if changed**

```bash
git add docs/superpowers/specs/2026-07-08-logistics-module-design.md docs/superpowers/plans/2026-07-09-logistics-module.md
git commit -m "docs: update logistics implementation notes"
```

If docs did not change, skip commit.

---

## Self-Review

Spec coverage:

- Retail delivery: Task 4.
- Retail return: Task 4.
- Repair intake: Task 4.
- Repair return: Task 4.
- Shared `LogisticsTask` data model: Task 1.
- Shared service path: Task 2.
- Failure/reschedule/cancel lifecycle: Task 3 and Task 9.
- Customer tracking page: Task 5 and Task 6.
- Customer notifications: Task 7.
- Logistics employee accounts: Task 8.
- Logistics queue filters/columns: Task 9.
- Final verification: Task 10.

Known execution blocker:

- Backend implementation paths are inferred from route references and tests. Restore `app/` and `database/` source before executing this plan.
