# Customer Repair Service Modification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a customer add, remove, or replace services from My Repairs after repairer acceptance and chat, but only before customer confirmation or any payment.

**Architecture:** Add one authenticated `PATCH` endpoint to the existing `RepairRequestController`; it owns authorization, lifecycle checks, authoritative repricing, package-to-individual conversion, payment-session invalidation, and the chat audit message in one database transaction. Extend the existing My Repairs payload and page with an inline modal that reuses the public active-service listing for the repair's shop. No migration, new dependency, or new page is needed.

**Tech Stack:** Laravel 12/PHP 8, Eloquent, PHPUnit feature tests, React 18/TypeScript, Axios, Tailwind CSS, Vitest.

## Global Constraints

- Show Modify only when `status === 'repairer_accepted'`, a conversation exists, and no payment has been recorded.
- Keep at least one active service from the repair's current shop.
- A package remains intact until the customer explicitly chooses Remove Package; saving individual selections then sets `repair_package_id` to `null`.
- The server, never the browser, calculates `total`, `final_total`, snapshots, and `pricing_breakdown` from current service prices.
- Keep the request in `repairer_accepted` after a successful change.
- Clear any unpaid PayMongo checkout-session reference so the next payment uses the new amount.
- Add one automatic conversation message listing added services, removed services, and the new total.
- Do not add a migration, dependency, service class, repository, or dedicated edit page.
- Preserve all unrelated working-tree changes, especially generated `public/build` files and current payment/location work.

---

### Task 1: Secure service-replacement API

**Files:**
- Create: `tests/Feature/Customer/RepairServiceModificationTest.php`
- Modify: `routes/web.php:1258-1273`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php:5-31, 882-948, 1768-1833`

**Interfaces:**
- Consumes: authenticated `user` guard; `RepairRequest::forCustomer()`; `RepairRequest::services()`; `GET /api/repair-services?shop_id={id}` active-service convention (`Active` or `active`).
- Produces: `PATCH /api/customer/repairs/{id}/services` with JSON `{ service_ids: number[], remove_package: boolean }`; My Repairs rows gain `services: RepairServiceSummary[]`.

- [ ] **Step 1: Write the failing feature tests**

Create `tests/Feature/Customer/RepairServiceModificationTest.php`:

```php
<?php

namespace Tests\Feature\Customer;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\RepairPackage;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairServiceModificationTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;
    private User $customer;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);
        $this->customer = User::factory()->create(['status' => 'active']);
        $this->conversation = Conversation::create([
            'shop_owner_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'status' => 'open',
            'priority' => 'medium',
            'last_message_at' => now(),
        ]);
    }

    private function service(string $name, float $price, ?ShopOwner $shop = null, string $status = 'Active'): RepairService
    {
        return RepairService::create([
            'shop_owner_id' => ($shop ?? $this->shop)->id,
            'name' => $name,
            'category' => 'Cleaning',
            'price' => $price,
            'duration' => '1 day',
            'description' => $name,
            'status' => $status,
        ]);
    }

    private function acceptedRepair(array $serviceIds, array $overrides = []): RepairRequest
    {
        $repair = RepairRequest::factory()->create(array_merge([
            'shop_owner_id' => $this->shop->id,
            'user_id' => $this->customer->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'repairer_accepted',
            'payment_status' => 'unpaid',
            'total_paid_amount' => 0,
            'total' => 500,
            'final_total' => 500,
            'pricing_breakdown' => ['mode' => 'services', 'tax_mode' => 'vat_inclusive'],
        ], $overrides));
        $repair->services()->sync($serviceIds);

        return $repair;
    }

    public function test_customer_replaces_services_and_server_reprices_request(): void
    {
        $old = $this->service('Deep Clean', 500);
        $replacement = $this->service('Sole Reglue', 750);
        $repair = $this->acceptedRepair([$old->id], [
            'paymongo_link_id' => 'cs_stale',
            'payment_link_created_at' => now(),
            'payment_expires_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($this->customer, 'user')->patchJson(
            "/api/customer/repairs/{$repair->id}/services",
            ['service_ids' => [$replacement->id], 'remove_package' => false],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'repairer_accepted')
            ->assertJsonPath('data.total', '750.00')
            ->assertJsonPath('data.services.0.id', $replacement->id);

        $repair->refresh();
        $this->assertSame([$replacement->id], $repair->services()->pluck('repair_services.id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('750.00', $repair->total);
        $this->assertSame('750.00', $repair->final_total);
        $this->assertNull($repair->paymongo_link_id);
        $this->assertSame('services', $repair->pricing_breakdown['mode']);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'system',
        ]);
        $message = ConversationMessage::where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('Added: Sole Reglue', $message->content);
        $this->assertStringContainsString('Removed: Deep Clean', $message->content);
        $this->assertStringContainsString('New total: PHP 750.00', $message->content);
    }

    public function test_customer_can_remove_package_and_convert_to_individual_services(): void
    {
        $included = $this->service('Package Clean', 500);
        $replacement = $this->service('Custom Repair', 900);
        $package = RepairPackage::create([
            'shop_owner_id' => $this->shop->id,
            'name' => 'Cleaning Package',
            'package_price' => 450,
            'status' => 'active',
        ]);
        $package->services()->sync([$included->id]);
        $repair = $this->acceptedRepair([$included->id], [
            'repair_package_id' => $package->id,
            'package_price' => 450,
        ]);

        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", [
                'service_ids' => [$replacement->id],
                'remove_package' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.repair_package_id', null);

        $repair->refresh();
        $this->assertNull($repair->repair_package_id);
        $this->assertNull($repair->package_price);
        $this->assertSame('0.00', $repair->add_ons_total);
        $this->assertSame(
            [$replacement->id],
            collect($repair->included_services_snapshot)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_package_cannot_be_changed_without_explicit_removal(): void
    {
        $service = $this->service('Package Clean', 500);
        $package = RepairPackage::create([
            'shop_owner_id' => $this->shop->id,
            'name' => 'Cleaning Package',
            'package_price' => 450,
            'status' => 'active',
        ]);
        $repair = $this->acceptedRepair([$service->id], ['repair_package_id' => $package->id]);

        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", [
                'service_ids' => [$service->id],
                'remove_package' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('remove_package');
    }

    public function test_modification_rejects_wrong_shop_inactive_wrong_status_and_paid_requests(): void
    {
        $current = $this->service('Deep Clean', 500);
        $otherShop = ShopOwner::factory()->approved()->create();
        $wrongShop = $this->service('Foreign Service', 100, $otherShop);
        $inactive = $this->service('Inactive Service', 100, null, 'Inactive');
        $repair = $this->acceptedRepair([$current->id]);

        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", ['service_ids' => [$wrongShop->id]])
            ->assertStatus(422);
        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", ['service_ids' => [$inactive->id]])
            ->assertStatus(422);

        $repair->update(['status' => 'pending']);
        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", ['service_ids' => [$current->id]])
            ->assertStatus(409);

        $repair->update(['status' => 'repairer_accepted', 'payment_status' => 'paid', 'total_paid_amount' => 500]);
        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", ['service_ids' => [$current->id]])
            ->assertStatus(409);
    }
}
```

- [ ] **Step 2: Run the focused test and verify the missing route failure**

Run:

```bash
php artisan test tests/Feature/Customer/RepairServiceModificationTest.php
```

Expected: FAIL because `PATCH /api/customer/repairs/{id}/services` does not exist.

- [ ] **Step 3: Register the authenticated route**

In the existing `Route::middleware('auth:user')->prefix('api/customer/repairs')` group in `routes/web.php`, add immediately after the single-repair GET route:

```php
Route::patch('{id}/services', [\App\Http\Controllers\Api\RepairRequestController::class, 'updateServices']);
```

- [ ] **Step 4: Import the chat models and expose service summaries in My Repairs**

Add these imports to `RepairRequestController.php`:

```php
use App\Models\Conversation;
use App\Models\ConversationMessage;
```

In the array returned by `myRepairs()`, immediately after `repair_type`, add:

```php
'services' => $repair->services->map(fn (RepairService $service) => [
    'id' => (int) $service->id,
    'name' => $service->name,
    'category' => $service->category,
    'price' => (float) $service->price,
    'duration' => $service->duration,
])->values(),
```

- [ ] **Step 5: Implement the transactional update endpoint**

Add this method next to `confirmRepair()` in `RepairRequestController.php`:

```php
public function updateServices(Request $request, int $id)
{
    $user = Auth::guard('user')->user();
    if (!$user) {
        return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
    }

    $validated = $request->validate([
        'service_ids' => ['required', 'array', 'min:1'],
        'service_ids.*' => ['integer', 'distinct', 'exists:repair_services,id'],
        'remove_package' => ['sometimes', 'boolean'],
    ]);

    try {
        $repair = DB::transaction(function () use ($validated, $id, $user) {
            $repair = RepairRequest::query()
                ->with('services:id,name')
                ->whereKey($id)
                ->forCustomer($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($repair->status !== 'repairer_accepted' || !$repair->conversation_id) {
                abort(409, 'Services can only be modified after repairer acceptance and before confirmation.');
            }

            $paidStatuses = ['paid', 'completed', 'down_payment_paid', 'partially_paid', 'partially_refunded', 'refunded'];
            if ((float) $repair->total_paid_amount > 0
                || $repair->payment_completed_at
                || in_array(strtolower((string) $repair->payment_status), $paidStatuses, true)
                || $repair->posTransactions()->whereIn('status', ['paid', 'partially_refunded', 'refunded'])->exists()) {
                abort(409, 'Paid repairs can no longer be modified.');
            }

            if ($repair->repair_package_id && !($validated['remove_package'] ?? false)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'remove_package' => ['Remove the package before choosing individual services.'],
                ]);
            }

            $requestedIds = collect($validated['service_ids'])->map(fn ($value) => (int) $value)->unique()->values();
            $services = RepairService::query()
                ->whereIn('id', $requestedIds)
                ->where('shop_owner_id', $repair->shop_owner_id)
                ->whereIn('status', ['Active', 'active'])
                ->get(['id', 'name', 'category', 'price', 'duration']);

            if ($services->count() !== $requestedIds->count()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'service_ids' => ['Every selected service must be active and belong to this repair shop.'],
                ]);
            }

            $oldNames = $repair->services->pluck('name');
            $newNames = $services->pluck('name');
            $total = round((float) $services->sum(fn (RepairService $service) => (float) $service->price), 2);
            $shopOwner = ShopOwner::find($repair->shop_owner_id);
            $requiresOwnerApprovalByPolicy = $shopOwner
                ? $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForRepairReject((int) $shopOwner->id, $total)
                : false;
            $isHighValue = (bool) ($shopOwner && ($total >= (float) $shopOwner->high_value_threshold || $requiresOwnerApprovalByPolicy));
            $snapshot = $services->map(fn (RepairService $service) => [
                'id' => (int) $service->id,
                'name' => $service->name,
                'category' => $service->category,
                'price' => (float) $service->price,
                'duration' => $service->duration,
            ])->values()->all();

            $repair->services()->sync($services->pluck('id')->all());
            $repair->update([
                'repair_package_id' => null,
                'package_price' => null,
                'add_ons_total' => 0,
                'total' => $total,
                'final_total' => $total,
                'included_services_snapshot' => $snapshot,
                'add_on_services_snapshot' => null,
                'is_high_value' => $isHighValue,
                'requires_owner_approval' => (bool) ($shopOwner
                    && $shopOwner->require_two_way_approval
                    && $requiresOwnerApprovalByPolicy),
                'pricing_breakdown' => [
                    'mode' => 'services',
                    'package_id' => null,
                    'package_name' => null,
                    'included_services_total' => $total,
                    'package_price' => null,
                    'add_ons_total' => 0,
                    'base_total' => $total,
                    'materials_total' => 0,
                    'final_total' => $total,
                    'add_on_count' => 0,
                    'tax_mode' => 'vat_inclusive',
                ],
                'paymongo_link_id' => null,
                'payment_link_created_at' => null,
                'payment_expires_at' => null,
                'payment_failed_at' => null,
                'payment_failure_reason' => null,
                'payment_expired_at' => null,
            ]);

            $added = $newNames->diff($oldNames)->values()->join(', ') ?: 'None';
            $removed = $oldNames->diff($newNames)->values()->join(', ') ?: 'None';
            $message = ConversationMessage::create([
                'conversation_id' => $repair->conversation_id,
                'sender_type' => 'system',
                'sender_id' => $user->id,
                'content' => "Services updated by customer.\n\nAdded: {$added}\nRemoved: {$removed}\nNew total: PHP " . number_format($total, 2),
            ]);
            Conversation::whereKey($repair->conversation_id)->update(['last_message_at' => $message->created_at]);

            return $repair->fresh('services:id,name,category,price,duration');
        });

        return response()->json([
            'success' => true,
            'message' => 'Repair services updated.',
            'data' => $repair,
        ]);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
        ], $exception->getStatusCode());
    }
}
```

- [ ] **Step 6: Run the focused backend test**

Run:

```bash
php artisan test tests/Feature/Customer/RepairServiceModificationTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 7: Run adjacent repair workflow tests**

Run:

```bash
php artisan test tests/Feature/Repairer/RepairerWorkflowTest.php tests/Feature/RepairPosPaymentFlowTest.php
```

Expected: PASS with no regression in acceptance, conversation creation, or repair payment calculation.

- [ ] **Step 8: Commit the backend slice**

```bash
git add routes/web.php app/Http/Controllers/Api/RepairRequestController.php tests/Feature/Customer/RepairServiceModificationTest.php
git commit -m "feat: allow customers to modify accepted repair services"
```

---

### Task 2: My Repairs Modify modal

**Files:**
- Create: `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.service-modification.test.ts`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx:70-176, 575-633, 1298-1312, 3678-3718, before the existing cancel modal`

**Interfaces:**
- Consumes: `RepairOrder.services` from Task 1; `GET /api/repair-services?shop_id={shopOwnerId}`; `PATCH /api/customer/repairs/{repairId}/services`.
- Produces: `MODIFY` action and accessible `Modify Repair Services` dialog; successful saves call existing `fetchRepairs()`.

- [ ] **Step 1: Write the failing source-integration test**

Create `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.service-modification.test.ts`:

```ts
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Repairs/myRepairs.tsx'),
  'utf8',
);

describe('My Repairs service modification integration', () => {
  it('offers modification only for accepted unpaid repairs and calls the update endpoint', () => {
    expect(source).toContain("order.status === 'repairer_accepted'");
    expect(source).toContain('order.conversation_id');
    expect(source).toContain("['paid', 'completed'].includes");
    expect(source).toContain('MODIFY');
    expect(source).toContain('Modify Repair Services');
    expect(source).toContain('/api/repair-services?shop_id=');
    expect(source).toContain("method: 'PATCH'");
    expect(source).toContain('/services`');
    expect(source).toContain('remove_package: Boolean(modifyOrder.repair_package_id)');
    expect(source).toContain('selectedModifyServiceIds.length === 0');
  });
});
```

- [ ] **Step 2: Run the focused frontend test and verify it fails**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.service-modification.test.ts
```

Expected: FAIL because the page has no Modify UI or PATCH call.

- [ ] **Step 3: Add the response types and modal state**

Add this type above `RepairOrder`, and add `services: RepairServiceSummary[]` to `RepairOrder`:

```ts
type RepairServiceSummary = {
  id: number;
  name: string;
  category?: string;
  price: number | string;
  duration?: string;
};
```

Add these states beside the existing modal states in `MyRepairs`:

```ts
const [modifyOrder, setModifyOrder] = useState<RepairOrder | null>(null);
const [availableModifyServices, setAvailableModifyServices] = useState<RepairServiceSummary[]>([]);
const [selectedModifyServiceIds, setSelectedModifyServiceIds] = useState<number[]>([]);
const [packageRemovalConfirmed, setPackageRemovalConfirmed] = useState(false);
const [isLoadingModifyServices, setIsLoadingModifyServices] = useState(false);
const [isSavingModifyServices, setIsSavingModifyServices] = useState(false);
```

- [ ] **Step 4: Add open, toggle, close, and save handlers**

Place these handlers near the other action handlers:

```ts
const closeModifyModal = () => {
  if (isSavingModifyServices) return;
  setModifyOrder(null);
  setAvailableModifyServices([]);
  setSelectedModifyServiceIds([]);
  setPackageRemovalConfirmed(false);
};

const openModifyModal = async (order: RepairOrder) => {
  setModifyOrder(order);
  setSelectedModifyServiceIds(order.services.map((service) => service.id));
  setPackageRemovalConfirmed(!order.repair_package_id);
  setIsLoadingModifyServices(true);

  try {
    const response = await axios.get(`/api/repair-services?shop_id=${order.shop_owner_id}`);
    setAvailableModifyServices(Array.isArray(response.data?.data) ? response.data.data : []);
  } catch {
    closeModifyModal();
    Swal.fire({ icon: 'error', title: 'Unable to Load Services', text: 'Please try again.', confirmButtonColor: '#000000' });
  } finally {
    setIsLoadingModifyServices(false);
  }
};

const toggleModifyService = (serviceId: number) => {
  if (!packageRemovalConfirmed) return;
  setSelectedModifyServiceIds((current) =>
    current.includes(serviceId) ? current.filter((id) => id !== serviceId) : [...current, serviceId],
  );
};

const saveModifiedServices = async () => {
  if (!modifyOrder || selectedModifyServiceIds.length === 0) return;
  setIsSavingModifyServices(true);

  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const response = await fetch(`/api/customer/repairs/${modifyOrder.id}/services`, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken || '',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        service_ids: selectedModifyServiceIds,
        remove_package: Boolean(modifyOrder.repair_package_id),
      }),
    });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Unable to update services.');

    closeModifyModal();
    await fetchRepairs();
    Swal.fire({ icon: 'success', title: 'Services Updated', text: 'Your repair total and chat were updated.', confirmButtonColor: '#000000' });
  } catch (error: any) {
    Swal.fire({ icon: 'error', title: 'Update Failed', text: error?.message || 'Please try again.', confirmButtonColor: '#000000' });
  } finally {
    setIsSavingModifyServices(false);
  }
};

const selectedModifyTotal = availableModifyServices
  .filter((service) => selectedModifyServiceIds.includes(service.id))
  .reduce((sum, service) => sum + Number(service.price || 0), 0);
```

When implementing, close the modal by directly resetting state after success rather than calling `closeModifyModal()` while `isSavingModifyServices` is true:

```ts
setModifyOrder(null);
setAvailableModifyServices([]);
setSelectedModifyServiceIds([]);
setPackageRemovalConfirmed(false);
```

- [ ] **Step 5: Add the eligible card action**

Insert before the existing accepted-repair payment/cancel actions:

```tsx
{order.status === 'repairer_accepted' &&
  order.conversation_id &&
  !['paid', 'completed'].includes(String(order.payment_status || '').toLowerCase()) &&
  Number(order.total_paid_amount || 0) === 0 && (
    <button
      type="button"
      onClick={() => openModifyModal(order)}
      disabled={processingPayment}
      className={`${actionButtonBaseClass} ${processingPayment ? actionButtonDisabledClass : 'border border-[#16233b] bg-white text-[#16233b] hover:bg-slate-50'}`}
    >
      MODIFY
    </button>
  )}
```

- [ ] **Step 6: Add the accessible inline modal**

Render this immediately before the existing cancel modal:

```tsx
{modifyOrder && (
  <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" role="dialog" aria-modal="true" aria-labelledby="modify-repair-services-title">
    <div className="max-h-[85vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl sm:p-7">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 id="modify-repair-services-title" className="text-xl font-semibold text-slate-950">Modify Repair Services</h2>
          <p className="mt-1 text-sm text-slate-500">Choose at least one active service from {modifyOrder.shop_name}.</p>
        </div>
        <button type="button" onClick={closeModifyModal} aria-label="Close modify services" className="text-2xl leading-none text-slate-500 hover:text-black">&times;</button>
      </div>

      {modifyOrder.repair_package_id && !packageRemovalConfirmed && (
        <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
          <p className="text-sm text-amber-900">This repair uses a package. Remove it to switch to individually priced services.</p>
          <button type="button" onClick={() => setPackageRemovalConfirmed(true)} className="mt-3 text-sm font-semibold text-amber-950 underline">Remove Package</button>
        </div>
      )}

      <div className="mt-5 space-y-2">
        {isLoadingModifyServices ? (
          <p className="py-8 text-center text-sm text-slate-500">Loading services...</p>
        ) : availableModifyServices.map((service) => {
          const checked = selectedModifyServiceIds.includes(service.id);
          return (
            <label key={service.id} className={`flex items-center justify-between gap-4 rounded-xl border p-4 ${packageRemovalConfirmed ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'}`}>
              <span className="flex items-center gap-3">
                <input type="checkbox" checked={checked} disabled={!packageRemovalConfirmed} onChange={() => toggleModifyService(service.id)} />
                <span>
                  <span className="block text-sm font-semibold text-slate-950">{service.name}</span>
                  {service.duration && <span className="block text-xs text-slate-500">{service.duration}</span>}
                </span>
              </span>
              <span className="text-sm font-semibold text-slate-950">{formatCurrency(Number(service.price || 0))}</span>
            </label>
          );
        })}
      </div>

      <div className="mt-6 flex items-center justify-between border-t border-slate-200 pt-4">
        <div>
          <p className="text-xs uppercase tracking-wider text-slate-500">Updated total</p>
          <p className="text-xl font-bold text-slate-950">{formatCurrency(selectedModifyTotal)}</p>
        </div>
        <div className="flex gap-2">
          <button type="button" onClick={closeModifyModal} disabled={isSavingModifyServices} className={`${actionButtonBaseClass} border border-slate-300 bg-white text-slate-700`}>CANCEL</button>
          <button type="button" onClick={saveModifiedServices} disabled={!packageRemovalConfirmed || selectedModifyServiceIds.length === 0 || isSavingModifyServices} className={`${actionButtonBaseClass} ${!packageRemovalConfirmed || selectedModifyServiceIds.length === 0 || isSavingModifyServices ? actionButtonDisabledClass : actionButtonPrimaryClass}`}>
            {isSavingModifyServices ? 'SAVING...' : 'SAVE CHANGES'}
          </button>
        </div>
      </div>
    </div>
  </div>
)}
```

- [ ] **Step 7: Run the focused frontend test**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.service-modification.test.ts
```

Expected: PASS, 1 test.

- [ ] **Step 8: Type-check through the production build**

Run:

```bash
pnpm build
```

Expected: Vite build exits 0. Do not stage generated `public/build` changes.

- [ ] **Step 9: Commit the frontend slice**

```bash
git add resources/js/Pages/UserSide/Repairs/myRepairs.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.service-modification.test.ts
git commit -m "feat: add repair service modification modal"
```

---

### Task 3: End-to-end verification

**Files:**
- Verify only; no source files should change.

**Interfaces:**
- Consumes: backend endpoint and My Repairs modal from Tasks 1-2.
- Produces: evidence that lifecycle restrictions, repricing, chat audit, frontend integration, and the production bundle all work together.

- [ ] **Step 1: Run all focused automated checks together**

```bash
php artisan test tests/Feature/Customer/RepairServiceModificationTest.php tests/Feature/Repairer/RepairerWorkflowTest.php tests/Feature/RepairPosPaymentFlowTest.php
pnpm test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.service-modification.test.ts
pnpm build
```

Expected: every command exits 0.

- [ ] **Step 2: Perform the browser smoke path**

Use one courier repair in `repairer_accepted` with a conversation and zero paid amount:

1. Open `/my-repairs?tab=pending` and confirm `MODIFY` is visible on that card.
2. Open Modify and verify current services are checked and only active services from the same shop appear.
3. Remove one service, add another, save, and verify the card names and totals refresh without a page reload.
4. Open chat and verify the automatic message lists Added, Removed, and New total.
5. Reopen Modify on a package repair; verify checkboxes stay disabled until Remove Package is clicked.
6. Confirm/pay the repair and verify Modify is no longer shown.

Expected: all six checks pass; payment uses the newly calculated total.

- [ ] **Step 3: Confirm the final diff excludes unrelated and generated files**

```bash
git status --short
git diff --check
git diff -- routes/web.php app/Http/Controllers/Api/RepairRequestController.php tests/Feature/Customer/RepairServiceModificationTest.php resources/js/Pages/UserSide/Repairs/myRepairs.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.service-modification.test.ts
```

Expected: `git diff --check` exits 0; only the five feature files are part of these commits. Existing unrelated dirty files remain untouched.
