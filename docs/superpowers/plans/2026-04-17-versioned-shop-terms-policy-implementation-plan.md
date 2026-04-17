# Versioned Shop Terms Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a versioned, shop-scoped Terms and Conditions system with editable sections in Shop Settings and required acceptance capture for repair submissions and retail checkout payments.

**Architecture:** Add a dedicated policy version aggregate (`shop_policy_versions`) and immutable acceptance ledger (`policy_acceptances`), then integrate policy resolution and acceptance writes into existing repair and checkout flows. Frontend pages consume active policy context and use registration-style modal-gated checkbox UX. Premium checkout source is explicitly excluded from enforcement.

**Tech Stack:** Laravel 12, PHP 8+, MySQL migrations, Inertia React + TypeScript, SweetAlert2, PHPUnit Feature/Unit tests, Vitest.

---

## Scope Check

This work is a single cohesive subsystem (policy authoring, serving, and acceptance evidence) that spans Shop Owner settings and customer transaction flows. It should remain in one plan because schema, APIs, and UI behavior must stay consistent and version-aligned.

## File Structure

### New Files
- `database/migrations/2026_04_17_100000_create_shop_policy_versions_table.php`
  - Creates versioned policy storage per shop owner.
- `database/migrations/2026_04_17_101000_create_policy_acceptances_table.php`
  - Creates immutable acceptance ledger tied to transaction contexts.
- `database/migrations/2026_04_17_102000_add_policy_version_refs_to_orders_and_repair_requests.php`
  - Adds nullable foreign keys from orders/repairs to accepted policy version.
- `app/Models/ShopPolicyVersion.php`
  - Policy version aggregate model.
- `app/Models/PolicyAcceptance.php`
  - Acceptance ledger model.
- `app/Services/ShopPolicyTemplateService.php`
  - Builds default editable section templates by business/account context.
- `app/Services/ShopPolicyVersionService.php`
  - Draft, publish, active version, and version increment orchestration.
- `app/Services/PolicyAcceptanceService.php`
  - Acceptance write API for order/repair contexts.
- `app/Http/Controllers/Api/ShopPolicyController.php`
  - Customer-facing active policy + prefill endpoints.
- `tests/Feature/Policies/ShopPolicySettingsTest.php`
  - Shop settings draft/publish behavior.
- `tests/Feature/Policies/RepairPolicyAcceptanceTest.php`
  - Repair submission acceptance enforcement.
- `tests/Feature/Policies/CheckoutPolicyAcceptanceTest.php`
  - Checkout acceptance enforcement and premium bypass.
- `tests/Unit/Services/ShopPolicyTemplateServiceTest.php`
  - Business/account mapping and section selection.
- `resources/js/types/shopPolicy.ts`
  - Shared TS policy DTO types.
- `resources/js/utils/policySectionResolver.ts`
  - Customer-side section resolver helper.
- `resources/js/utils/termsPolicyModal.ts`
  - Shared registration-style modal helper.
- `resources/js/test/policySectionResolver.test.ts`
  - Frontend unit tests for section resolver.

### Modified Files
- `app/Models/Order.php`
  - Add policy version relation/fillable/casts.
- `app/Models/RepairRequest.php`
  - Add policy version relation/fillable/casts.
- `app/Http/Controllers/ShopOwner/ShopSettingsController.php`
  - Include policy payload in settings page, add draft/publish endpoints.
- `app/Http/Controllers/Api/RepairRequestController.php`
  - Validate and persist acceptance during repair request creation.
- `app/Http/Controllers/UserSide/CheckoutController.php`
  - Validate and persist acceptance in retail order creation path; bypass for premium source.
- `routes/web.php`
  - Add shop-owner policy management routes and customer active/prefill policy routes.
- `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx`
  - Terms editor UI, draft/publish controls, business/account scoped section preview.
- `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx`
  - Required terms checkbox with registration-like modal; payload inclusion.
- `resources/js/Pages/UserSide/Orders/payment.tsx`
  - Required terms checkbox for retail + repair payment sources only.
- `resources/css/app.css`
  - Reuse existing registration modal classes for terms content blocks if additional class hooks are needed.

---

### Task 1: Add Policy Version and Acceptance Schema

**Files:**
- Create: `database/migrations/2026_04_17_100000_create_shop_policy_versions_table.php`
- Create: `database/migrations/2026_04_17_101000_create_policy_acceptances_table.php`
- Create: `database/migrations/2026_04_17_102000_add_policy_version_refs_to_orders_and_repair_requests.php`
- Test: `tests/Feature/Policies/ShopPolicySettingsTest.php`

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Policies;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShopPolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_tables_and_foreign_keys_exist(): void
    {
        $this->assertTrue(Schema::hasTable('shop_policy_versions'));
        $this->assertTrue(Schema::hasTable('policy_acceptances'));
        $this->assertTrue(Schema::hasColumn('orders', 'accepted_shop_policy_version_id'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'accepted_shop_policy_version_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=policy_tables_and_foreign_keys_exist`
Expected: FAIL with missing table/column assertions.

- [ ] **Step 3: Write minimal migrations**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->string('business_type_scope', 20);
            $table->string('registration_clause_mode', 40)->default('individual_business_clause');
            $table->json('policy_sections_json');
            $table->char('content_hash', 64);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();

            $table->unique(['shop_owner_id', 'version_number']);
            $table->index(['shop_owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_policy_versions');
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
        Schema::create('policy_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('shop_policy_version_id')->constrained('shop_policy_versions')->cascadeOnDelete();
            $table->string('actor_guard', 20)->default('user');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('context_type', ['order', 'repair_request']);
            $table->unsignedBigInteger('context_id');
            $table->timestamp('accepted_at');
            $table->string('accepted_from_ip', 45)->nullable();
            $table->text('accepted_user_agent')->nullable();
            $table->char('accepted_snapshot_hash', 64);
            $table->timestamps();

            $table->index(['context_type', 'context_id']);
            $table->index(['shop_owner_id', 'actor_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acceptances');
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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('accepted_shop_policy_version_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('shop_policy_versions')
                ->nullOnDelete();
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->foreignId('accepted_shop_policy_version_id')
                ->nullable()
                ->after('payment_policy')
                ->constrained('shop_policy_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_shop_policy_version_id');
        });

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_shop_policy_version_id');
        });
    }
};
```

- [ ] **Step 4: Run migrations and test to verify pass**

Run: `php artisan migrate`
Expected: PASS and migration batch completes.

Run: `php artisan test --filter=policy_tables_and_foreign_keys_exist`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_17_100000_create_shop_policy_versions_table.php database/migrations/2026_04_17_101000_create_policy_acceptances_table.php database/migrations/2026_04_17_102000_add_policy_version_refs_to_orders_and_repair_requests.php tests/Feature/Policies/ShopPolicySettingsTest.php
git commit -m "feat(policy): add version and acceptance schema"
```

### Task 2: Add Policy Models and Template/Version Services

**Files:**
- Create: `app/Models/ShopPolicyVersion.php`
- Create: `app/Models/PolicyAcceptance.php`
- Create: `app/Services/ShopPolicyTemplateService.php`
- Create: `app/Services/ShopPolicyVersionService.php`
- Test: `tests/Unit/Services/ShopPolicyTemplateServiceTest.php`

- [ ] **Step 1: Write failing unit tests for section mapping**

```php
<?php

namespace Tests\Unit\Services;

use App\Services\ShopPolicyTemplateService;
use PHPUnit\Framework\TestCase;

class ShopPolicyTemplateServiceTest extends TestCase
{
    public function test_both_business_type_returns_all_sections(): void
    {
        $service = new ShopPolicyTemplateService();
        $result = $service->buildSections('both', 'individual');

        $this->assertArrayHasKey('refund_payment_terms', $result);
        $this->assertArrayHasKey('repair_service_terms', $result);
        $this->assertArrayHasKey('retail_terms', $result);
        $this->assertArrayHasKey('account_type_clause', $result);
    }

    public function test_repair_business_type_excludes_retail_terms(): void
    {
        $service = new ShopPolicyTemplateService();
        $result = $service->buildSections('repair', 'business');

        $this->assertArrayHasKey('refund_payment_terms', $result);
        $this->assertArrayHasKey('repair_service_terms', $result);
        $this->assertArrayNotHasKey('retail_terms', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShopPolicyTemplateServiceTest`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement models and services**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPolicyVersion extends Model
{
    protected $fillable = [
        'shop_owner_id',
        'version_number',
        'status',
        'business_type_scope',
        'registration_clause_mode',
        'policy_sections_json',
        'content_hash',
        'published_at',
        'published_by',
    ];

    protected $casts = [
        'policy_sections_json' => 'array',
        'published_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }
}
```

  ```php
  <?php

  namespace App\Models;

  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;

  class PolicyAcceptance extends Model
  {
    protected $fillable = [
      'shop_owner_id',
      'shop_policy_version_id',
      'actor_guard',
      'actor_user_id',
      'context_type',
      'context_id',
      'accepted_at',
      'accepted_from_ip',
      'accepted_user_agent',
      'accepted_snapshot_hash',
    ];

    protected $casts = [
      'accepted_at' => 'datetime',
    ];

    public function shopPolicyVersion(): BelongsTo
    {
      return $this->belongsTo(ShopPolicyVersion::class);
    }
  }
  ```

```php
<?php

namespace App\Services;

  use App\Models\ShopOwner;
  use App\Models\ShopPolicyVersion;

class ShopPolicyTemplateService
{
    public function buildSections(string $businessType, string $registrationType): array
    {
        $normalizedBusinessType = strtolower(trim($businessType));
        $normalizedRegistrationType = strtolower(trim($registrationType));

        $sections = [
            'refund_payment_terms' => "Refunds are subject to shop review and payment channel settlement rules.",
        ];

        if (in_array($normalizedBusinessType, ['repair', 'both'], true)) {
            $sections['repair_service_terms'] = "Repair timelines, workmanship scope, and return method obligations apply.";
        }

        if (in_array($normalizedBusinessType, ['retail', 'both'], true)) {
            $sections['retail_terms'] = "Retail orders are subject to shipping confirmation, stock verification, and refund window rules.";
        }

        $sections['account_type_clause'] = str_contains($normalizedRegistrationType, 'individual')
            ? "This agreement is accepted as an Individual Account holder."
            : "This agreement is accepted as a Business Account representative.";

        return $sections;
    }
}
```

```php
<?php

namespace App\Services;

use App\Models\ShopOwner;
use App\Models\ShopPolicyVersion;

class ShopPolicyVersionService
{
  public function saveDraft(int $shopOwnerId, array $sections): ShopPolicyVersion
  {
    $latest = ShopPolicyVersion::query()
      ->where('shop_owner_id', $shopOwnerId)
      ->latest('version_number')
      ->first();

    $nextVersion = (int) ($latest?->version_number ?? 0) + 1;

    return ShopPolicyVersion::query()->create([
      'shop_owner_id' => $shopOwnerId,
      'version_number' => $nextVersion,
      'status' => 'draft',
      'business_type_scope' => (string) (ShopOwner::query()->whereKey($shopOwnerId)->value('business_type') ?? ''),
      'registration_clause_mode' => 'individual_business_clause',
      'policy_sections_json' => $sections,
      'content_hash' => hash('sha256', json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    ]);
  }

  public function publishLatestDraft(int $shopOwnerId, ?int $publishedBy = null): ShopPolicyVersion
  {
    $draft = ShopPolicyVersion::query()
      ->where('shop_owner_id', $shopOwnerId)
      ->where('status', 'draft')
      ->latest('version_number')
      ->firstOrFail();

    $draft->update([
      'status' => 'published',
      'published_at' => now(),
      'published_by' => $publishedBy,
    ]);

    return $draft->fresh();
  }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `php artisan test --filter=ShopPolicyTemplateServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/ShopPolicyVersion.php app/Models/PolicyAcceptance.php app/Services/ShopPolicyTemplateService.php app/Services/ShopPolicyVersionService.php tests/Unit/Services/ShopPolicyTemplateServiceTest.php
git commit -m "feat(policy): add models and section template resolver"
```

### Task 3: Extend Shop Settings Backend for Draft and Publish

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/ShopSettingsController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Policies/ShopPolicySettingsTest.php`

- [ ] **Step 1: Add failing feature tests for draft and publish endpoints**

```php
public function test_shop_owner_can_save_policy_draft_and_publish_new_version(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
        'business_type' => 'both',
        'registration_type' => 'individual',
    ]);

    $this->actingAs($shopOwner, 'shop_owner')
        ->putJson('/shop-owner/settings/policies/draft', [
            'policy_sections_json' => [
                'refund_payment_terms' => 'Draft refund terms',
                'repair_service_terms' => 'Draft repair terms',
                'retail_terms' => 'Draft retail terms',
                'account_type_clause' => 'Individual clause',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($shopOwner, 'shop_owner')
        ->postJson('/shop-owner/settings/policies/publish')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('shop_policy_versions', [
        'shop_owner_id' => $shopOwner->id,
        'status' => 'published',
        'version_number' => 1,
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=shop_owner_can_save_policy_draft_and_publish_new_version`
Expected: FAIL with route not found.

- [ ] **Step 3: Implement endpoints and route wiring**

```php
// routes/web.php
Route::middleware('auth:shop_owner')->prefix('shop-owner')->group(function () {
    Route::put('/settings/policies/draft', [\App\Http\Controllers\ShopOwner\ShopSettingsController::class, 'savePolicyDraft']);
    Route::post('/settings/policies/publish', [\App\Http\Controllers\ShopOwner\ShopSettingsController::class, 'publishPolicy']);
    Route::get('/settings/policies', [\App\Http\Controllers\ShopOwner\ShopSettingsController::class, 'getPolicyEditorState']);
});
```

```php
// app/Http/Controllers/ShopOwner/ShopSettingsController.php
public function savePolicyDraft(Request $request): JsonResponse
{
    $shopOwner = Auth::guard('shop_owner')->user();

    $validated = $request->validate([
        'policy_sections_json' => ['required', 'array'],
        'policy_sections_json.refund_payment_terms' => ['required', 'string'],
        'policy_sections_json.account_type_clause' => ['required', 'string'],
    ]);

    $draft = app(\App\Services\ShopPolicyVersionService::class)
        ->saveDraft((int) $shopOwner->id, $validated['policy_sections_json']);

    return response()->json(['success' => true, 'data' => $draft]);
}

public function publishPolicy(): JsonResponse
{
    $shopOwner = Auth::guard('shop_owner')->user();

    $published = app(\App\Services\ShopPolicyVersionService::class)
        ->publishLatestDraft((int) $shopOwner->id, Auth::id());

    return response()->json(['success' => true, 'data' => $published]);
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `php artisan test --filter=ShopPolicySettingsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ShopOwner/ShopSettingsController.php routes/web.php tests/Feature/Policies/ShopPolicySettingsTest.php
git commit -m "feat(policy): add shop settings draft and publish endpoints"
```

### Task 4: Add Customer Policy Read and Prefill APIs

**Files:**
- Create: `app/Http/Controllers/Api/ShopPolicyController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Policies/PolicyReadApiTest.php`

- [ ] **Step 1: Write failing feature tests for active policy and prefill**

```php
public function test_customer_can_fetch_active_policy_for_shop(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create();
    \App\Models\ShopPolicyVersion::create([
        'shop_owner_id' => $shopOwner->id,
        'version_number' => 1,
        'status' => 'published',
        'business_type_scope' => 'both',
        'registration_clause_mode' => 'individual_business_clause',
        'policy_sections_json' => ['refund_payment_terms' => 'Live terms', 'account_type_clause' => 'Business clause'],
        'content_hash' => hash('sha256', 'Live terms'),
        'published_at' => now(),
    ]);

    $this->getJson("/api/policies/shops/{$shopOwner->id}/active")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.version_number', 1);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=customer_can_fetch_active_policy_for_shop`
Expected: FAIL with route/controller not found.

- [ ] **Step 3: Implement controller + routes**

```php
// routes/web.php
Route::get('/api/policies/shops/{shopOwnerId}/active', [\App\Http\Controllers\Api\ShopPolicyController::class, 'active']);
Route::middleware('auth:user')->get('/api/policies/shops/{shopOwnerId}/prefill', [\App\Http\Controllers\Api\ShopPolicyController::class, 'prefill']);
```

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyAcceptance;
use App\Models\ShopPolicyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopPolicyController extends Controller
{
    public function active(int $shopOwnerId): JsonResponse
    {
        $version = ShopPolicyVersion::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'published')
            ->latest('version_number')
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $version]);
    }

    public function prefill(Request $request, int $shopOwnerId): JsonResponse
    {
        $userId = (int) $request->user('user')->id;

        $version = ShopPolicyVersion::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'published')
            ->latest('version_number')
            ->firstOrFail();

        $accepted = PolicyAcceptance::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('shop_policy_version_id', $version->id)
            ->where('actor_user_id', $userId)
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'prefill_checked' => $accepted,
                'shop_policy_version_id' => $version->id,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `php artisan test --filter=PolicyReadApiTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/ShopPolicyController.php routes/web.php tests/Feature/Policies/PolicyReadApiTest.php
git commit -m "feat(policy): add active policy and prefill APIs"
```

### Task 5: Integrate Shop Settings Terms Editor UI

**Files:**
- Create: `resources/js/types/shopPolicy.ts`
- Modify: `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx`
- Create: `resources/js/utils/policySectionResolver.ts`
- Test: `resources/js/test/policySectionResolver.test.ts`

- [ ] **Step 1: Write failing frontend resolver test**

```ts
import { describe, expect, it } from 'vitest';
import { requiredPolicySectionKeys } from '../utils/policySectionResolver';

describe('requiredPolicySectionKeys', () => {
  it('returns all sections for both', () => {
    expect(requiredPolicySectionKeys('both')).toEqual([
      'refund_payment_terms',
      'repair_service_terms',
      'retail_terms',
      'account_type_clause',
    ]);
  });

  it('returns repair-only sections for repair shops', () => {
    expect(requiredPolicySectionKeys('repair')).toEqual([
      'refund_payment_terms',
      'repair_service_terms',
      'account_type_clause',
    ]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:frontend -- resources/js/test/policySectionResolver.test.ts`
Expected: FAIL with module not found.

- [ ] **Step 3: Implement resolver and wire editor state in shop settings**

```ts
// resources/js/utils/policySectionResolver.ts
export const requiredPolicySectionKeys = (businessType: string): string[] => {
  const normalized = String(businessType || '').toLowerCase().trim();
  const isBoth = normalized === 'both' || normalized.includes('both');
  const hasRepair = isBoth || normalized.includes('repair') || normalized.includes('service');
  const hasRetail = isBoth || normalized.includes('retail') || normalized.includes('shoe') || normalized.includes('product');

  const keys: string[] = ['refund_payment_terms'];
  if (hasRepair) keys.push('repair_service_terms');
  if (hasRetail) keys.push('retail_terms');
  keys.push('account_type_clause');

  return keys;
};
```

```tsx
// shopSetting.tsx excerpt
const [policySections, setPolicySections] = useState<Record<string, string>>({});
const [policyVersionNumber, setPolicyVersionNumber] = useState<number | null>(null);
const [savingPolicyDraft, setSavingPolicyDraft] = useState(false);
const [publishingPolicy, setPublishingPolicy] = useState(false);

const requiredSectionKeys = requiredPolicySectionKeys(shop_settings.business_type);

const savePolicyDraft = async () => {
  setSavingPolicyDraft(true);
  try {
    const response = await axios.put('/shop-owner/settings/policies/draft', {
      policy_sections_json: policySections,
    });
    setPolicyVersionNumber(response.data?.data?.version_number ?? policyVersionNumber);
  } finally {
    setSavingPolicyDraft(false);
  }
};
```

- [ ] **Step 4: Run frontend tests and build**

Run: `npm run test:frontend -- resources/js/test/policySectionResolver.test.ts`
Expected: PASS.

Run: `npm run build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/types/shopPolicy.ts resources/js/utils/policySectionResolver.ts resources/js/test/policySectionResolver.test.ts resources/js/Pages/ShopOwner/Settings/shopSetting.tsx
git commit -m "feat(policy-ui): add shop settings terms editor and section resolver"
```

### Task 6: Enforce and Record Acceptance in Repair Submission

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx`
- Create: `resources/js/utils/termsPolicyModal.ts`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Create: `app/Services/PolicyAcceptanceService.php`
- Test: `tests/Feature/Policies/RepairPolicyAcceptanceTest.php`

- [ ] **Step 1: Write failing repair acceptance feature tests**

```php
public function test_repair_request_requires_policy_acceptance_payload(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $customer = \App\Models\User::factory()->createOne();

    \App\Models\ShopPolicyVersion::create([
        'shop_owner_id' => $shopOwner->id,
        'version_number' => 1,
        'status' => 'published',
        'business_type_scope' => 'repair',
        'registration_clause_mode' => 'individual_business_clause',
        'policy_sections_json' => ['refund_payment_terms' => 'x', 'repair_service_terms' => 'y', 'account_type_clause' => 'z'],
        'content_hash' => hash('sha256', 'repair-v1'),
        'published_at' => now(),
    ]);

    $response = $this->actingAs($customer, 'user')->post('/api/repair-requests', [
        'customer_name' => 'Policy Test',
        'email' => $customer->email,
        'phone' => '09171234567',
        'shoe_type' => 'Sneakers',
        'shop_owner_id' => $shopOwner->id,
        'services' => [],
        'images' => [\Illuminate\Http\UploadedFile::fake()->image('repair.jpg')],
        'total' => 100,
        'service_type' => 'walkin',
        'return_delivery_method' => 'walk_in',
    ]);

    $response->assertStatus(422);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=repair_request_requires_policy_acceptance_payload`
Expected: FAIL because endpoint currently accepts payload without policy fields.

- [ ] **Step 3: Implement controller validation, acceptance service write, and modal-gated checkbox UI**

```php
// RepairRequestController validation excerpt
'accepted_shop_policy_version_id' => 'required|integer|exists:shop_policy_versions,id',
'policy_accepted' => 'required|boolean|accepted',
```

```php
// RepairRequestController after repair create
app(\App\Services\PolicyAcceptanceService::class)->record([
    'shop_owner_id' => (int) $shopOwner->id,
    'shop_policy_version_id' => (int) $request->accepted_shop_policy_version_id,
    'actor_user_id' => (int) $userId,
    'context_type' => 'repair_request',
    'context_id' => (int) $repairRequest->id,
    'accepted_at' => now(),
    'accepted_from_ip' => $request->ip(),
    'accepted_user_agent' => (string) $request->userAgent(),
]);

$repairRequest->update([
    'accepted_shop_policy_version_id' => (int) $request->accepted_shop_policy_version_id,
]);
```

```ts
// termsPolicyModal.ts
import Swal from 'sweetalert2';

export const openTermsPolicyModal = async (title: string, htmlContent: string) => {
  return Swal.fire({
    title,
    html: htmlContent,
    showCancelButton: true,
    confirmButtonText: 'Accept',
    cancelButtonText: 'Decline',
    allowOutsideClick: false,
    didOpen: () => {
      const confirmButton = Swal.getConfirmButton();
      const scrollBox = document.querySelector('.terms-modal__scroll') as HTMLElement | null;
      if (!confirmButton || !scrollBox) return;
      confirmButton.disabled = true;
      const unlock = () => {
        const threshold = 8;
        const reachedBottom = scrollBox.scrollTop + scrollBox.clientHeight >= scrollBox.scrollHeight - threshold;
        confirmButton.disabled = !reachedBottom;
      };
      scrollBox.addEventListener('scroll', unlock, { passive: true });
      unlock();
    },
    customClass: {
      popup: 'user-terms-modal-popup',
      title: 'user-terms-modal-title',
      htmlContainer: 'user-terms-modal-content',
      actions: 'user-terms-modal-actions',
      confirmButton: 'user-terms-modal-accept',
      cancelButton: 'user-terms-modal-decline',
    },
  });
};
```

  ```php
  <?php

  namespace App\Services;

  use App\Models\PolicyAcceptance;
  use App\Models\ShopPolicyVersion;

  class PolicyAcceptanceService
  {
    public function record(array $attributes): PolicyAcceptance
    {
      $policyVersion = ShopPolicyVersion::query()->findOrFail((int) $attributes['shop_policy_version_id']);

      return PolicyAcceptance::query()->create([
        'shop_owner_id' => (int) $attributes['shop_owner_id'],
        'shop_policy_version_id' => (int) $policyVersion->id,
        'actor_guard' => (string) ($attributes['actor_guard'] ?? 'user'),
        'actor_user_id' => isset($attributes['actor_user_id']) ? (int) $attributes['actor_user_id'] : null,
        'context_type' => (string) $attributes['context_type'],
        'context_id' => (int) $attributes['context_id'],
        'accepted_at' => $attributes['accepted_at'] ?? now(),
        'accepted_from_ip' => $attributes['accepted_from_ip'] ?? null,
        'accepted_user_agent' => $attributes['accepted_user_agent'] ?? null,
        'accepted_snapshot_hash' => (string) $policyVersion->content_hash,
      ]);
    }
  }
  ```

- [ ] **Step 4: Run repair acceptance tests**

Run: `php artisan test --filter=RepairPolicyAcceptanceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PolicyAcceptanceService.php app/Http/Controllers/Api/RepairRequestController.php resources/js/utils/termsPolicyModal.ts resources/js/Pages/UserSide/Repairs/RepairProcess.tsx tests/Feature/Policies/RepairPolicyAcceptanceTest.php
git commit -m "feat(repair-policy): enforce and record terms acceptance"
```

### Task 7: Enforce and Record Acceptance in Payment Flow (Retail + Repair, Premium Bypass)

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Modify: `app/Http/Controllers/UserSide/CheckoutController.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Test: `tests/Feature/Policies/CheckoutPolicyAcceptanceTest.php`

- [ ] **Step 1: Write failing checkout acceptance tests (including premium bypass)**

```php
public function test_checkout_create_order_requires_policy_acceptance_for_retail_source(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create([
        'business_type' => 'retail',
        'paymongo_secret_key' => 'sk_test_policy_checkout',
    ]);

    $customer = \App\Models\User::factory()->createOne();
    $product = \App\Models\Product::create([
        'shop_owner_id' => $shopOwner->id,
        'name' => 'Policy Shoe',
        'slug' => 'policy-shoe-' . random_int(1000, 9999),
        'description' => 'Policy enforcement product',
        'price' => 1500,
        'stock_quantity' => 5,
        'is_active' => true,
        'is_featured' => false,
    ]);

    $response = $this->actingAs($customer, 'user')->postJson('/api/checkout/create-order', [
        'items' => [[
            'id' => 'row-1',
            'pid' => $product->id,
            'qty' => 1,
            'name' => $product->name,
            'price' => 1500,
        ]],
        'total_amount' => 1500,
        'shipping_fee' => 0,
        'customer_name' => 'Policy Customer',
        'customer_email' => $customer->email,
        'shipping_address' => 'Address',
        'payment_method' => 'paymongo',
    ]);

    $response->assertStatus(422);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=checkout_create_order_requires_policy_acceptance_for_retail_source`
Expected: FAIL because controller currently accepts request without policy acceptance fields.

- [ ] **Step 3: Implement payment UI and backend enforcement**

```php
// CheckoutController validate excerpt
'accepted_shop_policy_version_id' => 'required|integer|exists:shop_policy_versions,id',
'policy_accepted' => 'required|boolean|accepted',
'checkout_source' => 'nullable|string|in:retail,repair,premium',
```

```php
// CheckoutController after Order::create
app(\App\Services\PolicyAcceptanceService::class)->record([
    'shop_owner_id' => (int) $shopOwnerId,
    'shop_policy_version_id' => (int) $validated['accepted_shop_policy_version_id'],
    'actor_user_id' => (int) $customerId,
    'context_type' => 'order',
    'context_id' => (int) $order->id,
    'accepted_at' => now(),
    'accepted_from_ip' => $request->ip(),
    'accepted_user_agent' => (string) $request->userAgent(),
]);

$order->update([
    'accepted_shop_policy_version_id' => (int) $validated['accepted_shop_policy_version_id'],
]);
```

```tsx
// payment.tsx excerpt
const [termsAccepted, setTermsAccepted] = useState(false);
const [acceptedPolicyVersionId, setAcceptedPolicyVersionId] = useState<number | null>(null);

const requiresTerms = !isPremiumPayment && (isRepairPayment || !isRepairPayment);

if (requiresTerms && !termsAccepted) {
  setPayError('Please accept the terms and conditions before continuing.');
  return;
}

const orderData = {
  ...existingOrderData,
  checkout_source: isPremiumPayment ? 'premium' : (isRepairPayment ? 'repair' : 'retail'),
  accepted_shop_policy_version_id: acceptedPolicyVersionId,
  policy_accepted: termsAccepted,
};
```

- [ ] **Step 4: Run checkout tests**

Run: `php artisan test --filter=CheckoutPolicyAcceptanceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/UserSide/CheckoutController.php app/Http/Controllers/Api/RepairRequestController.php resources/js/Pages/UserSide/Orders/payment.tsx tests/Feature/Policies/CheckoutPolicyAcceptanceTest.php
git commit -m "feat(checkout-policy): enforce terms for retail and repair, bypass premium"
```

### Task 8: Add Prefill Behavior and Acceptance Linking in Frontend Flows

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Test: `tests/Feature/Policies/PolicyReadApiTest.php`

- [ ] **Step 1: Write failing feature test for prefill endpoint response shape**

```php
public function test_prefill_endpoint_returns_checked_when_user_already_accepted_same_version(): void
{
    $shopOwner = \App\Models\ShopOwner::factory()->approved()->create();
    $user = \App\Models\User::factory()->createOne();

    $version = \App\Models\ShopPolicyVersion::create([
        'shop_owner_id' => $shopOwner->id,
        'version_number' => 1,
        'status' => 'published',
        'business_type_scope' => 'both',
        'registration_clause_mode' => 'individual_business_clause',
        'policy_sections_json' => ['refund_payment_terms' => 'v1', 'account_type_clause' => 'v1'],
        'content_hash' => hash('sha256', 'v1'),
        'published_at' => now(),
    ]);

    \App\Models\PolicyAcceptance::create([
        'shop_owner_id' => $shopOwner->id,
        'shop_policy_version_id' => $version->id,
        'actor_guard' => 'user',
        'actor_user_id' => $user->id,
        'context_type' => 'order',
        'context_id' => 1,
        'accepted_at' => now(),
        'accepted_snapshot_hash' => $version->content_hash,
    ]);

    $this->actingAs($user, 'user')
        ->getJson("/api/policies/shops/{$shopOwner->id}/prefill")
        ->assertOk()
        ->assertJsonPath('data.prefill_checked', true);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=prefill_endpoint_returns_checked_when_user_already_accepted_same_version`
Expected: FAIL before prefill logic is fully implemented.

- [ ] **Step 3: Implement prefill consumption in both pages**

```tsx
// RepairProcess.tsx excerpt
useEffect(() => {
  if (!shopId) return;

  axios.get(`/api/policies/shops/${shopId}/prefill`)
    .then((response) => {
      setTermsAccepted(Boolean(response.data?.data?.prefill_checked));
      setAcceptedPolicyVersionId(Number(response.data?.data?.shop_policy_version_id || 0) || null);
    })
    .catch(() => {
      setTermsAccepted(false);
      setAcceptedPolicyVersionId(null);
    });
}, [shopId]);
```

```tsx
// payment.tsx excerpt
useEffect(() => {
  const shopOwnerId = Number(promoPreview?.shop_owner_id || 0);
  if (!shopOwnerId || isPremiumPayment) return;

  axios.get(`/api/policies/shops/${shopOwnerId}/prefill`)
    .then((response) => {
      setTermsAccepted(Boolean(response.data?.data?.prefill_checked));
      setAcceptedPolicyVersionId(Number(response.data?.data?.shop_policy_version_id || 0) || null);
    });
}, [promoPreview?.shop_owner_id, isPremiumPayment]);
```

- [ ] **Step 4: Run tests and frontend build**

Run: `php artisan test --filter=PolicyReadApiTest`
Expected: PASS.

Run: `npm run build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/UserSide/Repairs/RepairProcess.tsx resources/js/Pages/UserSide/Orders/payment.tsx tests/Feature/Policies/PolicyReadApiTest.php
git commit -m "feat(policy-prefill): prefill checkbox for same shop and active version"
```

### Task 9: Wire Relations on Order and RepairRequest Models

**Files:**
- Modify: `app/Models/Order.php`
- Modify: `app/Models/RepairRequest.php`
- Test: `tests/Unit/Models/PolicyVersionRelationsTest.php`

- [ ] **Step 1: Write failing relation test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use App\Models\RepairRequest;
use PHPUnit\Framework\TestCase;

class PolicyVersionRelationsTest extends TestCase
{
    public function test_order_exposes_policy_version_relation_method(): void
    {
        $this->assertTrue(method_exists(new Order(), 'acceptedShopPolicyVersion'));
    }

    public function test_repair_request_exposes_policy_version_relation_method(): void
    {
        $this->assertTrue(method_exists(new RepairRequest(), 'acceptedShopPolicyVersion'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PolicyVersionRelationsTest`
Expected: FAIL because relation methods do not exist.

- [ ] **Step 3: Implement model changes**

```php
// app/Models/Order.php additions
protected $fillable = [
    // ...existing fields
    'accepted_shop_policy_version_id',
];

public function acceptedShopPolicyVersion(): BelongsTo
{
    return $this->belongsTo(\App\Models\ShopPolicyVersion::class, 'accepted_shop_policy_version_id');
}
```

```php
// app/Models/RepairRequest.php additions
protected $fillable = [
    // ...existing fields
    'accepted_shop_policy_version_id',
];

public function acceptedShopPolicyVersion()
{
    return $this->belongsTo(\App\Models\ShopPolicyVersion::class, 'accepted_shop_policy_version_id');
}
```

- [ ] **Step 4: Run model tests**

Run: `php artisan test --filter=PolicyVersionRelationsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Order.php app/Models/RepairRequest.php tests/Unit/Models/PolicyVersionRelationsTest.php
git commit -m "refactor(models): add accepted policy version relations"
```

### Task 10: Full Regression and Documentation

**Files:**
- Modify: `docs/P4-ROLLOUT-CHECKLIST.md`
- Modify: `docs/P4-STATUS.md`

- [ ] **Step 1: Add rollout checklist items for policy versioning**

```md
## Policy Versioned Terms Rollout
- [ ] Existing shops have initial published policy version
- [ ] Repair submission rejects missing policy acceptance
- [ ] Retail checkout rejects missing policy acceptance
- [ ] Premium checkout bypass verified
- [ ] Acceptance records visible in DB with context links
```

- [ ] **Step 2: Run backend targeted tests**

Run: `php artisan test --filter=ShopPolicySettingsTest`
Expected: PASS.

Run: `php artisan test --filter=RepairPolicyAcceptanceTest`
Expected: PASS.

Run: `php artisan test --filter=CheckoutPolicyAcceptanceTest`
Expected: PASS.

- [ ] **Step 3: Run frontend checks**

Run: `npm run test:frontend -- resources/js/test/policySectionResolver.test.ts`
Expected: PASS.

Run: `npm run build`
Expected: PASS.

- [ ] **Step 4: Commit docs and verification updates**

```bash
git add docs/P4-ROLLOUT-CHECKLIST.md docs/P4-STATUS.md
git commit -m "docs(policy): add rollout and verification checklist"
```

- [ ] **Step 5: Tag completion in changelog or release notes commit**

```bash
git add .
git commit -m "chore(policy): finalize versioned terms acceptance rollout"
```

---

## Self-Review

### 1. Spec Coverage
- Shop settings editable preset terms with publish versioning: Covered by Tasks 3 and 5.
- Business type aware section selection (retail, repair, both): Covered by Tasks 2 and 5.
- Registration type clause (individual/business): Covered by Tasks 2 and 5.
- Repair process required checkbox with registration-like modal UX: Covered by Task 6.
- Payment page required checkbox for retail + repair, premium bypass: Covered by Task 7.
- Persist acceptance with version and timestamp for order and repair: Covered by Tasks 1, 6, and 7.
- Prefill when same user accepted same shop/version before: Covered by Tasks 4 and 8.
- Immediate effect of publish for new transactions: Covered by Tasks 3 and 4.

### 2. Placeholder Scan
- No TODO/TBD placeholders left.
- Every task includes explicit files, commands, and concrete snippets.

### 3. Type Consistency
- Consistent key names: `accepted_shop_policy_version_id`, `policy_accepted`, `policy_sections_json`.
- Context types consistently `order` and `repair_request`.
- Resolver and template naming aligned with frontend and backend usage.
