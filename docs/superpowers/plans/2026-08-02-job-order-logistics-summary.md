# Retail Job Order Logistics Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Retail Job Order details modal always show a canonical logistics section with outbound and refund-return shipment details, authorized proof thumbnails, and an explicit empty state.

**Architecture:** Extend the existing Staff Order payload instead of adding another request. Batch-load outbound and refund-return shipments with their display legs, methods, assignments, riders, and proofs; serialize both through one private summary method; then render one compact Logistics section using the existing Job Repair delivery-progress visual pattern.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit feature tests, React/TypeScript, Vitest/Testing Library, Vite, Tailwind CSS.

---

## File Map

- Modify `tests/Feature/StaffOrderRefundPayloadTest.php` — backend contract and tenant/proof regressions.
- Modify `app/Http/Controllers/Api/StaffOrderController.php` — batch shipment lookup and canonical logistics serializer.
- Modify `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts` — modal visibility, detail fields, and thumbnail regressions.
- Modify `resources/js/Pages/ERP/STAFF/JobOrders.tsx` — shared logistics type, API mapping, stable Logistics section, and accessible thumbnails.
- Regenerate `public/build/**` — production assets after all source tests pass.

### Task 1: Add the canonical Staff Order logistics payload

**Files:**
- Modify: `tests/Feature/StaffOrderRefundPayloadTest.php`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php`

- [ ] **Step 1: Write failing backend contract tests**

Add model imports for `DeliveryAssignment`, `RiderProfile`, `Shipment`, `ShipmentLeg`, and `ShippingMethod`. Extend the existing return fixture so its delivered return leg has a named shipping method and an assigned rider, then assert list and show both expose:

```php
$this->assertSame($shipment->id, $payload['return_logistics']['shipment_id']);
$this->assertSame('Shop-owned logistics', $payload['return_logistics']['carrier']);
$this->assertSame('Marco Santos', $payload['return_logistics']['rider_name']);
$this->assertSame('09171234567', $payload['return_logistics']['rider_phone']);
$this->assertSame("/api/logistics/proofs/{$proof->id}/file", $payload['return_logistics']['proofs'][0]['file_url']);
```

Add focused tests for:

1. An order shipment returned at top-level `logistics` in both list and show responses.
2. A shipment without a leg returning shipment ID/status with nullable leg fields and `proofs: []`.
3. A proof on a non-delivered return leg not appearing in `proofs`.
4. A same-source-ID shipment belonging to another shop never appearing in the staff payload.

- [ ] **Step 2: Run the backend test and verify RED**

Run:

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php artisan test tests/Feature/StaffOrderRefundPayloadTest.php
```

Expected: FAIL because top-level `logistics` and carrier/rider fields do not exist, shipment-without-leg currently returns `null`, and non-delivered return proofs are currently exposed.

- [ ] **Step 3: Batch-load logistics shipments in `StaffOrderController`**

Add one private lookup method that follows the Job Repair controller pattern:

```php
private function latestShipmentLookup(
    int $shopOwnerId,
    string $sourceType,
    array $sourceIds,
    string $purpose,
) {
    if ($sourceIds === []) {
        return collect();
    }

    return Shipment::query()
        ->with([
            'legs.shippingMethod',
            'legs.assignments.riderProfile',
            'legs.proofs',
        ])
        ->where('shop_owner_id', $shopOwnerId)
        ->where('source_type', $sourceType)
        ->whereIn('source_id', $sourceIds)
        ->where('purpose', $purpose)
        ->orderByDesc('id')
        ->get()
        ->groupBy('source_id')
        ->map(fn ($shipments) => $shipments->first());
}
```

In `index`, build outbound and latest-refund shipment lookups before mapping orders. In `show`, use the same helper with one order/refund ID. Pass the selected `Shipment` instances into serialization so list requests do not add one query per order.

- [ ] **Step 4: Add one shared shipment summary serializer**

Add a private serializer that returns `null` only when the shipment is absent. When a shipment exists, return shipment ID/status even if no qualifying leg exists:

```php
[
    'shipment_id' => (int) $shipment->id,
    'shipment_status' => $shipment->status->value,
    'leg_id' => $leg ? (int) $leg->id : null,
    'leg_type' => $leg?->leg_type,
    'leg_status' => $leg?->status->value,
    'carrier' => $leg?->shippingMethod?->name ?? $fallback['carrier'] ?? null,
    'rider_name' => $assignment?->riderProfile?->name ?? $fallback['rider_name'] ?? null,
    'rider_phone' => $assignment?->riderProfile?->phone ?? $fallback['rider_phone'] ?? null,
    'tracking_number' => $leg?->tracking_number ?? $fallback['tracking_number'] ?? null,
    'tracking_url' => $leg?->tracking_url ?? $fallback['tracking_url'] ?? null,
    'proofs' => $proofs,
]
```

Select the highest-sequence qualifying leg and the latest assignment whose status is `assigned`, `accepted`, or `completed`. Map proof URLs through `/api/logistics/proofs/{id}/file`; never expose `file_path`.

For refund-return summaries, pass a flag that produces an empty proof array unless the selected return leg status is `delivered`. Preserve refund carrier/rider/tracking fields as fallbacks. For outbound summaries, use order carrier/rider/tracking fields as fallbacks.

- [ ] **Step 5: Expose the summaries from both endpoints**

Add top-level `logistics` to the list and show payloads. Change `serializeLatestRefund` to accept the already-loaded return shipment and call the shared serializer even when the shipment has no leg.

- [ ] **Step 6: Run the backend test and verify GREEN**

Run the command from Step 2.

Expected: PASS with all `StaffOrderRefundPayloadTest` assertions green.

- [ ] **Step 7: Format and commit the backend change**

Run:

```powershell
php vendor/bin/pint app/Http/Controllers/Api/StaffOrderController.php tests/Feature/StaffOrderRefundPayloadTest.php
git diff --check
```

Commit:

```powershell
git add -- app/Http/Controllers/Api/StaffOrderController.php tests/Feature/StaffOrderRefundPayloadTest.php
git commit -m "fix: expose job order logistics summary"
```

### Task 2: Render the always-present Logistics section

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`

- [ ] **Step 1: Write failing UI tests**

Add a modal test for an order without shipment data:

```tsx
expect(screen.getByText('Logistics')).toBeInTheDocument();
expect(screen.getByText('No logistics shipment yet.')).toBeInTheDocument();
```

Extend `makeRefundOrder` with a top-level outbound logistics summary and enriched return summary. Assert the modal shows:

- `Customer delivery` and `Return to shop` cards.
- Shipment IDs for both cards.
- Shipment and leg statuses as readable text.
- The refund `return_status` as a separate readable `Return status` row.
- Carrier, rider name/phone, tracking link/number.
- `Refund evidence 1` separate from `Return delivery proof 1`.
- `No proof submitted yet.` when a summary contains no proofs.
- `Leg not created yet` when shipment data has nullable leg fields.

- [ ] **Step 2: Run the UI test and verify RED**

Run:

```powershell
npm exec -- vitest run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts
```

Expected: FAIL because the Logistics section is conditional, top-level outbound logistics is not mapped, and the new detail labels do not exist.

- [ ] **Step 3: Add the shared TypeScript logistics contract**

Define one `LogisticsSummary` interface and reuse it for top-level `Order.logistics` and `latest_refund.return_logistics`:

```tsx
interface LogisticsSummary {
  shipment_id: number;
  shipment_status: string;
  leg_id?: number | null;
  leg_type?: string | null;
  leg_status?: string | null;
  carrier?: string | null;
  rider_name?: string | null;
  rider_phone?: string | null;
  tracking_number?: string | null;
  tracking_url?: string | null;
  proofs: LogisticsProof[];
}
```

Map `order.logistics || null` in the existing backend-to-UI order mapper.

- [ ] **Step 4: Replace the conditional Return Logistics block**

Render one always-present **Logistics** section after Refund Evidence. Reuse the Job Repair card hierarchy: section label, bordered status card, text status labels, and explicit empty state.

Render the available summaries from:

```tsx
[
  { label: 'Customer delivery', summary: viewOrder.logistics, proofLabel: 'Delivery proof' },
  { label: 'Return to shop', summary: viewOrder.latest_refund?.return_logistics, proofLabel: 'Return delivery proof' },
]
```

For each card, show shipment ID/status, leg status, carrier, rider/phone, tracking, and proof thumbnails. For the `Return to shop` card, also show the existing `latest_refund.return_status` as `Return status`; keep this refund workflow state separate from shipment and leg statuses. Use `Not assigned`, `Not available`, `Leg not created yet`, and `No proof submitted yet.` for missing values. Keep the existing protected proof links and `target="_blank" rel="noreferrer"`.

Delete the old conditional **Return Logistics** block so the same data is not shown twice. Keep the separate **Shipping & Tracking** legacy block unchanged unless its existing fields already duplicate a value inside the new summary; this task does not change shipping actions.

- [ ] **Step 5: Run the UI test and verify GREEN**

Run the command from Step 2.

Expected: PASS with all Job Order modal tests green.

- [ ] **Step 6: Commit the UI change**

Run:

```powershell
git diff --check
git add -- resources/js/Pages/ERP/STAFF/JobOrders.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts
git commit -m "fix: show job order logistics details"
```

### Task 3: Verify and publish the production assets

**Files:**
- Modify: `public/build/**`

- [ ] **Step 1: Run focused backend verification**

Run:

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php artisan test tests/Feature/StaffOrderRefundPayloadTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/StaffRetailShippingCoverageTest.php
```

Expected: PASS with zero failures.

- [ ] **Step 2: Run focused frontend verification**

Run:

```powershell
npm exec -- vitest run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx
```

Expected: PASS with zero failed files or tests.

- [ ] **Step 3: Run formatting checks**

Run:

```powershell
php vendor/bin/pint --test app/Http/Controllers/Api/StaffOrderController.php tests/Feature/StaffOrderRefundPayloadTest.php
git diff --check origin/solespace-b...HEAD
```

Expected: Pint passes and Git reports no whitespace errors.

- [ ] **Step 4: Build fresh production assets**

Run:

```powershell
npm run build
```

Expected: Vite exits 0 and regenerates `public/build/manifest.json` plus hashed assets.

- [ ] **Step 5: Commit only generated assets**

Run:

```powershell
git add -- public/build
git commit -m "build: refresh job order logistics assets"
```

- [ ] **Step 6: Perform final branch checks**

Run:

```powershell
git status --short --branch
git diff --check origin/solespace-b...HEAD
git log --oneline origin/solespace-b..HEAD
```

Expected: clean worktree; design, backend, UI, and build commits only.
