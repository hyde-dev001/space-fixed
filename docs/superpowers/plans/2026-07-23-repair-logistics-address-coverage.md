# Repair Logistics, Address, and Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add pinned-address coverage, separate intake/return delivery fees, and automatic shop-owned Logistics shipments to the existing Repair lifecycle while preserving walk-in and customer-arranged third-party delivery.

**Architecture:** Keep `RepairRequest` as the source of truth and extend the existing `SourceShipmentService`, payment services, saved-address APIs, Leaflet picker, and Logistics shipment/proof system. Add one focused `RepairDeliveryService` for server-authoritative address snapshots, versions, quotes, locks, readiness checks, and proof lookup; derive Retail/Repair module from each shipment's existing source type, and enforce homogeneous two-or-more-stop batch construction inside the existing Logistics services without new module columns or repair-only dispatch code.

**Tech Stack:** Laravel 12, PHP 8.2+, MySQL/SQLite tests, Inertia React/TypeScript, Tailwind CSS, Leaflet, Vitest, PHPUnit.

---

## Verified Current Flow (2026-07-23)

The following is based on the current routes, controllers, React pages, and a passing focused backend run: **43 tests, 303 assertions**.

1. Customer opens `/repair-services`, selects a shop at `/repair-shop/{id}`, then opens `/repair-process?shop={id}`.
2. `RepairProcess.tsx` collects services/package, photos, contact details, intake choice, return choice, and policy acceptance.
3. Current intake choices are `walk_in` and `pickup`; the latter maps to `customer_delivery` and means the **customer** arranges a courier to the shop. It does not mean shop pickup.
4. Current return choices shown in booking are `walk_in` and `shop_delivery`, but `shop_delivery` currently means the repairer later enters third-party carrier/tracking fields manually.
5. `RepairRequestController::store` creates `new_request`, snapshots service pricing, notifies the shop, and auto-assigns the least-busy eligible Repairer. Success becomes `assigned_to_repairer`; no eligible Repairer becomes `assignment_failed`.
6. Repairer acceptance creates/reuses the conversation. Walk-in becomes `pending`; courier intake becomes `repairer_accepted` until customer confirmation changes it to `pending`.
7. Payment is `full_upfront` or `deposit_50`. A deposit permits intake/repair work; the remaining balance is due at `ready_for_pickup`. Payments may settle through PayMongo or Repair POS.
8. Staff/Repairer marks physical receipt: `pending → received`. Work then follows `received → in_progress`; it may pause at `awaiting_parts`, resume to `in_progress`, then become `completed → ready_for_pickup`.
9. There is no separate diagnosis or QA state. Material-plan validation is the existing completion gate and must remain intact.
10. Current non-walk-in return is manual: Repairer submits carrier/tracking, `shipRepair` sets `shipped` and creates a `repair_return` shipment, then activates customer receive confirmation. Customer confirmation sets terminal status `picked_up` and generates the invoice.
11. Walk-in return skips `shipped`; Staff/Repairer activation marks the repair `picked_up` directly after full payment.
12. Warranty approval creates one linked warranty RepairRequest and reuses the same operational lifecycle; service may be zero-cost.

### Integration boundaries

- Add shop-owned intake at acceptance/payment before physical receipt.
- Add shop-owned return at ready/payment/address-confirmation before customer receipt.
- Do not change repair assignment, conversation, material planning, rejection review, refund, invoice, or review workflows except where payment totals and delivery completion directly require it.

---

## File Map

**Create**

- `database/migrations/2026_07_23_000001_add_logistics_fields_to_repair_requests.php` — fees, versions, confirmation, locks, quote/reconciliation data, and `shop_pickup` enum support.
- `database/migrations/2026_07_23_000002_enforce_source_shipment_uniqueness.php` — one shipment per source/purpose.
- `database/migrations/2026_07_23_000003_create_repair_payment_sessions_table.php` — versioned PayMongo phase sessions so invalidated links remain auditable and late callbacks can reconcile safely.
- `app/Models/RepairPaymentSession.php` — persisted phase/session metadata and status.
- `app/Services/RepairDeliveryService.php` — address snapshots, hashes, coverage/fee quotes, lock rules, readiness checks, and proof checks.
- `resources/js/components/address/CustomerAddressManager.tsx` — reusable saved-address select/add/edit UI backed by the existing API and map picker.
- Focused backend/frontend tests named in each task.

**Modify**

- `app/Models/RepairRequest.php` — fillable/casts for new fields.
- `app/Http/Controllers/Api/RepairAvailabilityController.php` and `routes/web.php` — repair delivery quote endpoint.
- `app/Http/Controllers/Api/RepairRequestController.php` — booking snapshots, delivery edits/confirmation, customer tracking, payment-session validation, and receipt confirmation.
- `app/Http/Controllers/Api/RepairWorkflowController.php` — intake receipt gates, non-shop handoffs, ready trigger, and removal of manual carrier fields for shop-owned return.
- `app/Services/PaymentSettlementService.php`, `app/Services/RepairPosPaymentService.php`, `app/Http/Controllers/Api/RepairPosController.php`, `app/Http/Controllers/PaymongoWebhookController.php`, and `routes/api.php` — componentized phase amounts, quote/version locks, zero-amount settlement, and readiness triggers.
- `app/Services/Logistics/SourceShipmentService.php` — coordinates, schedules, and concurrency-safe repair shipment creation.
- `resources/js/Pages/UserSide/Repairs/Repair.tsx`, `repairShow.tsx`, `RepairProcess.tsx`, and `myRepairs.tsx` — coverage-aware booking, address confirmation, fees, and tracking.
- `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx` and `resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx` — shared Logistics status and explicit handoff actions.
- `app/Models/Logistics/Shipment.php`, `app/Http/Controllers/Logistics/ErpLogisticsController.php`, `app/Http/Controllers/Api/Logistics/DeliveryBatchController.php`, `app/Services/Logistics/BatchSuggestionService.php`, and `app/Services/Logistics/BatchDispatchService.php` â€” derived module filtering plus homogeneous, minimum-two batch construction.
- `resources/js/Pages/ERP/Logistics/Batches.tsx`, `Shipments.tsx`, their focused components/types, and existing tracking UI â€” module filters/badges, batch-selection guidance, and missing source details.
- Warranty creation code and tests so linked jobs use the same snapshot/fee rules.

---

### Task 1: Persist versioned repair delivery data

**Files:**
- Create: `database/migrations/2026_07_23_000001_add_logistics_fields_to_repair_requests.php`
- Create: `database/migrations/2026_07_23_000002_enforce_source_shipment_uniqueness.php`
- Create: `database/migrations/2026_07_23_000003_create_repair_payment_sessions_table.php`
- Create: `app/Models/RepairPaymentSession.php`
- Modify: `app/Models/RepairRequest.php`
- Test: `tests/Feature/Repair/RepairLogisticsSchemaTest.php`

- [ ] **Step 1: Write failing schema/model tests**

Assert that RepairRequest supports:

```php
$repair->update([
    'intake_delivery_method' => 'shop_pickup',
    'intake_delivery_fee' => 128.00,
    'return_delivery_fee' => 142.00,
    'same_as_intake_address' => true,
    'return_address_confirmed_at' => now(),
    'return_address_confirmed_version' => 'return-v1',
    'intake_logistics_locked_at' => now(),
    'return_logistics_locked_at' => now(),
    'intake_logistics_quote' => ['version' => 'intake-v1', 'fee' => 128.00],
    'return_logistics_quote' => ['version' => 'return-v1', 'fee' => 142.00],
    'logistics_payment_reconciliation' => ['status' => 'pending'],
]);
```

Also assert a second shipment with the same `(source_type, source_id, purpose)` is rejected by the database.

Assert `repair_payment_sessions` stores a unique provider link ID, phase, status, snapshot version/method, service amount, delivery amount, quote JSON, invalidation timestamp, and resolution timestamp.

- [ ] **Step 2: Run the failing test**

Run: `php artisan test tests/Feature/Repair/RepairLogisticsSchemaTest.php`

Expected: FAIL because columns and unique constraint do not exist.

- [ ] **Step 3: Add the minimal schema**

Add decimal fee columns, boolean `same_as_intake_address`, timestamps, string confirmation version, and JSON quote/reconciliation columns. Expand `intake_delivery_method` to `walk_in`, `customer_delivery`, `shop_pickup`. Add the minimal payment-session table and relationship. Add the shipment unique index and abort migration with a clear message if pre-existing duplicates are detected; do not silently delete shipment history.

- [ ] **Step 4: Add fillable/casts and run tests**

Run: `php artisan test tests/Feature/Repair/RepairLogisticsSchemaTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_23_000001_add_logistics_fields_to_repair_requests.php database/migrations/2026_07_23_000002_enforce_source_shipment_uniqueness.php database/migrations/2026_07_23_000003_create_repair_payment_sessions_table.php app/Models/RepairRequest.php app/Models/RepairPaymentSession.php tests/Feature/Repair/RepairLogisticsSchemaTest.php
git commit -m "feat: persist repair delivery logistics state"
```

### Task 2: Add the server-authoritative address snapshot and quote service

**Files:**
- Create: `app/Services/RepairDeliveryService.php`
- Modify: `app/Http/Controllers/Api/RepairAvailabilityController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Repair/RepairDeliveryQuoteTest.php`

- [ ] **Step 1: Write failing ownership, pin, coverage, and fee tests**

Cover: owning saved address succeeds; foreign address returns 422; missing pin returns `address_needs_pin`; outside radius returns `outside_coverage`; in-radius quote uses the exact retail formula and charges `max_fee`; boundary distance and rounding match `ShippingEstimateService`.

- [ ] **Step 2: Run the failing test**

Run: `php artisan test tests/Feature/Repair/RepairDeliveryQuoteTest.php`

Expected: FAIL because the repair quote endpoint/service do not exist.

- [ ] **Step 3: Implement one focused service**

Required public contract:

```php
final class RepairDeliveryService
{
    public function snapshot(UserAddress $address, string $method): array;
    public function version(array $snapshot, string $method): string;
    public function quote(ShopOwner $shop, UserAddress $address): array;
    public function tryCreateIntakeShipment(RepairRequest $repair): ?Shipment;
    public function tryCreateReturnShipment(RepairRequest $repair): ?Shipment;
    public function hasApprovedProof(RepairRequest $repair, string $purpose): bool;
}
```

Snapshot only stable delivery fields: `address_id`, name, phone, structured address, latitude/longitude, delivery instructions, method, and SHA-256 `version`. `quote()` must call existing `DeliveryScheduleService::coverage()` and `ShippingEstimateService::calculate()`; do not copy either formula.

- [ ] **Step 4: Add the authenticated quote endpoint**

Add `GET /api/repair/shops/{shop}/delivery-quote?address_id={id}`. Resolve the address through `$request->user('user')->addresses()` so a forged ID never leaks another customer’s location.

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test tests/Feature/Repair/RepairDeliveryQuoteTest.php tests/Feature/Logistics/DeliveryScheduleServiceTest.php`

```bash
git add app/Services/RepairDeliveryService.php app/Http/Controllers/Api/RepairAvailabilityController.php routes/web.php tests/Feature/Repair/RepairDeliveryQuoteTest.php
git commit -m "feat: quote covered repair delivery addresses"
```

### Task 3: Reuse saved-address and Leaflet UI across Repair screens

**Files:**
- Create: `resources/js/components/address/CustomerAddressManager.tsx`
- Test: `resources/js/components/address/__tests__/CustomerAddressManager.test.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/Repair.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/repairShow.tsx`
- Test: `resources/js/Pages/UserSide/Repairs/__tests__/repairShopCoverageIntegration.test.tsx`

- [ ] **Step 1: Write failing component tests**

Verify keyboard-accessible selection, add/edit, loading/error states, pin requirement, and `onSelect(address)` after `/api/user/addresses` mutations. Reuse `CustomerAddressMapPicker`; add no map dependency.

- [ ] **Step 2: Implement the smallest reusable manager**

```ts
export type CustomerAddress = {
  id: number;
  name: string;
  phone: string;
  address_line: string;
  barangay: string;
  city: string;
  province: string;
  region: string;
  postal_code: string | null;
  latitude: number | null;
  longitude: number | null;
  delivery_instructions: string | null;
  is_default: boolean;
};
```

Use existing `/api/user/addresses` CRUD and Philippine location normalization. Do not duplicate geocoding; the map picker already handles it.

- [ ] **Step 3: Add coverage-aware shop selection**

Load the default address for authenticated customers, call the repair quote endpoint per visible shop, and render `Within coverage`, `Outside coverage`, or `Pin required`. Keep every shop clickable. Carry `address_id` through `/repair-shop/{id}` and `repair-process` query strings.

- [ ] **Step 4: Run tests and commit**

Run: `npm run test:frontend -- resources/js/components/address/__tests__/CustomerAddressManager.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShopCoverageIntegration.test.tsx`

```bash
git add resources/js/components/address/CustomerAddressManager.tsx resources/js/components/address/__tests__/CustomerAddressManager.test.tsx resources/js/Pages/UserSide/Repairs/Repair.tsx resources/js/Pages/UserSide/Repairs/repairShow.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShopCoverageIntegration.test.tsx
git commit -m "feat: show repair shop address coverage"
```

### Task 4: Save separate intake and return snapshots during booking

**Files:**
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Modify: `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx`
- Test: `tests/Feature/Repair/RepairAddressSnapshotTest.php`
- Test: `resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLogistics.test.tsx`

- [ ] **Step 1: Write failing backend tests**

Cover all combinations: `walk_in`, `customer_delivery`, `shop_pickup`; `walk_in`, `customer_pickup`, `shop_delivery`; `Same as intake`; separate return address; foreign address; outside coverage disabling only shop-owned; later saved-address edits not mutating stored snapshots. Assert `Same as intake` copies identical address fields without sharing a mutable record, while intake and return fingerprints remain distinct because their methods differ.

- [ ] **Step 2: Replace ambiguous booking payload fields**

Submit explicit IDs and methods:

```ts
submitFormData.append('intake_delivery_method', intakeMethod);
if (intakeAddressId) submitFormData.append('intake_address_id', String(intakeAddressId));
submitFormData.append('return_delivery_method', returnMethod);
submitFormData.append('same_as_intake_address', sameAsIntake ? '1' : '0');
if (returnAddressId) submitFormData.append('return_address_id', String(returnAddressId));
```

Keep legacy `service_type` input accepted server-side for old clients, but new UI must use explicit methods.

- [ ] **Step 3: Make booking easy to scan**

Use two cards: `Send shoes to shop` and `Return repaired shoes`. Show walk-in, shop-owned, and third-party choices. Display pin/coverage/distance/fee inline; disable only the unavailable shop-owned radio. Third-party copy and its frontend test must state that the customer arranges and pays the courier directly. Default `Same as intake` on, but allow a separate return address.

- [ ] **Step 4: Store authoritative snapshots and quotes**

Controller resolves saved addresses through the logged-in customer, calls `RepairDeliveryService`, stores versioned snapshots, sets shop-owned fees, and ignores client fee values.

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test tests/Feature/Repair/RepairAddressSnapshotTest.php tests/Feature/Policies/RepairPolicyAcceptanceTest.php`

Run: `npm run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLogistics.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLocationIntegration.test.ts`

```bash
git add app/Http/Controllers/Api/RepairRequestController.php resources/js/Pages/UserSide/Repairs/RepairProcess.tsx tests/Feature/Repair/RepairAddressSnapshotTest.php resources/js/Pages/UserSide/Repairs/__tests__/repairProcessLogistics.test.tsx
git commit -m "feat: capture repair intake and return addresses"
```

### Task 5: Split delivery fees across PayMongo and POS phases

**Files:**
- Modify: `app/Services/PaymentSettlementService.php`
- Modify: `app/Services/RepairPosPaymentService.php`
- Modify: `app/Models/RepairPaymentSession.php`
- Modify: `app/Http/Controllers/Api/RepairPosController.php`
- Modify: `app/Http/Controllers/PaymongoWebhookController.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Repair/RepairLogisticsPaymentTest.php`
- Test: `tests/Feature/RepairPosPaymentFlowTest.php`

- [ ] **Step 1: Write failing phase-allocation tests**

For `deposit_50`, assert initial due is `50% service + intake fee`, final due is `remaining service + return fee`. For `full_upfront`, charge service + intake fee initially and return fee only at ready. Verify zero-cost warranty still charges chosen delivery fees, and walk-in/third-party fee is zero.

- [ ] **Step 2: Revalidate and persist every payment session before creating it**

For both PayMongo creation and POS checkout, re-resolve address ownership, coordinates, current coverage, Logistics availability, fee, method, and snapshot version on the server. Reject before provider/POS side effects when any value is invalid. PayMongo creation writes a `RepairPaymentSession` containing the exact components/version/method before exposing its checkout URL; one pending session per repair phase is allowed.

- [ ] **Step 3: Centralize due calculation in existing payment services**

Use component values, not `grandTotal / 2`:

```php
$serviceDeposit = $policy === 'deposit_50' ? round($serviceTotal * 0.5, 2) : $serviceTotal;
$initialDue = $serviceDeposit + (float) $repair->intake_delivery_fee;
$finalDue = ($serviceTotal - $serviceDeposit) + (float) $repair->return_delivery_fee;
```

Store service and delivery components in PayMongo quote JSON and POS transaction metadata. Do not change VAT extraction behavior for the service component.

Update `isRepairSettled()` / due-phase checks so `full_upfront` means the service is fully paid but a non-zero return fee may still create a return-fee-only final phase. Release remains blocked until both service and required return delivery fee are settled.

- [ ] **Step 4: Validate version at settlement and retain invalidated callbacks**

PayMongo webhook resolves the persisted session by provider link ID, not only the mutable RepairRequest link. PayMongo and POS settlement compare link/phase/address version with the current repair snapshot. Match: mark session paid, lock the leg, and invoke readiness. Mismatch or an invalidated session paid late: apply the valid service component once, mark the session `reconciliation`, record only the stale delivery component in `logistics_payment_reconciliation`, block another phase payment, and create no shipment.

- [ ] **Step 5: Handle zero amount without a fake processor call**

Add a server transition that marks a zero due phase settled/locked and invokes readiness. Never create a PHP 1.00 minimum payment for a genuinely zero phase.

- [ ] **Step 6: Run tests and commit**

Run: `php artisan test tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/PaymentLifecycleFeatureTest.php tests/Feature/RepairPosPaymentFlowTest.php`

```bash
git add app/Services/PaymentSettlementService.php app/Services/RepairPosPaymentService.php app/Models/RepairPaymentSession.php app/Http/Controllers/Api/RepairPosController.php app/Http/Controllers/PaymongoWebhookController.php app/Http/Controllers/Api/RepairRequestController.php routes/api.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/RepairPosPaymentFlowTest.php
git commit -m "feat: allocate repair delivery fees by phase"
```

### Task 6: Automatically create the covered intake shipment

**Files:**
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Services/Logistics/SourceShipmentService.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `app/Services/PaymentSettlementService.php`
- Test: `tests/Feature/Repair/RepairLogisticsIntakeTest.php`
- Test: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`

- [ ] **Step 1: Write failing gate and concurrency tests**

Assert no shipment before acceptance or initial settlement; either event order creates one shipment once both are true; concurrent retries create one; current outside coverage creates none and starts compensation; third-party/walk-in never create Dispatcher shipments.

- [ ] **Step 2: Harden `ensureRepairInboundShipment`**

Within one DB transaction, lock the shop/repair row, find-or-create by source/purpose, copy coordinates/instructions/version plus the accepted intake fee and explicit current coverage result into the leg snapshots, call `DeliveryScheduleService::estimate`, and record schedule events like retail. Rate changes never reprice the accepted fee; current coverage may still block creation.

- [ ] **Step 3: Trigger one shared readiness method**

Call `tryCreateIntakeShipment()` after Repairer acceptance and matching initial settlement. It must return `null` without mutation when gates are incomplete and return the existing active shipment on retries. If the unique shipment was previously cancelled and its delivery fee successfully compensated, a later fresh paid plan reuses that shipment row by appending a new leg with a higher sequence and reactivating the shipment; cancelled legs remain immutable audit history.

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php`

```bash
git add app/Services/RepairDeliveryService.php app/Services/Logistics/SourceShipmentService.php app/Http/Controllers/Api/RepairWorkflowController.php app/Services/PaymentSettlementService.php tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php
git commit -m "feat: dispatch covered repair pickups automatically"
```

### Task 7: Enforce physical intake custody for every method

**Files:**
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx`
- Test: `tests/Feature/Repair/RepairIntakeHandoffTest.php`
- Test: `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx`

- [ ] **Step 1: Write failing authorization/state tests**

Shop-owned receipt requires same-shop authorized Staff/Repairer, expected repair state, and approved `repair_pickup` proof. Rider proof without Staff receipt leaves status unchanged. Cross-shop, wrong actor, premature, and replayed calls fail safely.

- [ ] **Step 2: Keep non-Dispatcher paths explicit**

`walk_in` and `customer_delivery` require Staff physical receipt but no Logistics proof. In the same transaction, set `intake_logistics_locked_at` so method/address/external tracking can no longer change. Customer-arranged tracking is customer-owned/read-only to Staff.

- [ ] **Step 3: Update Job Orders UI**

For shop pickup, show Logistics timeline/status and enable `Confirm physical receipt` only after approved proof. Keep existing payment and material gates. Remove manual rider/carrier inputs only for shop-owned legs.

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test tests/Feature/Repair/RepairIntakeHandoffTest.php tests/Feature/Repairer/RepairerWorkflowTest.php`

Run: `npm run test:frontend -- resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx`

```bash
git add app/Http/Controllers/Api/RepairWorkflowController.php resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx "resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx" tests/Feature/Repair/RepairIntakeHandoffTest.php resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
git commit -m "feat: confirm physical receipt of repair intake"
```

### Task 8: Confirm the latest return plan and dispatch automatically

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Services/Logistics/SourceShipmentService.php`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- Test: `tests/Feature/Repair/RepairLogisticsReturnTest.php`
- Test: `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx`

- [ ] **Step 1: Write failing return-version tests**

Changing an unlocked return address/method invalidates quote and confirmation. Confirmation binds to the exact version. While `Same as intake` is selected and both legs are unlocked, an intake edit refreshes return snapshot/version and invalidates its quote, confirmation, and affected pending payment session. Turning the option off detaches the return snapshot. After intake lock, return edits cannot mutate intake. Ready, final payment, and confirmation arriving in any order create exactly one covered shipment: if payment settles first, confirmation may still record the exact locked session version but may not edit it. Outside current coverage creates none and starts compensation. Server rejects every address/method edit after that leg's payment lock.

- [ ] **Step 2: Expand the customer delivery-plan route**

Keep the existing delivery-method endpoint for compatibility, but expand it into the owning customer's delivery-plan update for intake address/method, return address/method, and `same_as_intake_address`. It must lock the RepairRequest row, reject edits to a paid/locked leg, apply the live-link rules above, and invalidate only pending sessions whose phase fingerprint changed while retaining provider links for late-webhook reconciliation. Add `POST .../{id}/confirm-return-address`: while unlocked it confirms the current version; after payment lock it may confirm only when the unchanged current version equals the paid session version.

- [ ] **Step 3: Replace manual shop-owned shipping**

`shop_delivery` must not accept manual carrier/tracking in `shipRepair`. `tryCreateReturnShipment()` performs coverage recheck and shipment creation after `ready_for_pickup`, final settlement, and exact confirmation. It copies the accepted return fee and explicit current coverage result into the leg snapshots for audit without repricing. For a previously cancelled/compensated unique shipment, append a replacement leg and reactivate the same shipment row rather than inserting a duplicate. `customer_pickup` keeps customer-owned external tracking; walk-in stays at the shop.

- [ ] **Step 4: Build the My Repairs return card**

Show saved-address manager, `Same as intake`, coverage/distance/fee, `Confirm address & delivery`, final amount, and the existing Logistics tracking timeline. Lock controls only after successful matching payment. The frontend test must assert distinct intake and return timeline headings/events so one leg cannot overwrite or masquerade as the other.

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test tests/Feature/Repair/RepairLogisticsReturnTest.php tests/Feature/Repairer/RepairerWorkflowTest.php tests/Feature/Logistics/CustomerTrackingTest.php`

Run: `npm run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx`

```bash
git add routes/web.php app/Http/Controllers/Api/RepairRequestController.php app/Http/Controllers/Api/RepairWorkflowController.php app/Services/RepairDeliveryService.php app/Services/Logistics/SourceShipmentService.php resources/js/Pages/UserSide/Repairs/myRepairs.tsx tests/Feature/Repair/RepairLogisticsReturnTest.php resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
git commit -m "feat: dispatch confirmed repair returns"
```

### Task 9: Complete return custody and third-party tracking safely

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- Test: `tests/Feature/Repair/RepairReturnHandoffTest.php`

- [ ] **Step 1: Write failing completion tests**

Shop-owned return requires approved `repair_return` proof before the owning customer can confirm. Proof alone never changes RepairRequest to `picked_up`. Walk-in requires Staff release; customer pickup requires Staff handoff and customer confirmation. Staff release/handoff atomically sets `return_logistics_locked_at`; later method/address/external-tracking edits are rejected. Wrong customer/shop/state/replay leaves state unchanged.

- [ ] **Step 2: Add customer-owned external tracking**

Add a customer route that saves carrier/tracking only for `customer_delivery` intake or `customer_pickup` return while unlocked. The server checks the relevant lock timestamp on every write. Staff views it read-only and records receipt/handoff; it never creates a Logistics shipment.

- [ ] **Step 3: Preserve terminal behavior**

Successful customer receipt continues to set `picked_up`, generate the existing invoice once, and enable the existing review/warranty/refund behavior. Do not add a second terminal status.

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repairer/RepairerWorkflowTest.php tests/Feature/RepairOnlineRefundWorkflowTest.php`

```bash
git add routes/web.php app/Http/Controllers/Api/RepairRequestController.php app/Http/Controllers/Api/RepairWorkflowController.php resources/js/Pages/UserSide/Repairs/myRepairs.tsx resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx tests/Feature/Repair/RepairReturnHandoffTest.php
git commit -m "feat: secure repair return handoffs"
```

### Task 10: Handle coverage changes and payment reconciliation

**Files:**
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Services/PaymentSettlementService.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `app/Http/Controllers/Api/RepairRefundWorkflowController.php`
- Modify: `app/Services/NotificationService.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/ERP/Finance/refundApproval.tsx`
- Test: `tests/Feature/Repair/RepairDeliveryReconciliationTest.php`
- Test: `resources/js/Pages/ERP/Finance/__tests__/refundApproval.deliveryReconciliation.test.tsx`

- [ ] **Step 1: Write failing compensation tests**

Cover: paid fee then radius shrinks; authorized same-shop cancellation; rejection after pickup/in-transit; unstarted shipment cancellation; exact fee credited against outstanding service balance; otherwise original-channel refund; Finance-only resolution; unlock only after success; temporary shipment failure remains retryable; double cancellation/resolution is idempotent; customer and same-shop Finance notifications are emitted once.

- [ ] **Step 2: Add the explicit pre-pickup cancellation operation**

Add `POST /api/repairer/repairs/{id}/cancel-delivery-leg` for an authorized same-shop Staff/Repairer with `leg` (`intake` or `return`) and reason. `RepairWorkflowController` calls one `RepairDeliveryService` transaction that locks the repair/session/shipment, rejects if any relevant leg is `picked_up`, `in_transit`, `awaiting_proof_approval`, or `delivered`, cancels the unstarted active leg/shipment when present, records pending compensation, and leaves address/method locked. Temporary shipment-creation failure is not cancellation and remains retryable. After compensation unlocks the plan, a newly paid plan must append a replacement leg to the same unique shipment and reactivate it; never mutate or delete the cancelled leg.

- [ ] **Step 3: Reuse the existing refund/payment services**

Do not create another refund ledger. Store reconciliation state on RepairRequest, then call the existing PayMongo refund or repair POS refund path for the delivery component. Keep the service component applied exactly once.

- [ ] **Step 4: Add the smallest Finance action and notifications**

Expose only pending delivery-fee reconciliations scoped to the Finance user’s shop, with `Credit balance` and `Refund original channel`. Record resolver and timestamp in the JSON audit payload. On reconciliation creation, notify the owning customer and authorized Finance users in the same shop; on successful compensation, notify both again, unlock only the affected delivery leg, and permit a fresh quote/payment.

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/RepairOnlineRefundWorkflowTest.php tests/Feature/RepairPosRefundFlowTest.php`

Run: `npm run test:frontend -- resources/js/Pages/ERP/Finance/__tests__/refundApproval.deliveryReconciliation.test.tsx`

```bash
git add app/Services/RepairDeliveryService.php app/Services/PaymentSettlementService.php app/Http/Controllers/Api/RepairWorkflowController.php app/Http/Controllers/Api/RepairRefundWorkflowController.php app/Services/NotificationService.php routes/web.php resources/js/Pages/ERP/Finance/refundApproval.tsx resources/js/Pages/ERP/Finance/__tests__/refundApproval.deliveryReconciliation.test.tsx tests/Feature/Repair/RepairDeliveryReconciliationTest.php
git commit -m "feat: reconcile unavailable repair delivery fees"
```

### Task 11: Carry the same rules into warranty/rework jobs

**Files:**
- Modify: `app/Services/RepairWarrantyService.php`
- Modify: `app/Http/Controllers/Api/RepairWarrantyClaimController.php`
- Modify: `app/Http/Controllers/Api/RepairerWarrantyClaimController.php`
- Test: `tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php`
- Test: `tests/Feature/Repair/Warranty/RepairWarrantyEligibilityTest.php`

- [ ] **Step 1: Add failing warranty delivery tests**

Assert linked jobs copy the selected versioned intake/return snapshot, use coverage rules, charge delivery fees despite zero service cost, and never dispatch an outside-coverage shop-owned leg.

- [ ] **Step 2: Reuse normal repair creation/readiness**

Pass approved claims through `RepairDeliveryService`; do not create warranty-specific shipment or payment logic.

- [ ] **Step 3: Run tests and commit**

Run: `php artisan test tests/Feature/Repair/Warranty tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/Repair/RepairLogisticsReturnTest.php`

```bash
git add app/Services/RepairWarrantyService.php app/Http/Controllers/Api/RepairWarrantyClaimController.php app/Http/Controllers/Api/RepairerWarrantyClaimController.php tests/Feature/Repair/Warranty
git commit -m "feat: apply repair logistics to warranty jobs"
```

### Task 12: Filter Dispatcher queues and enforce valid batch composition

**Files:**
- Modify: `app/Models/Logistics/Shipment.php`
- Modify: `app/Models/ShopOwner.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `app/Http/Controllers/Api/Logistics/DeliveryBatchController.php`
- Modify: `app/Services/Logistics/BatchDispatchService.php`
- Modify: `app/Services/Logistics/BatchSuggestionService.php`
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchCard.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`
- Test: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Test: `tests/Feature/Logistics/DeliveryBatchApiTest.php`
- Test: `tests/Feature/Logistics/BatchDispatchServiceTest.php`
- Test: `tests/Feature/Logistics/BatchSuggestionServiceTest.php`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Add failing backend module-filter tests**

In `LogisticsPageAccessTest.php`, create Retail (`order`, `order_refund`) and Repair (`repair_request`) shipments for the same shop. Assert:

```php
$response = $this->actingAs($dispatcher, 'user')->get('/erp/logistics/shipments?module=repair');
$response->assertInertia(fn (Assert $page) => $page
    ->where('filters.module', 'repair')
    ->has('shipments.data', 1)
    ->where('shipments.data.0.source_type', 'repair_request'));
```

Also prove module composes with status, purpose, delivery window, and pagination; a mismatched module/purpose returns no cross-module rows; the Batches page applies its module filter to `batches`, `pool`, and `unscheduled`, while its date/window filter applies only to already-scheduled batches/pool and deliberately leaves null-window unscheduled deliveries available; `both (retail & repair)` shops see `all|retail|repair`; and retail-only/repair-only shops are forcibly scoped to their module even if a conflicting query string is supplied. Seed one legacy mixed-module batch: it remains visible under `All` with a neutral `Mixed (legacy)` label, but an all-legs constraint excludes it from both Retail and Repair filters so cross-module stops never leak into either filtered view.

- [ ] **Step 2: Run the page tests to verify they fail**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php`

Expected: FAIL because `filters.module`, business-type scoping, and module-aware batch collections do not exist.

- [ ] **Step 3: Add the smallest shared source-to-module mapping and backend filters**

In `Shipment.php`, centralize the existing source mapping without persisting another field:

```php
public static function moduleForSourceType(string $sourceType): ?string
{
    return match ($sourceType) {
        'order', 'order_refund' => 'retail',
        'repair_request' => 'repair',
        default => null,
    };
}

public static function sourceTypesForModule(string $module): array
{
    return match ($module) {
        'retail' => ['order', 'order_refund'],
        'repair' => ['repair_request'],
        default => [],
    };
}
```

Add one `ShopOwner::logisticsModules(): array` method that normalizes the stored values (`retail`, `repair`, `both`, and `both (retail & repair)`) to `['retail']`, `['repair']`, or `['retail', 'repair']`. This is the shared authorization boundary used by both page and API/service code.

In `ErpLogisticsController`, accept `module=all|retail|repair` only for a shop whose `logisticsModules()` contains both values, and force a single-module shop to its permitted value. Add `window=all|morning|afternoon` to the Dispatcher Shipments and Batches queries. Apply `whereIn('source_type', Shipment::sourceTypesForModule($module))` before shipment pagination and use `whereHas('legs', fn ($legs) => $legs->where('delivery_window', $window))` for its window filter.

For Batches, apply module using both `whereHas` for the selected source types and `whereDoesntHave` for every other/unknown source type; this all-legs rule prevents a legacy mixed batch from appearing under Retail or Repair. Apply the selected date/window at the query level to already-scheduled batches and pool legs. Apply module, but not date/window, to `unscheduled`: those legs have no selected slot yet and must remain available for the dispatcher to schedule into the active slot. `All` keeps legacy batches readable and labels a batch whose loaded legs derive to multiple/unknown modules as `Mixed (legacy)`. Pass `filters.module`, `filters.window`, `availableModules`, and `showModuleFilter`; do not filter already-assigned Rider work or customer historical tracking pages.

- [ ] **Step 4: Add failing API/service tests for batch invariants**

Update existing happy-path fixtures in `DeliveryBatchApiTest.php` to use two eligible legs, then add tests that assert HTTP 422 and unchanged rows for:

```php
$this->postJson('/api/logistics/batches', ['leg_ids' => [$retailLeg->id], /* date/window */])
    ->assertJsonValidationErrors('leg_ids');

$this->postJson('/api/logistics/batches', ['leg_ids' => [$retailLeg->id, $repairLeg->id], /* date/window */])
    ->assertUnprocessable();
```

In the service tests, cover `createDraft`, `replaceStops`, and `restore`: fewer than two, mixed-module legs, or a homogeneous module disallowed by the shop's `business_type` must fail before settings creation, batch creation, leg assignment, stop snapshot/event creation, or other mutation. Also prove `removeStop` may reduce a valid two-stop draft to one, that the remaining batch can still be offered/operated, and that pre-existing historical single-stop batches remain readable.

Preserve the existing delivery-scheduling endpoint at `min:1`: scheduling one leg is not creating a batch and is also used from the Shipments page. It must still atomically reject an unknown source or any leg whose derived module is not in `ShopOwner::logisticsModules()` before settings or leg mutation; a `both` shop may schedule Retail and Repair legs together because homogeneity is enforced only when a batch is created. Add a regression where the Batches UI selects one already-scheduled plus one unscheduled compatible leg; scheduling the one unscheduled leg succeeds, then `createDraft` receives and validates the full two-leg selection.

In `DeliveryBatchApiTest.php`, also cover `GET /api/logistics/batches` and suggestions with `module=all|retail|repair`: both-shops may select either module; a single-module shop cannot retrieve or suggest the other module even with a crafted request.

In `BatchSuggestionServiceTest.php`, prove Retail and Repair legs never appear in the same suggestion, a shop-disallowed module is omitted, and no suggestion is returned with fewer than two legs.

- [ ] **Step 5: Run batch tests to verify they fail**

Run: `php artisan test tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/BatchSuggestionServiceTest.php`

Expected: FAIL because batch store/update validation uses `min:1`, suggestions share one source pool, and batch construction methods do not reject mixed modules.

- [ ] **Step 6: Enforce the batch rules inside the transaction boundary**

Change `leg_ids` validation to `min:2` for batch store and full stop replacement. Keep the delivery-scheduling endpoint at `min:1`, because it only assigns a slot and does not create a batch; `createDraft` remains authoritative over the complete selected set after any unscheduled subset is scheduled. Move scheduling's settings lookup/date validation into its existing database transaction, load and lock every requested leg with `shipment`, and reject unknown or shop-disallowed derived modules before `firstOrCreate()` or leg updates. Do not require scheduled legs to share a module. Keep `remove` unchanged so an operational removal may leave one stop. Make the batch API index/suggestions accept a validated module, resolve it against `ShopOwner::logisticsModules()`, and apply the same all-legs module constraint used by the Inertia page.

After locking and loading legs with `shipment`, but before settings creation or any mutation, make `BatchDispatchService::createDraft`, `replaceStops`, and `restore` enforce:

```php
if ($legs->count() < 2) {
    throw ValidationException::withMessages(['legs' => 'A batch requires at least two deliveries.']);
}

$modules = $legs->map(fn ($leg) => Shipment::moduleForSourceType($leg->shipment->source_type));
if ($modules->contains(null) || $modules->unique()->count() !== 1) {
    throw ValidationException::withMessages(['legs' => 'Retail and Repair deliveries cannot share a batch.']);
}

if (!in_array($modules->first(), $shop->logisticsModules(), true)) {
    throw ValidationException::withMessages(['legs' => 'This shop cannot dispatch that delivery module.']);
}
```

For `replaceStops`/`restore`, resolve the batch's locked `ShopOwner` and apply the same allowed-module check. Load `shipment` in `replaceStops`, and validate restored snapshot legs before reattaching any of them. Do not add a database module column, split batch tables, or block later `removeStop`/operational cancellation from leaving one remaining stop.

Partition `BatchSuggestionService` candidates by `Shipment::moduleForSourceType(...)`, discard unknown or shop-disallowed modules, apply the requested module when present, route each group independently, cap it to the rider capacity, and discard a candidate when its final `leg_ids` count is below two.

- [ ] **Step 7: Run focused backend tests**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/BatchSuggestionServiceTest.php`

Expected: PASS; invalid requests leave batches, legs, assignments, events, and scheduling fields unchanged.

- [ ] **Step 8: Add failing Dispatcher UI tests**

In `Shipments.test.tsx`, assert a `both` shop sees the Module selector, changing it requests the same page with `module=repair` while retaining compatible status/purpose filters, Repair exposes only Repair Pickup/Return purposes, and a single-module shop does not see a redundant selector.

In `Batches.test.tsx`, assert a `both` shop can switch `All|Retail|Repair` and sends the selected module/window to the backend while single-module shops hide and force the selector. Assert unscheduled/null-window deliveries remain selectable for the active slot; Retail/Repair badges appear on batch cards/stops; a legacy mixed batch uses `Mixed (legacy)`; selecting a leg disables incompatible-module legs; the create action stays disabled at zero or one selected leg with `Select at least 2 deliveries`; one scheduled plus one unscheduled leg schedules only the unscheduled subset and then creates the batch from both IDs; and a backend validation message remains visible if a stale or crafted request is rejected.

- [ ] **Step 9: Run frontend tests to verify they fail**

Run: `npm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: FAIL because the Module selector/badges and two-stop selection guidance do not exist.

- [ ] **Step 10: Implement the minimal Dispatcher controls**

Add `LogisticsModule = 'retail' | 'repair'` and derive the display module from `shipment.source_type` in the existing TypeScript types/helpers. Use one compact `Module` selector on each page only when `showModuleFilter` is true, and make the existing Batches date/window controls reload backend-filtered scheduled data while keeping unscheduled deliveries in the picker. Add a morning/afternoon Dispatcher window filter to Shipments. Keep the existing Purpose filter, but show only purposes valid for the selected/scoped module. Render a small Retail/Repair badge on shipment rows, batch cards, and stops, with `Mixed (legacy)` only for an old mixed/unknown batch shown under All.

In `AvailableDeliveriesPanel`, once the first delivery is selected, disable deliveries from the other module with an explanatory label. Disable only the batch-creation action until at least two compatible deliveries are selected; do not disable independent single-delivery scheduling on the Shipments page. When the selected set mixes scheduled and unscheduled legs, keep the existing two-stage save: schedule the unscheduled subset (which may contain one leg), then create the draft with every selected ID. Treat these as usability guidance only; display server 422 errors because backend validation remains authoritative.

- [ ] **Step 11: Run all focused tests and commit**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/BatchSuggestionServiceTest.php`

Run: `npm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

```bash
git add app/Models/Logistics/Shipment.php app/Models/ShopOwner.php app/Http/Controllers/Logistics/ErpLogisticsController.php app/Http/Controllers/Api/Logistics/DeliveryBatchController.php app/Services/Logistics/BatchDispatchService.php app/Services/Logistics/BatchSuggestionService.php resources/js/types/logistics.ts resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/components tests/Feature/Logistics resources/js/Pages/ERP/Logistics/__tests__
git commit -m "feat: filter logistics modules and validate batches"
```

### Task 13: Polish shared Dispatcher/tracking labels and run the full gate

**Files:**
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `app/Services/Logistics/CustomerTrackingService.php`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx`
- Test: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Test: `tests/Feature/Logistics/CustomerTrackingTest.php`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Test: `resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`

- [ ] **Step 1: Add one failing display test**

Require `Repair Pickup` / `Repair Return`, repair request number, customer, and shoe summary in ERP shipment/batch props and the customer tracking payload. Keep the current generic shipment/batch actions.

- [ ] **Step 2: Add source presentation only**

Have `ErpLogisticsController` eager-load/present the repair source summary needed by Shipments/Batches, and extend `CustomerTrackingService::payload()` for customer tracking. Do not put presentation work in `Api\Logistics\ShipmentController`, fork Dispatcher pages, or add repair-only actions.

- [ ] **Step 3: Run focused frontend tests**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php tests/Feature/Logistics/CustomerTrackingTest.php`

Run: `npm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx resources/js/Pages/UserSide/Repairs/__tests__ resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx`

- [ ] **Step 4: Run full backend regression**

Run: `php artisan test tests/Feature/Repairer tests/Feature/Repair tests/Feature/Customer/RepairServiceModificationTest.php tests/Feature/PaymentLifecycleFeatureTest.php tests/Feature/Logistics`

Expected: all tests pass; no reduction from the current focused baseline of 43 tests / 303 assertions.

- [ ] **Step 5: Build production assets**

Run: `npm run build`

Expected: Vite exits 0 with no TypeScript/build errors.

- [ ] **Step 6: Manual role-based E2E pass**

Use a clean test repair for each method:

1. Customer books shop pickup + same-address shop return.
2. Repairer accepts; payment settles; Dispatcher assigns eligible Rider.
3. Rider pickup proof → Dispatcher approval → Staff physical receipt.
4. Repairer starts, completes, and marks ready.
5. Customer confirms latest return address and pays final due.
6. Rider delivery proof → Dispatcher approval → customer confirms receipt.
7. Confirm terminal `picked_up`, invoice, review availability, and both tracking timelines.
8. Repeat outside coverage: shop-owned disabled, third-party/walk-in usable, no shipment.
9. Repeat Rider on leave/off-schedule: assignment/offer remains blocked by existing Logistics guards.
10. Repeat warranty: zero service charge, delivery fees still gated and dispatched correctly.

- [ ] **Step 7: Commit final UI/regression adjustments**

```bash
git add app/Http/Controllers/Logistics/ErpLogisticsController.php app/Services/Logistics/CustomerTrackingService.php resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx tests/Feature/Logistics/LogisticsPageAccessTest.php tests/Feature/Logistics/CustomerTrackingTest.php resources/js/Pages/ERP/Logistics/__tests__ resources/js/Pages/UserSide/Tracking/__tests__
git commit -m "feat: label repair logistics across tracking"
```

---

## Explicitly Reused, Not Rebuilt

- `UserAddressController` and `user_addresses` for saved addresses.
- `CustomerAddressMapPicker` for Leaflet search, GPS, pinning, and reverse geocoding.
- `DeliveryScheduleService` for coverage and scheduling.
- `ShippingEstimateService` for the retail distance fee formula.
- `SourceShipmentService`, Shipment/Leg, batching, assignment, Rider leave/schedule guards, proof approval/rejection, incidents, and customer tracking.
- Existing repair assignment, material planning, payment ledgers, invoice, refund, warranty, and review flows.

No new delivery provider, map library, repair-only Dispatcher, or speculative diagnosis/QA state is included.
