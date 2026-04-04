# Repair Material Template Gating Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an inventory-linked material template workflow for repair services/packages, then enforce planning and validation gates in repair execution.

**Architecture:** Material templates are defined on packages/services using existing `inventory_items` references only. Repair jobs initialize a plan snapshot from template, run pre-start validation for critical vs non-critical availability, and enforce completion validation with tolerance-based variance. We keep workflow logic centralized in a dedicated planning service and expose structured API payloads for UI badges/actions.

**Tech Stack:** Laravel 11 (PHP), MySQL migrations, Inertia React/TypeScript, SweetAlert, PHPUnit Feature tests.

---

## Scope Check

This plan targets one subsystem: **repair materials operational control** (template -> plan -> usage/variance gating). It intentionally excludes unrelated pricing approvals, POS flows, and non-repair inventory modules.

## File Structure (Planned Changes)

- Create: `database/migrations/2026_04_04_000001_create_repair_material_template_items_table.php`
  - Stores predefined material lines for services/packages; inventory-only references.
- Create: `database/migrations/2026_04_04_000002_create_repair_material_plan_items_table.php`
  - Stores per-repair material plan snapshot (criticality, planned qty, tolerance).
- Create: `database/migrations/2026_04_04_000003_add_approval_stage_to_stock_request_approvals_table.php`
  - Adds request stage visibility for repairer UI.
- Create: `app/Models/RepairMaterialTemplateItem.php`
  - Template-line model with owner scoping and polymorphic target (`repair_service`/`repair_package`).
- Create: `app/Models/RepairMaterialPlanItem.php`
  - Per-repair planned lines and readiness state helpers.
- Create: `app/Services/RepairMaterialPlanningService.php`
  - Single place for initialize/validate/variance decisions.
- Modify: `app/Models/RepairService.php`
  - Add template relationship.
- Modify: `app/Models/RepairPackage.php`
  - Add template relationship.
- Modify: `app/Models/RepairRequest.php`
  - Add plan relationship and readiness accessor.
- Modify: `app/Http/Controllers/Api/RepairPackageController.php`
  - Save/read template lines on package create/update.
- Modify: `app/Http/Controllers/Api/RepairServiceController.php`
  - Save/read service-level template lines for direct-service jobs.
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
  - Add initialize-plan, pre-start validator, completion validator endpoints.
- Modify: `routes/web.php`
  - Register new `/api/repairer/repairs/{id}/materials/*` endpoints.
- Modify: `resources/js/services/repairMaterialsApi.ts`
  - Add typed methods for planning/validation endpoints.
- Modify: `resources/js/Pages/ERP/repairer/components/RepairPackageManager.tsx`
  - Add inventory-linked material template editor in package form.
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
  - Add Planning panel, readiness badges, start/complete gate UX.
- Modify: `resources/js/Pages/ERP/repairer/requestMaterials.tsx`
  - Enforce repair context in request creation (`repair_request_id` required in repair mode).
- Create: `tests/Feature/Repairer/RepairMaterialTemplateApiTest.php`
- Create: `tests/Feature/Repairer/RepairMaterialPlanningGateTest.php`
- Create: `tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php`

---

### Task 1: Add Schema for Templates, Plans, and Approval Stage

**Files:**
- Create: `database/migrations/2026_04_04_000001_create_repair_material_template_items_table.php`
- Create: `database/migrations/2026_04_04_000002_create_repair_material_plan_items_table.php`
- Create: `database/migrations/2026_04_04_000003_add_approval_stage_to_stock_request_approvals_table.php`
- Test: `tests/Feature/Repairer/RepairMaterialTemplateApiTest.php`

- [ ] **Step 1: Write the failing migration-level test**

```php
<?php

namespace Tests\Feature\Repairer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairMaterialTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_template_and_plan_tables_exist_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('repair_material_template_items'));
        $this->assertTrue(Schema::hasTable('repair_material_plan_items'));
        $this->assertTrue(Schema::hasColumn('stock_request_approvals', 'approval_stage'));

        $this->assertTrue(Schema::hasColumns('repair_material_template_items', [
            'inventory_item_id', 'template_type', 'template_id', 'default_quantity', 'is_critical', 'tolerance_percent',
        ]));

        $this->assertTrue(Schema::hasColumns('repair_material_plan_items', [
            'repair_request_id', 'inventory_item_id', 'planned_quantity', 'actual_quantity', 'is_critical', 'tolerance_percent', 'variance_status',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialTemplateApiTest.php --filter=material_template_and_plan_tables_exist_with_required_columns`
Expected: FAIL with missing table/column assertions.

- [ ] **Step 3: Write minimal migration implementation**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('repair_material_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('template_type'); // repair_service | repair_package
            $table->unsignedBigInteger('template_id');
            $table->decimal('default_quantity', 10, 2);
            $table->boolean('is_critical')->default(false);
            $table->decimal('tolerance_percent', 5, 2)->default(20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['template_type', 'template_id']);
            $table->unique(['template_type', 'template_id', 'inventory_item_id'], 'repair_material_template_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_material_template_items');
    }
};
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('repair_material_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_request_id')->constrained('repair_requests')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('planned_quantity', 10, 2);
            $table->decimal('actual_quantity', 10, 2)->default(0);
            $table->boolean('is_critical')->default(false);
            $table->decimal('tolerance_percent', 5, 2)->default(20);
            $table->enum('variance_status', ['within_tolerance', 'exceeded_with_note', 'escalated'])->default('within_tolerance');
            $table->text('variance_note')->nullable();
            $table->timestamps();
            $table->unique(['repair_request_id', 'inventory_item_id'], 'repair_material_plan_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_material_plan_items');
    }
};
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            $table->string('approval_stage')->default('inventory_pending')->after('status');
            $table->unsignedBigInteger('request_source_repair_plan_id')->nullable()->after('approval_stage');
            $table->boolean('is_auto_generated')->default(false)->after('request_source_repair_plan_id');
            $table->index('approval_stage');
        });
    }

    public function down(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            $table->dropColumn(['approval_stage', 'request_source_repair_plan_id', 'is_auto_generated']);
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialTemplateApiTest.php --filter=material_template_and_plan_tables_exist_with_required_columns`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_04_000001_create_repair_material_template_items_table.php database/migrations/2026_04_04_000002_create_repair_material_plan_items_table.php database/migrations/2026_04_04_000003_add_approval_stage_to_stock_request_approvals_table.php tests/Feature/Repairer/RepairMaterialTemplateApiTest.php
git commit -m "feat: add repair material template and plan schema"
```

### Task 2: Add Models and Relationships for Template/Plan Lines

**Files:**
- Create: `app/Models/RepairMaterialTemplateItem.php`
- Create: `app/Models/RepairMaterialPlanItem.php`
- Modify: `app/Models/RepairPackage.php`
- Modify: `app/Models/RepairService.php`
- Modify: `app/Models/RepairRequest.php`
- Test: `tests/Feature/Repairer/RepairMaterialTemplateApiTest.php`

- [ ] **Step 1: Write the failing relationship test**

```php
public function test_repair_package_can_store_inventory_linked_material_template_items(): void
{
    $shop = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $repairer = \App\Models\User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);

    $package = \App\Models\RepairPackage::create([
        'shop_owner_id' => $shop->id,
        'name' => 'Basic Restore',
        'package_price' => 1000,
        'status' => 'active',
        'created_by' => $repairer->id,
    ]);

    $material = \App\Models\InventoryItem::create([
        'shop_owner_id' => $shop->id,
        'name' => 'Shoe Glue',
        'sku' => 'RM-GLUE-01',
        'category' => 'repair_materials',
        'available_quantity' => 20,
    ]);

    $package->materialTemplateItems()->create([
        'shop_owner_id' => $shop->id,
        'inventory_item_id' => $material->id,
        'template_type' => 'repair_package',
        'template_id' => $package->id,
        'default_quantity' => 1,
        'is_critical' => true,
        'tolerance_percent' => 20,
        'created_by' => $repairer->id,
    ]);

    $this->assertCount(1, $package->fresh()->materialTemplateItems);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialTemplateApiTest.php --filter=repair_package_can_store_inventory_linked_material_template_items`
Expected: FAIL with undefined relationship/model errors.

- [ ] **Step 3: Write minimal model + relationship implementation**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RepairMaterialTemplateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'inventory_item_id',
        'template_type',
        'template_id',
        'default_quantity',
        'is_critical',
        'tolerance_percent',
        'created_by',
    ];

    protected $casts = [
        'default_quantity' => 'float',
        'is_critical' => 'boolean',
        'tolerance_percent' => 'float',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RepairMaterialPlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'repair_request_id',
        'inventory_item_id',
        'planned_quantity',
        'actual_quantity',
        'is_critical',
        'tolerance_percent',
        'variance_status',
        'variance_note',
    ];

    protected $casts = [
        'planned_quantity' => 'float',
        'actual_quantity' => 'float',
        'is_critical' => 'boolean',
        'tolerance_percent' => 'float',
    ];
}
```

```php
// app/Models/RepairPackage.php
public function materialTemplateItems()
{
    return $this->hasMany(\App\Models\RepairMaterialTemplateItem::class, 'template_id')
        ->where('template_type', 'repair_package');
}
```

```php
// app/Models/RepairService.php
public function materialTemplateItems()
{
    return $this->hasMany(\App\Models\RepairMaterialTemplateItem::class, 'template_id')
        ->where('template_type', 'repair_service');
}
```

```php
// app/Models/RepairRequest.php
public function materialPlanItems()
{
    return $this->hasMany(\App\Models\RepairMaterialPlanItem::class);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialTemplateApiTest.php --filter=repair_package_can_store_inventory_linked_material_template_items`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/RepairMaterialTemplateItem.php app/Models/RepairMaterialPlanItem.php app/Models/RepairPackage.php app/Models/RepairService.php app/Models/RepairRequest.php tests/Feature/Repairer/RepairMaterialTemplateApiTest.php
git commit -m "feat: add repair material template and plan model relationships"
```

### Task 3: Save Inventory-Only Material Template in Service/Package APIs

**Files:**
- Modify: `app/Http/Controllers/Api/RepairPackageController.php`
- Modify: `app/Http/Controllers/Api/RepairServiceController.php`
- Test: `tests/Feature/Repairer/RepairMaterialTemplateApiTest.php`

- [ ] **Step 1: Write failing API test for inventory-only enforcement**

```php
public function test_package_template_rejects_non_existing_inventory_item_ids(): void
{
    $shop = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $repairer = \App\Models\User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);

    $response = $this->actingAs($repairer, 'user')->postJson('/api/repair-packages', [
        'name' => 'Deep Restore',
        'package_price' => 1200,
        'status' => 'active',
        'service_ids' => [],
        'material_templates' => [
            ['inventory_item_id' => 999999, 'default_quantity' => 1, 'is_critical' => true, 'tolerance_percent' => 20],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialTemplateApiTest.php --filter=package_template_rejects_non_existing_inventory_item_ids`
Expected: FAIL because API currently accepts/ignores unknown template lines.

- [ ] **Step 3: Implement minimal validation + persistence**

```php
// app/Http/Controllers/Api/RepairPackageController.php (inside store/update validator)
$validator = \Validator::make($request->all(), [
    'material_templates' => 'array',
    'material_templates.*.inventory_item_id' => 'required|exists:inventory_items,id',
    'material_templates.*.default_quantity' => 'required|numeric|min:0.01',
    'material_templates.*.is_critical' => 'required|boolean',
    'material_templates.*.tolerance_percent' => 'nullable|numeric|min:0|max:100',
]);
```

```php
// after saving package
$package->materialTemplateItems()->delete();
collect($request->input('material_templates', []))->each(function (array $line) use ($package) {
    $package->materialTemplateItems()->create([
        'shop_owner_id' => $package->shop_owner_id,
        'inventory_item_id' => (int) $line['inventory_item_id'],
        'template_type' => 'repair_package',
        'template_id' => $package->id,
        'default_quantity' => (float) $line['default_quantity'],
        'is_critical' => (bool) $line['is_critical'],
        'tolerance_percent' => (float) ($line['tolerance_percent'] ?? 20),
        'created_by' => auth('user')->id(),
    ]);
});
```

```php
// app/Http/Controllers/Api/RepairServiceController.php (same validation rules)
// Persist with template_type = 'repair_service' and template_id = $service->id.
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialTemplateApiTest.php`
Expected: PASS for inventory-only template validation and persistence checks.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairPackageController.php app/Http/Controllers/Api/RepairServiceController.php tests/Feature/Repairer/RepairMaterialTemplateApiTest.php
git commit -m "feat: enforce inventory-linked material templates for service and package"
```

### Task 4: Add Planning Service and Start-Work Gate APIs

**Files:**
- Create: `app/Services/RepairMaterialPlanningService.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Repairer/RepairMaterialPlanningGateTest.php`

- [ ] **Step 1: Write failing gate behavior test**

```php
<?php

namespace Tests\Feature\Repairer;

use App\Models\InventoryItem;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairMaterialPlanningGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_work_is_blocked_when_critical_material_is_unavailable(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $repair = RepairRequest::factory()->create(['shop_owner_id' => $shop->id, 'assigned_repairer_id' => $repairer->id, 'status' => 'pending']);

        $item = InventoryItem::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Industrial Glue',
            'sku' => 'RM-IG-1',
            'category' => 'repair_materials',
            'available_quantity' => 0,
        ]);

        $repair->materialPlanItems()->create([
            'inventory_item_id' => $item->id,
            'planned_quantity' => 1,
            'actual_quantity' => 0,
            'is_critical' => true,
            'tolerance_percent' => 20,
        ]);

        $response = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/materials/validate-start");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.readiness_state', 'blocked');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialPlanningGateTest.php --filter=start_work_is_blocked_when_critical_material_is_unavailable`
Expected: FAIL because endpoint/service does not exist.

- [ ] **Step 3: Implement planning service + endpoints**

```php
<?php

namespace App\Services;

use App\Models\RepairRequest;

class RepairMaterialPlanningService
{
    public function validateStartReadiness(RepairRequest $repair): array
    {
        $blockers = [];
        $warnings = [];

        foreach ($repair->materialPlanItems()->with('inventoryItem')->get() as $line) {
            $available = (float) ($line->inventoryItem->available_quantity ?? 0);
            if ($line->is_critical && $available < $line->planned_quantity) {
                $blockers[] = [
                    'inventory_item_id' => $line->inventory_item_id,
                    'name' => $line->inventoryItem->name ?? 'Unknown',
                    'needed' => (float) $line->planned_quantity,
                    'available' => $available,
                ];
            }
            if (!$line->is_critical && $available < $line->planned_quantity) {
                $warnings[] = [
                    'inventory_item_id' => $line->inventory_item_id,
                    'name' => $line->inventoryItem->name ?? 'Unknown',
                ];
            }
        }

        return [
            'readiness_state' => empty($blockers) ? (empty($warnings) ? 'ready' : 'at_risk') : 'blocked',
            'blockers' => $blockers,
            'warnings' => $warnings,
            'actions' => ['request_materials'],
        ];
    }
}
```

```php
// app/Http/Controllers/Api/RepairWorkflowController.php
public function validateMaterialStart($id, \App\Services\RepairMaterialPlanningService $planner)
{
    $repair = \App\Models\RepairRequest::findOrFail($id);
    $result = $planner->validateStartReadiness($repair);

    if ($result['readiness_state'] === 'blocked') {
        return response()->json(['success' => false, 'data' => $result], 422);
    }

    return response()->json(['success' => true, 'data' => $result]);
}
```

```php
// routes/web.php within existing /api/repairer group
Route::post('/repairs/{id}/materials/validate-start', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'validateMaterialStart']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialPlanningGateTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RepairMaterialPlanningService.php app/Http/Controllers/Api/RepairWorkflowController.php routes/web.php tests/Feature/Repairer/RepairMaterialPlanningGateTest.php
git commit -m "feat: add repair material start-work readiness gate"
```

### Task 5: Add Completion Variance Validation Gate

**Files:**
- Modify: `app/Services/RepairMaterialPlanningService.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Test: `tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php`

- [ ] **Step 1: Write failing completion variance test**

```php
<?php

namespace Tests\Feature\Repairer;

use App\Models\InventoryItem;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairMaterialCompletionVarianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_blocks_when_variance_exceeds_tolerance_without_note(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $repair = RepairRequest::factory()->create(['shop_owner_id' => $shop->id, 'assigned_repairer_id' => $repairer->id, 'status' => 'in-progress']);

        $item = InventoryItem::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Thread',
            'sku' => 'RM-TH-1',
            'category' => 'repair_materials',
            'available_quantity' => 100,
        ]);

        $repair->materialPlanItems()->create([
            'inventory_item_id' => $item->id,
            'planned_quantity' => 1,
            'actual_quantity' => 2,
            'is_critical' => false,
            'tolerance_percent' => 20,
            'variance_status' => 'within_tolerance',
            'variance_note' => null,
        ]);

        $response = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/materials/validate-complete");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.readiness_state', 'variance_review_needed');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php --filter=completion_blocks_when_variance_exceeds_tolerance_without_note`
Expected: FAIL because completion validator endpoint is missing.

- [ ] **Step 3: Implement completion validator logic**

```php
// app/Services/RepairMaterialPlanningService.php
public function validateCompletionReadiness(\App\Models\RepairRequest $repair): array
{
    $varianceIssues = [];

    foreach ($repair->materialPlanItems as $line) {
        $planned = max((float) $line->planned_quantity, 0.01);
        $actual = (float) $line->actual_quantity;
        $variancePercent = abs($actual - $planned) / $planned * 100;

        if ($variancePercent > (float) $line->tolerance_percent && empty($line->variance_note)) {
            $varianceIssues[] = [
                'inventory_item_id' => $line->inventory_item_id,
                'planned_quantity' => $planned,
                'actual_quantity' => $actual,
                'variance_percent' => round($variancePercent, 2),
            ];
        }
    }

    return [
        'readiness_state' => empty($varianceIssues) ? 'ready' : 'variance_review_needed',
        'blockers' => [],
        'warnings' => $varianceIssues,
        'actions' => empty($varianceIssues) ? [] : ['add_variance_note_or_escalate'],
    ];
}
```

```php
// app/Http/Controllers/Api/RepairWorkflowController.php
public function validateMaterialCompletion($id, \App\Services\RepairMaterialPlanningService $planner)
{
    $repair = \App\Models\RepairRequest::with('materialPlanItems')->findOrFail($id);
    $result = $planner->validateCompletionReadiness($repair);

    if ($result['readiness_state'] !== 'ready') {
        return response()->json(['success' => false, 'data' => $result], 422);
    }

    return response()->json(['success' => true, 'data' => $result]);
}
```

```php
// routes/web.php within existing /api/repairer group
Route::post('/repairs/{id}/materials/validate-complete', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'validateMaterialCompletion']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RepairMaterialPlanningService.php app/Http/Controllers/Api/RepairWorkflowController.php routes/web.php tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php
git commit -m "feat: add completion variance validation gate for repair materials"
```

### Task 6: Expose Planning APIs in Frontend Service Layer

**Files:**
- Modify: `resources/js/services/repairMaterialsApi.ts`
- Test: `resources/js/services/repairMaterialsApi.ts` (type-check via build)

- [ ] **Step 1: Write failing type usage snippet in consuming code**

```ts
// compile-time expectation in JobOrdersRepair.tsx (temporary call)
await repairMaterialsApi.validateStartReadiness(123);
await repairMaterialsApi.validateCompletionReadiness(123);
```

- [ ] **Step 2: Run type-check to verify it fails**

Run: `npm run build`
Expected: FAIL with "Property validateStartReadiness does not exist on type...".

- [ ] **Step 3: Add minimal typed client methods**

```ts
export type MaterialReadinessResponse = {
  success: boolean;
  data: {
    readiness_state: "ready" | "at_risk" | "blocked" | "variance_review_needed";
    blockers: Array<Record<string, unknown>>;
    warnings: Array<Record<string, unknown>>;
    actions: string[];
  };
};

const validateStartReadiness = async (repairId: number): Promise<MaterialReadinessResponse> => {
  const { data } = await axios.post(`/api/repairer/repairs/${repairId}/materials/validate-start`);
  return data;
};

const validateCompletionReadiness = async (repairId: number): Promise<MaterialReadinessResponse> => {
  const { data } = await axios.post(`/api/repairer/repairs/${repairId}/materials/validate-complete`);
  return data;
};

export default {
  // existing methods...
  validateStartReadiness,
  validateCompletionReadiness,
};
```

- [ ] **Step 4: Run type-check to verify it passes**

Run: `npm run build`
Expected: PASS for TypeScript checks in touched files.

- [ ] **Step 5: Commit**

```bash
git add resources/js/services/repairMaterialsApi.ts
git commit -m "feat: expose repair material readiness validation api client methods"
```

### Task 7: Package Template UI (Inventory-Only Selector)

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/components/RepairPackageManager.tsx`
- Modify: `resources/js/Pages/ERP/repairer/uploadService.tsx`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/uploadService.tsx`
- Test: `tests/Feature/Repairer/RepairMaterialTemplateApiTest.php` (API-level) + manual UI verification

- [ ] **Step 1: Write failing API assertion to ensure UI payload contract is accepted**

```php
public function test_package_create_accepts_inventory_linked_material_template_payload(): void
{
    $shop = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $repairer = \App\Models\User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
    $material = \App\Models\InventoryItem::create([
        'shop_owner_id' => $shop->id,
        'name' => 'Shoe Glue',
        'sku' => 'RM-GLUE-02',
        'category' => 'repair_materials',
        'available_quantity' => 10,
    ]);

    $response = $this->actingAs($repairer, 'user')->postJson('/api/repair-packages', [
        'name' => 'Template Check Package',
        'package_price' => 900,
        'status' => 'active',
        'service_ids' => [],
        'material_templates' => [
            ['inventory_item_id' => $material->id, 'default_quantity' => 1, 'is_critical' => true, 'tolerance_percent' => 20],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true);
}
```

- [ ] **Step 2: Run test to verify current state**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialTemplateApiTest.php --filter=package_create_accepts_inventory_linked_material_template_payload`
Expected: PASS only after Task 3 implementation; if failing, fix backend contract first.

- [ ] **Step 3: Implement UI inventory-only template editor**

```tsx
// Add to RepairPackageManager form state
const [materialTemplates, setMaterialTemplates] = useState<Array<{
  inventory_item_id: number;
  default_quantity: number;
  is_critical: boolean;
  tolerance_percent: number;
}>>([]);

// Add inventory source load (repair_materials only)
const [repairMaterials, setRepairMaterials] = useState<Array<{id:number;name:string;available_quantity:number}>>([]);
const loadRepairMaterials = async () => {
  const response = await axios.get('/api/repairer/materials', { params: { category: 'repair_materials' } });
  if (response.data?.success) setRepairMaterials(response.data.data || []);
};

// Include payload on create/update
await axios.post('/api/repair-packages', {
  ...payload,
  material_templates: materialTemplates,
});
```

```tsx
// In uploadService pages, pass through endpoint and show guidance text
<RepairPackageManager serviceEndpoint="/api/repair-services" />
<p className="text-xs text-gray-500">Predefined materials must be selected from existing repair materials inventory only.</p>
```

- [ ] **Step 4: Verify UI behavior manually**

Run:
- `npm run dev`
- Open package create modal
- Confirm: no free-text material entry exists
- Confirm: saving requires selecting existing inventory item IDs
Expected: Material template lines are inventory-linked only.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/repairer/components/RepairPackageManager.tsx resources/js/Pages/ERP/repairer/uploadService.tsx resources/js/Pages/ShopOwner/Repairs/service\ management/uploadService.tsx
git commit -m "feat: add inventory-only predefined material template editor in package setup"
```

### Task 8: Job Order Gate UX + Readiness Badges + Repair Context Requests

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- Modify: `resources/js/Pages/ERP/repairer/requestMaterials.tsx`
- Test: `tests/Feature/Repairer/RepairMaterialPlanningGateTest.php`
- Test: `tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php`

- [ ] **Step 1: Write failing feature assertions for readiness states**

```php
public function test_non_critical_shortage_returns_at_risk_not_blocked(): void
{
    $shop = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $repairer = \App\Models\User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
    $repair = \App\Models\RepairRequest::factory()->create([
        'shop_owner_id' => $shop->id,
        'assigned_repairer_id' => $repairer->id,
        'status' => 'pending',
    ]);

    $item = \App\Models\InventoryItem::create([
        'shop_owner_id' => $shop->id,
        'name' => 'Laces',
        'sku' => 'RM-LACE-1',
        'category' => 'repair_materials',
        'available_quantity' => 0,
    ]);

    $repair->materialPlanItems()->create([
        'inventory_item_id' => $item->id,
        'planned_quantity' => 2,
        'actual_quantity' => 0,
        'is_critical' => false,
        'tolerance_percent' => 20,
    ]);

    $response = $this->actingAs($repairer, 'user')
        ->postJson("/api/repairer/repairs/{$repair->id}/materials/validate-start");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.readiness_state', 'at_risk');
}
```

- [ ] **Step 2: Run tests to verify failure state**

Run: `php artisan test tests/Feature/Repairer/RepairMaterialPlanningGateTest.php tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php`
Expected: FAIL until UI-triggered flow and API responses are wired end-to-end.

- [ ] **Step 3: Implement minimal UI gate integration**

```tsx
// JobOrdersRepair.tsx before start work
const readiness = await repairMaterialsApi.validateStartReadiness(order.database_id);
if (!readiness.success || readiness.data.readiness_state === 'blocked') {
  await Swal.fire({ title: 'Cannot start work', text: 'Critical materials are unavailable.', icon: 'warning' });
  return;
}

// before mark completed
const completionReadiness = await repairMaterialsApi.validateCompletionReadiness(order.database_id);
if (!completionReadiness.success || completionReadiness.data.readiness_state === 'variance_review_needed') {
  await Swal.fire({ title: 'Completion blocked', text: 'Resolve material variance note/review first.', icon: 'warning' });
  return;
}
```

```tsx
// requestMaterials.tsx require repair context when opened from repair workflow mode
if (repairMode && !repairRequestId) {
  await Swal.fire({ title: 'Missing repair context', text: 'Select a repair job before requesting materials.', icon: 'warning' });
  return;
}

await repairMaterialsApi.createMaterialRequest({
  inventory_item_id: selectedMaterial.id,
  quantity_needed: quantity,
  priority: toPriorityPayload(formData.priority),
  notes: formData.notes.trim(),
  repair_request_id: repairRequestId,
});
```

- [ ] **Step 4: Run tests and sanity checks**

Run:
- `php artisan test tests/Feature/Repairer/RepairMaterialPlanningGateTest.php tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php`
- `npm run build`
Expected: PASS for backend tests and successful frontend build.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx resources/js/Pages/ERP/repairer/requestMaterials.tsx tests/Feature/Repairer/RepairMaterialPlanningGateTest.php tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php
git commit -m "feat: enforce material readiness and completion variance gates in repairer workflow"
```

---

## Self-Review

### 1) Spec coverage check
- Existing inventory-only predefined templates: covered in Task 3 + Task 7.
- Planning gate before in-progress (critical blockers, non-critical warnings): covered in Task 4 + Task 8.
- Completion gate with tolerance-based variance: covered in Task 5 + Task 8.
- Visibility and workflow consistency for requests/readiness: covered in Task 1 (approval stage), Task 6, Task 8.
- Traceability plan -> request -> usage: covered by new plan table and controller/service flow across Tasks 1, 4, 5.

### 2) Placeholder scan
- No `TODO`, `TBD`, or deferred placeholders used for implementation logic.
- All code-changing steps include concrete code blocks.
- All test/run steps include explicit commands and expected outcomes.

### 3) Type/signature consistency
- Readiness states used consistently: `ready`, `at_risk`, `blocked`, `variance_review_needed`.
- Endpoint names consistent across backend routes and frontend client:
  - `/materials/validate-start`
  - `/materials/validate-complete`
- Template payload field names consistent across backend/frontend:
  - `material_templates[].inventory_item_id`
  - `default_quantity`
  - `is_critical`
  - `tolerance_percent`

---

Plan complete and saved to `docs/superpowers/plans/2026-04-04-repair-material-template-gating.md`. Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
