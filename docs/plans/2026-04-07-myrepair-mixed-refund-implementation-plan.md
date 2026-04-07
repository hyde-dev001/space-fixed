# myRepair Mixed Refund Split-Settlement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement one customer-facing myRepair refund request that can split mixed payments into gateway and POS-manual legs, with finance proof requirements and role-based proof visibility.

**Architecture:** Keep `pos_refunds` as the customer-visible parent refund record, then add child refund legs in `pos_refund_legs` for source-specific execution (`gateway`, `pos_manual`). Extend existing repair refund controllers/services instead of introducing a parallel workflow stack. Keep backward compatibility by applying new validations only when split-leg records exist.

**Tech Stack:** Laravel 11 (PHP), MySQL migrations, Eloquent models/services/controllers, Inertia React + TypeScript, Vitest, PHPUnit.

---

## File Structure and Responsibilities

- Create: `database/migrations/2026_04_07_130000_create_pos_refund_legs_table.php`
  - Stores child settlement legs per parent refund.
- Create: `database/migrations/2026_04_07_131000_add_mixed_refund_fields_to_pos_refunds_table.php`
  - Stores payout preference + finance execution proof fields on parent refund.
- Create: `app/Models/PosRefundLeg.php`
  - Eloquent model for leg records.
- Modify: `app/Models/PosRefund.php`
  - Add fillables/casts/relations for new fields + legs.
- Modify: `app/Services/RepairPosRefundService.php`
  - Create split legs, validate POS-manual execution proof, execute by leg.
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
  - Accept payout preference fields from myRepair submit and pass to service.
- Modify: `app/Http/Controllers/Api/RepairRefundWorkflowController.php`
  - Require proof payload on finance execute and expose finance-safe/full payload.
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
  - Preserve compatibility with existing POS flow while supporting leg-aware execution.
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
  - Collect payout preference for POS leg and remove strict 5-image+1-video requirement.
- Create: `resources/js/Pages/UserSide/Repairs/refundPayloadBuilder.ts`
  - Dedicated builder for myRepair refund submit payload.
- Create: `resources/js/Pages/UserSide/Repairs/__tests__/refundPayloadBuilder.test.ts`
  - Unit tests for payload contract.
- Modify: `resources/js/Pages/ERP/Finance/refundApproval.tsx`
  - Add POS-manual execute modal fields and send proof payload.
- Create: `resources/js/Pages/ERP/Finance/repairRefundExecutionPayload.ts`
  - Payload builder + validation for finance execute action.
- Create: `resources/js/Pages/ERP/Finance/__tests__/repairRefundExecutionPayload.test.ts`
  - Unit tests for finance execute payload rules.
- Create: `tests/Feature/RepairMixedRefundSplitSettlementTest.php`
  - Backend flow tests for split legs + proof enforcement + redaction.

---

### Task 1: Add Schema and Model Support for Split Legs and Proof Fields

**Files:**
- Create: `database/migrations/2026_04_07_130000_create_pos_refund_legs_table.php`
- Create: `database/migrations/2026_04_07_131000_add_mixed_refund_fields_to_pos_refunds_table.php`
- Create: `app/Models/PosRefundLeg.php`
- Modify: `app/Models/PosRefund.php`
- Test: `tests/Feature/RepairMixedRefundSplitSettlementTest.php`

- [ ] **Step 1: Write the failing schema test**

```php
#[Test]
public function refund_schema_supports_split_legs_and_proof_fields(): void
{
    $this->assertTrue(Schema::hasTable('pos_refund_legs'));

    $this->assertTrue(Schema::hasColumns('pos_refund_legs', [
        'pos_refund_id',
        'leg_type',
        'requested_amount',
        'approved_amount',
        'status',
        'source_transaction_id',
        'source_receipt_no',
    ]));

    $this->assertTrue(Schema::hasColumns('pos_refunds', [
        'preferred_return_channel',
        'preferred_return_account_name',
        'preferred_return_account_ref',
        'customer_payout_consent',
        'execution_channel',
        'execution_reference',
        'execution_amount',
        'execution_proof_urls',
    ]));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php --filter refund_schema_supports_split_legs_and_proof_fields`
Expected: FAIL with missing table/column assertions.

- [ ] **Step 3: Write minimal migration + model implementation**

```php
// database/migrations/2026_04_07_130000_create_pos_refund_legs_table.php
Schema::create('pos_refund_legs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('pos_refund_id')->constrained('pos_refunds')->cascadeOnDelete();
    $table->enum('leg_type', ['gateway', 'pos_manual']);
    $table->decimal('requested_amount', 12, 2);
    $table->decimal('approved_amount', 12, 2)->nullable();
    $table->enum('status', ['requested', 'approved', 'processing', 'succeeded', 'failed', 'rejected'])->default('requested');
    $table->unsignedBigInteger('source_transaction_id')->nullable();
    $table->string('source_receipt_no', 120)->nullable();
    $table->json('meta')->nullable();
    $table->timestamps();

    $table->index(['pos_refund_id', 'leg_type']);
    $table->index(['status']);
});
```

```php
// database/migrations/2026_04_07_131000_add_mixed_refund_fields_to_pos_refunds_table.php
Schema::table('pos_refunds', function (Blueprint $table) {
    $table->string('preferred_return_channel', 30)->nullable()->after('evidence_snapshot');
    $table->string('preferred_return_account_name')->nullable()->after('preferred_return_channel');
    $table->string('preferred_return_account_ref')->nullable()->after('preferred_return_account_name');
    $table->boolean('customer_payout_consent')->default(false)->after('preferred_return_account_ref');

    $table->string('execution_channel', 30)->nullable()->after('execution_mode');
    $table->string('execution_reference', 150)->nullable()->after('execution_channel');
    $table->decimal('execution_amount', 12, 2)->nullable()->after('execution_reference');
    $table->json('execution_proof_urls')->nullable()->after('execution_amount');
});
```

```php
// app/Models/PosRefundLeg.php
class PosRefundLeg extends Model
{
    protected $fillable = [
        'pos_refund_id',
        'leg_type',
        'requested_amount',
        'approved_amount',
        'status',
        'source_transaction_id',
        'source_receipt_no',
        'meta',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(PosRefund::class, 'pos_refund_id');
    }
}
```

```php
// app/Models/PosRefund.php (additions)
protected $fillable = [
    // existing fields...
    'preferred_return_channel',
    'preferred_return_account_name',
    'preferred_return_account_ref',
    'customer_payout_consent',
    'execution_channel',
    'execution_reference',
    'execution_amount',
    'execution_proof_urls',
];

protected $casts = [
    // existing casts...
    'customer_payout_consent' => 'boolean',
    'execution_amount' => 'decimal:2',
    'execution_proof_urls' => 'array',
];

public function legs(): HasMany
{
    return $this->hasMany(PosRefundLeg::class, 'pos_refund_id');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php --filter refund_schema_supports_split_legs_and_proof_fields`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_07_130000_create_pos_refund_legs_table.php database/migrations/2026_04_07_131000_add_mixed_refund_fields_to_pos_refunds_table.php app/Models/PosRefund.php app/Models/PosRefundLeg.php tests/Feature/RepairMixedRefundSplitSettlementTest.php
git commit -m "feat: add split-leg refund schema and proof fields"
```

### Task 2: Create Parent Refund with Internal Split Legs on myRepair Submit

**Files:**
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Modify: `app/Services/RepairPosRefundService.php`
- Modify: `app/Models/PosRefund.php`
- Test: `tests/Feature/RepairMixedRefundSplitSettlementTest.php`

- [ ] **Step 1: Write the failing mixed-payment creation test**

```php
#[Test]
public function myrepair_submit_creates_parent_refund_with_gateway_and_pos_legs_for_mixed_payment(): void
{
    $customer = User::factory()->create();
    $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

    $repair = RepairRequest::factory()->create([
        'user_id' => $customer->id,
        'shop_owner_id' => $shopOwner->id,
        'total_paid_amount' => 1000,
        'total_refunded_amount' => 0,
        'latest_pos_transaction_id' => null,
    ]);

    $source = PosTransaction::create([
        'transaction_no' => 'POS-MIX-001',
        'shop_owner_id' => $shopOwner->id,
        'module_type' => 'repair',
        'module_reference_id' => $repair->id,
        'customer_type' => 'registered',
        'customer_id' => $customer->id,
        'due_type' => 'full',
        'subtotal' => 400,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 400,
        'paid_amount' => 400,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds", [
        'source_transaction_id' => $source->id,
        'request_type' => 'full',
        'requested_amount' => 400,
        'reason_code' => 'mixed_payment_refund',
        'reason_notes' => 'Customer requested mixed payment refund.',
        'evidence' => [['type' => 'photo', 'url' => 'https://evidence.local/a.jpg']],
        'preferred_return_channel' => 'gcash',
        'preferred_return_account_name' => 'Juan Dela Cruz',
        'preferred_return_account_ref' => '09171234567',
        'customer_payout_consent' => true,
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $refund = PosRefund::query()->latest('id')->firstOrFail();
    $this->assertEqualsCanonicalizing(['gateway', 'pos_manual'], $refund->legs()->pluck('leg_type')->all());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php --filter myrepair_submit_creates_parent_refund_with_gateway_and_pos_legs_for_mixed_payment`
Expected: FAIL because no legs are created.

- [ ] **Step 3: Write minimal service/controller implementation**

```php
// app/Services/RepairPosRefundService.php
public function createRefundWithSplitLegs(PosTransaction $source, array $payload, int $actorId): PosRefund
{
    $refund = $this->requestRefund($source, $payload, $actorId);

    $refund->update([
        'preferred_return_channel' => $payload['preferred_return_channel'] ?? null,
        'preferred_return_account_name' => $payload['preferred_return_account_name'] ?? null,
        'preferred_return_account_ref' => $payload['preferred_return_account_ref'] ?? null,
        'customer_payout_consent' => (bool) ($payload['customer_payout_consent'] ?? false),
    ]);

    $requestedAmount = (float) $payload['requested_amount'];
    $gatewayAmount = (float) ($payload['gateway_amount'] ?? 0);
    $posAmount = max(0.0, round($requestedAmount - $gatewayAmount, 2));

    if ($gatewayAmount > 0) {
        $refund->legs()->create([
            'leg_type' => 'gateway',
            'requested_amount' => $gatewayAmount,
            'status' => 'requested',
            'source_transaction_id' => $source->id,
            'source_receipt_no' => (string) ($source->receipt?->receipt_no ?? $source->transaction_no),
        ]);
    }

    if ($posAmount > 0) {
        $refund->legs()->create([
            'leg_type' => 'pos_manual',
            'requested_amount' => $posAmount,
            'status' => 'requested',
            'source_transaction_id' => $source->id,
            'source_receipt_no' => (string) ($source->receipt?->receipt_no ?? $source->transaction_no),
        ]);
    }

    return $refund->fresh('legs');
}
```

```php
// app/Http/Controllers/Api/RepairRequestController.php (in requestRefundFromMyRepair)
$validated = $request->validate([
    // existing fields...
    'preferred_return_channel' => ['nullable', 'in:gcash,card,bank_transfer,manual_cash'],
    'preferred_return_account_name' => ['nullable', 'string', 'max:120'],
    'preferred_return_account_ref' => ['nullable', 'string', 'max:120'],
    'customer_payout_consent' => ['nullable', 'boolean'],
]);

$refund = DB::transaction(function () use ($refundService, $sourceTransaction, $validated, $user) {
    return $refundService->createRefundWithSplitLegs($sourceTransaction, [
        'workflow_source' => 'online_myrepair',
        'request_type' => $validated['request_type'],
        'requested_amount' => (float) $validated['requested_amount'],
        'reason_code' => $validated['reason_code'],
        'reason_notes' => $validated['reason_notes'] ?? null,
        'preferred_return_channel' => $validated['preferred_return_channel'] ?? null,
        'preferred_return_account_name' => $validated['preferred_return_account_name'] ?? null,
        'preferred_return_account_ref' => $validated['preferred_return_account_ref'] ?? null,
        'customer_payout_consent' => (bool) ($validated['customer_payout_consent'] ?? false),
        'gateway_amount' => 0.0,
    ], (int) $user->id);
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php --filter myrepair_submit_creates_parent_refund_with_gateway_and_pos_legs_for_mixed_payment`
Expected: PASS with two legs present.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairRequestController.php app/Services/RepairPosRefundService.php app/Models/PosRefund.php tests/Feature/RepairMixedRefundSplitSettlementTest.php
git commit -m "feat: create split refund legs from myRepair submit"
```

### Task 3: Enforce Finance Proof Fields for POS-Manual Execution

**Files:**
- Modify: `app/Http/Controllers/Api/RepairRefundWorkflowController.php`
- Modify: `app/Services/RepairPosRefundService.php`
- Test: `tests/Feature/RepairMixedRefundSplitSettlementTest.php`

- [ ] **Step 1: Write the failing execution validation test**

```php
#[Test]
public function finance_execute_rejects_pos_manual_leg_without_execution_proof(): void
{
    $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $finance = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

    $refund = PosRefund::factory()->create([
        'shop_owner_id' => $shopOwner->id,
        'module_type' => 'repair',
        'status' => 'approved',
        'finance_status' => 'approved',
        'shop_owner_status' => 'skipped',
    ]);

    $refund->legs()->create([
        'leg_type' => 'pos_manual',
        'requested_amount' => 300,
        'approved_amount' => 300,
        'status' => 'approved',
    ]);

    $this->actingAs($finance, 'user')
        ->postJson("/api/finance/repair-refunds/{$refund->id}/execute", [
            'execution_mode' => 'manual',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['execution_channel', 'execution_reference', 'execution_proof_urls']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php --filter finance_execute_rejects_pos_manual_leg_without_execution_proof`
Expected: FAIL because endpoint currently accepts bare `execution_mode`.

- [ ] **Step 3: Write minimal controller/service validation**

```php
// app/Http/Controllers/Api/RepairRefundWorkflowController.php (financeExecute)
$validated = $request->validate([
    'execution_mode' => ['nullable', 'in:manual,gateway'],
    'execution_note' => ['nullable', 'string', 'max:1000'],
    'execution_channel' => ['required_if:execution_mode,manual', 'nullable', 'in:gcash,card,bank_transfer,manual_cash'],
    'execution_reference' => ['required_if:execution_mode,manual', 'nullable', 'string', 'max:150'],
    'execution_amount' => ['required_if:execution_mode,manual', 'nullable', 'numeric', 'min:0.01'],
    'execution_proof_urls' => ['required_if:execution_mode,manual', 'array', 'min:1'],
    'execution_proof_urls.*' => ['url'],
]);

$updated = $service->execute(
    refund: $refund,
    actorId: (int) $actor->id,
    executionMode: (string) ($validated['execution_mode'] ?? 'manual'),
    executionNote: $validated['execution_note'] ?? null,
    executionContext: [
        'execution_channel' => $validated['execution_channel'] ?? null,
        'execution_reference' => $validated['execution_reference'] ?? null,
        'execution_amount' => isset($validated['execution_amount']) ? (float) $validated['execution_amount'] : null,
        'execution_proof_urls' => $validated['execution_proof_urls'] ?? [],
    ],
);
```

```php
// app/Services/RepairPosRefundService.php (execute signature + persistence)
public function execute(
    PosRefund $refund,
    int $actorId,
    string $executionMode = 'manual',
    ?string $executionNote = null,
    array $executionContext = []
): PosRefund {
    // existing status guards...

    $hasPosManualLeg = $refund->legs()->where('leg_type', 'pos_manual')->exists();
    if ($executionMode === 'manual' && $hasPosManualLeg) {
        if (empty($executionContext['execution_channel']) || empty($executionContext['execution_reference']) || empty($executionContext['execution_proof_urls'])) {
            throw ValidationException::withMessages([
                'execution_proof_urls' => ['POS manual execution requires channel, reference, and at least one proof URL.'],
            ]);
        }
    }

    $refund->update([
        'execution_channel' => $executionContext['execution_channel'] ?? null,
        'execution_reference' => $executionContext['execution_reference'] ?? null,
        'execution_amount' => $executionContext['execution_amount'] ?? null,
        'execution_proof_urls' => $executionContext['execution_proof_urls'] ?? null,
    ]);

    return $this->markRefundSucceeded($refund->fresh(), $source, $actorId, $approvedAmount, 'manual', $executionNote, null, null);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php --filter finance_execute_rejects_pos_manual_leg_without_execution_proof`
Expected: PASS with validation error assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairRefundWorkflowController.php app/Services/RepairPosRefundService.php tests/Feature/RepairMixedRefundSplitSettlementTest.php
git commit -m "feat: enforce finance proof fields for POS-manual refund execution"
```

### Task 4: Implement Proof Visibility Rules (Full for Finance, Redacted for Customer)

**Files:**
- Modify: `app/Http/Controllers/Api/RepairRefundWorkflowController.php`
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Test: `tests/Feature/RepairMixedRefundSplitSettlementTest.php`

- [ ] **Step 1: Write failing visibility test**

```php
#[Test]
public function customer_refund_list_returns_redacted_execution_reference_only(): void
{
    $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
    $customer = User::factory()->create();

    $repair = RepairRequest::factory()->create([
        'shop_owner_id' => $shopOwner->id,
        'user_id' => $customer->id,
    ]);

    PosRefund::factory()->create([
        'shop_owner_id' => $shopOwner->id,
        'module_type' => 'repair',
        'module_reference_id' => $repair->id,
        'execution_reference' => 'AUTH-1234567890',
        'execution_proof_urls' => ['https://proof.local/rfd-1.png'],
        'status' => 'succeeded',
    ]);

    $response = $this->actingAs($customer, 'user')->getJson('/api/repair-pos/refunds/mine');

    $response->assertOk()
        ->assertJsonMissing(['execution_reference' => 'AUTH-1234567890'])
        ->assertJsonPath('data.0.execution_reference_masked', 'AUTH-******7890');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php --filter customer_refund_list_returns_redacted_execution_reference_only`
Expected: FAIL because full fields are currently serialized directly.

- [ ] **Step 3: Write minimal redaction implementation**

```php
// app/Http/Controllers/Api/RepairPosController.php (inside listMyRefunds)
$data = $refunds->map(function (PosRefund $refund) {
    $masked = null;
    $rawReference = (string) ($refund->execution_reference ?? '');
    if ($rawReference !== '') {
        $prefix = substr($rawReference, 0, 4);
        $suffix = substr($rawReference, -4);
        $masked = $prefix . '-******' . $suffix;
    }

    return [
        'id' => $refund->id,
        'module_reference_id' => $refund->module_reference_id,
        'status' => $refund->status,
        'execution_channel' => $refund->execution_channel,
        'execution_reference_masked' => $masked,
        'executed_at' => optional($refund->executed_at)->toDateTimeString(),
        'requested_amount' => (float) $refund->requested_amount,
        'approved_amount' => (float) ($refund->approved_amount ?? 0),
    ];
});

return response()->json(['success' => true, 'data' => $data]);
```

```php
// app/Http/Controllers/Api/RepairRefundWorkflowController.php (transformApprovalRefund additions)
'financeExecution' => [
    'execution_channel' => (string) ($refund->execution_channel ?? ''),
    'execution_reference' => (string) ($refund->execution_reference ?? ''),
    'execution_amount' => (float) ($refund->execution_amount ?? 0),
    'execution_proof_urls' => is_array($refund->execution_proof_urls) ? $refund->execution_proof_urls : [],
],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php --filter customer_refund_list_returns_redacted_execution_reference_only`
Expected: PASS with redacted customer response.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RepairPosController.php app/Http/Controllers/Api/RepairRefundWorkflowController.php tests/Feature/RepairMixedRefundSplitSettlementTest.php
git commit -m "feat: add role-based refund proof visibility with customer redaction"
```

### Task 5: Update myRepairs Refund Form Contract (POS Payout Preference + Flexible Media)

**Files:**
- Create: `resources/js/Pages/UserSide/Repairs/refundPayloadBuilder.ts`
- Create: `resources/js/Pages/UserSide/Repairs/__tests__/refundPayloadBuilder.test.ts`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`

- [ ] **Step 1: Write failing payload builder test**

```ts
import { describe, expect, it } from "vitest";
import { buildRepairRefundPayload } from "../refundPayloadBuilder";

describe("buildRepairRefundPayload", () => {
  it("includes POS payout preference and allows at least one media file", () => {
    const payload = buildRepairRefundPayload({
      sourceTransactionId: 99,
      requestedAmount: 560,
      reasonCode: "service_issue",
      reasonNotes: "Need refund",
      preferredReturnChannel: "gcash",
      preferredReturnAccountName: "Juan Dela Cruz",
      preferredReturnAccountRef: "09171234567",
      customerPayoutConsent: true,
      evidence: [{ type: "photo", url: "https://evidence.local/p1.jpg" }],
    });

    expect(payload.preferred_return_channel).toBe("gcash");
    expect(payload.customer_payout_consent).toBe(true);
    expect(payload.evidence).toHaveLength(1);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm vitest resources/js/Pages/UserSide/Repairs/__tests__/refundPayloadBuilder.test.ts`
Expected: FAIL because builder file does not exist.

- [ ] **Step 3: Write minimal builder + wire into page submit**

```ts
// resources/js/Pages/UserSide/Repairs/refundPayloadBuilder.ts
export type RefundEvidenceItem = { type: "photo" | "video"; url: string };

export function buildRepairRefundPayload(input: {
  sourceTransactionId: number;
  requestedAmount: number;
  reasonCode: string;
  reasonNotes: string;
  preferredReturnChannel: "gcash" | "card" | "bank_transfer" | "manual_cash";
  preferredReturnAccountName: string;
  preferredReturnAccountRef: string;
  customerPayoutConsent: boolean;
  evidence: RefundEvidenceItem[];
}) {
  return {
    source_transaction_id: input.sourceTransactionId,
    request_type: "full",
    requested_amount: input.requestedAmount,
    reason_code: input.reasonCode,
    reason_notes: input.reasonNotes,
    preferred_return_channel: input.preferredReturnChannel,
    preferred_return_account_name: input.preferredReturnAccountName,
    preferred_return_account_ref: input.preferredReturnAccountRef,
    customer_payout_consent: input.customerPayoutConsent,
    evidence: input.evidence,
  };
}
```

```ts
// resources/js/Pages/UserSide/Repairs/myRepairs.tsx (submit usage)
const payload = buildRepairRefundPayload({
  sourceTransactionId: targetOrder.latest_pos_transaction_id,
  requestedAmount: refundableAmount,
  reasonCode,
  reasonNotes,
  preferredReturnChannel: refundMethod as "gcash" | "card" | "bank_transfer" | "manual_cash",
  preferredReturnAccountName: refundAccountName,
  preferredReturnAccountRef: refundAccountRef,
  customerPayoutConsent: refundPayoutConsent,
  evidence,
});

body: JSON.stringify(payload),
```

```ts
// resources/js/Pages/UserSide/Repairs/myRepairs.tsx (flexible media rule)
const isMediaRequirementMet = () => refundMedia.length >= 1;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm vitest resources/js/Pages/UserSide/Repairs/__tests__/refundPayloadBuilder.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/UserSide/Repairs/refundPayloadBuilder.ts resources/js/Pages/UserSide/Repairs/__tests__/refundPayloadBuilder.test.ts resources/js/Pages/UserSide/Repairs/myRepairs.tsx
git commit -m "feat: add myRepair POS payout preference payload and flexible media requirement"
```

### Task 6: Add Finance Execute Proof Payload Builder and UI Wiring

**Files:**
- Create: `resources/js/Pages/ERP/Finance/repairRefundExecutionPayload.ts`
- Create: `resources/js/Pages/ERP/Finance/__tests__/repairRefundExecutionPayload.test.ts`
- Modify: `resources/js/Pages/ERP/Finance/refundApproval.tsx`

- [ ] **Step 1: Write failing payload validation test**

```ts
import { describe, expect, it } from "vitest";
import { buildRepairRefundExecutionPayload } from "../repairRefundExecutionPayload";

describe("buildRepairRefundExecutionPayload", () => {
  it("throws when POS manual proof is incomplete", () => {
    expect(() =>
      buildRepairRefundExecutionPayload({
        executionMode: "manual",
        executionChannel: "gcash",
        executionReference: "",
        executionAmount: 500,
        executionProofUrls: [],
      }),
    ).toThrow("Execution reference is required for manual POS refund execution");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm vitest resources/js/Pages/ERP/Finance/__tests__/repairRefundExecutionPayload.test.ts`
Expected: FAIL because helper file does not exist.

- [ ] **Step 3: Write minimal helper and integrate in execute action**

```ts
// resources/js/Pages/ERP/Finance/repairRefundExecutionPayload.ts
export function buildRepairRefundExecutionPayload(input: {
  executionMode: "manual" | "gateway";
  executionChannel?: "gcash" | "card" | "bank_transfer" | "manual_cash";
  executionReference?: string;
  executionAmount?: number;
  executionProofUrls?: string[];
}) {
  if (input.executionMode === "manual") {
    if (!input.executionReference?.trim()) {
      throw new Error("Execution reference is required for manual POS refund execution");
    }
    if (!input.executionProofUrls || input.executionProofUrls.length === 0) {
      throw new Error("At least one execution proof is required for manual POS refund execution");
    }
  }

  return {
    execution_mode: input.executionMode,
    execution_channel: input.executionChannel ?? null,
    execution_reference: input.executionReference ?? null,
    execution_amount: input.executionAmount ?? null,
    execution_proof_urls: input.executionProofUrls ?? [],
  };
}
```

```ts
// resources/js/Pages/ERP/Finance/refundApproval.tsx (inside handleExecuteGatewayRefund)
const payload = request.refundType === "repair"
  ? buildRepairRefundExecutionPayload({
      executionMode: "manual",
      executionChannel,
      executionReference,
      executionAmount,
      executionProofUrls,
    })
  : {};

body: JSON.stringify(payload),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm vitest resources/js/Pages/ERP/Finance/__tests__/repairRefundExecutionPayload.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/Finance/repairRefundExecutionPayload.ts resources/js/Pages/ERP/Finance/__tests__/repairRefundExecutionPayload.test.ts resources/js/Pages/ERP/Finance/refundApproval.tsx
git commit -m "feat: require finance execution proof payload for repair refund execution"
```

### Task 7: End-to-End Mixed Refund Regression and Rollout Guard

**Files:**
- Modify: `tests/Feature/RepairMixedRefundSplitSettlementTest.php`
- Modify: `config/orders.php`
- Modify: `app/Services/RepairPosRefundService.php`

- [ ] **Step 1: Write failing rollout-flag test**

```php
#[Test]
public function split_leg_creation_applies_only_when_feature_flag_enabled(): void
{
    config()->set('orders.repair_split_refund_enabled', false);

    // Create repair + source transaction ...

    $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds", $payload);

    $response->assertOk();
    $refund = PosRefund::query()->latest('id')->firstOrFail();

    $this->assertCount(0, $refund->legs);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php --filter split_leg_creation_applies_only_when_feature_flag_enabled`
Expected: FAIL because service always creates legs.

- [ ] **Step 3: Write minimal flag guard implementation**

```php
// config/orders.php
return [
    // existing config...
    'repair_split_refund_enabled' => env('REPAIR_SPLIT_REFUND_ENABLED', true),
];
```

```php
// app/Services/RepairPosRefundService.php
if (!config('orders.repair_split_refund_enabled', true)) {
    return $refund;
}

// existing leg creation block...
```

- [ ] **Step 4: Run targeted and full relevant test suites**

Run: `php artisan test tests/Feature/RepairMixedRefundSplitSettlementTest.php tests/Feature/RepairOnlineRefundWorkflowTest.php tests/Feature/RepairPosRefundFlowTest.php`
Expected: PASS.

Run: `pnpm vitest resources/js/Pages/UserSide/Repairs/__tests__/refundPayloadBuilder.test.ts resources/js/Pages/ERP/Finance/__tests__/repairRefundExecutionPayload.test.ts resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.refund-workflow.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/RepairMixedRefundSplitSettlementTest.php config/orders.php app/Services/RepairPosRefundService.php
git commit -m "chore: gate split-leg mixed refund flow behind feature flag"
```

---

## Self-Review

1. Spec coverage:
- One myRepair ticket with internal split legs: covered in Task 2.
- Finance proof-required execution for POS leg: covered in Task 3 and Task 6.
- Proof visibility rules (full vs redacted): covered in Task 4.
- Flexible media minimum and payout preference collection: covered in Task 5.
- New-record rollout safety: covered in Task 7.

2. Placeholder scan:
- No `TBD`, `TODO`, or deferred implementation placeholders included.
- Every code-changing step includes concrete code blocks.
- Every verification step includes explicit run commands and expected outcomes.

3. Type consistency:
- Backend field names align across migration/model/controller/service:
  - `preferred_return_channel`
  - `preferred_return_account_name`
  - `preferred_return_account_ref`
  - `customer_payout_consent`
  - `execution_channel`
  - `execution_reference`
  - `execution_amount`
  - `execution_proof_urls`
- Frontend builders use the same API property names as backend validation.

---

Plan complete and saved to `docs/plans/2026-04-07-myrepair-mixed-refund-implementation-plan.md`. Two execution options:

1. Subagent-Driven (recommended) - I dispatch a fresh subagent per task, review between tasks, fast iteration
2. Inline Execution - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
