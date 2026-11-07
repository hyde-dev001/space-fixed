# Xendit Supplier Recipient Profile Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Support valid Xendit payouts to business and individual suppliers while keeping supplier dialogs usable at 100% browser zoom.

**Architecture:** Extend the verified supplier payment profile as the single source for recipient identity, address, and account details. Copy that complete profile into the encrypted payment-attempt snapshot, then build the Xendit recipient dynamically from the snapshot. Reuse the existing Add Supplier modal layout pattern for Edit and View, and extract only the duplicated payment-profile fields into one focused React component.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent encrypted casts, PHPUnit, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library, Vite 7.

**Design:** `docs/superpowers/specs/2026-09-16-xendit-supplier-recipient-profile-design.md`

---

### Task 1: Add the recipient data contract

**Files:**
- Create: `database/migrations/2026_09_16_000001_add_xendit_recipient_fields_to_supplier_payment_profiles.php`
- Modify: `app/Models/SupplierPaymentProfile.php`
- Test: `tests/Feature/Finance/SupplierPaymentProfileTest.php`

- [ ] **Step 1: Write a failing model/profile serialization test**

Add a test that creates one business profile and one individual profile, then asserts `toMaskedArray()` exposes the selected recipient type, applicable identity fields, and complete address without exposing account identifiers.

```php
$business = SupplierPaymentProfile::create([
    ...$this->validBusinessProfile($shop, $supplier),
    'account_number' => '12345678',
]);

$this->assertSame('business', $business->toMaskedArray()['recipient_type']);
$this->assertSame('Test Supplier PH', $business->toMaskedArray()['business_name']);
$this->assertArrayNotHasKey('account_number', $business->toMaskedArray());
```

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php artisan test tests/Feature/Finance/SupplierPaymentProfileTest.php --filter=recipient`

Expected: FAIL because the recipient columns/constants and serialized fields do not exist.

- [ ] **Step 3: Add the migration and minimal model fields**

Add nullable legacy-safe columns for `recipient_type`, business/individual identity, and recipient address. Set the database default for `recipient_type` to `business`. Backfill only unambiguous values from `suppliers` (`name`, `address`, `city`, normalized country), then set every existing profile to `unverified`; do not invent province/state or postal codes.

Add model constants and allowlisted fillable/serialized fields:

```php
public const RECIPIENT_BUSINESS = 'business';
public const RECIPIENT_INDIVIDUAL = 'individual';
public const RECIPIENT_TYPES = [self::RECIPIENT_BUSINESS, self::RECIPIENT_INDIVIDUAL];
```

Keep `account_number` and `account_identifier` encrypted and hidden.

- [ ] **Step 4: Run the focused profile tests and verify GREEN**

Run: `php artisan test tests/Feature/Finance/SupplierPaymentProfileTest.php --filter=recipient`

Expected: PASS.

- [ ] **Step 5: Commit the data contract**

```bash
git add -- database/migrations/2026_09_16_000001_add_xendit_recipient_fields_to_supplier_payment_profiles.php app/Models/SupplierPaymentProfile.php tests/Feature/Finance/SupplierPaymentProfileTest.php
git commit -m "feat: add xendit supplier recipient profiles"
```

### Task 2: Enforce conditional profile validation and verification lifecycle

**Files:**
- Modify: `app/Http/Requests/StoreSupplierPaymentProfileRequest.php`
- Modify: `app/Http/Controllers/Erp/SupplierController.php`
- Modify: `app/Http/Controllers/Api/Finance/ProcurementExpenseController.php`
- Test: `tests/Feature/Finance/SupplierPaymentProfileTest.php`

- [ ] **Step 1: Write failing request and lifecycle tests**

Cover these cases separately:

- Business requires `business_name` and prohibits `given_name`/`surname`.
- Individual requires `given_name` and `surname` and prohibits `business_name`.
- Both require ISO alpha-2 country, province/state, city, street line 1, and postal code.
- A new profile defaults to Business when the frontend sends the explicit default.
- Updating any recipient identity or address field resets a verified profile to `unverified`.
- Finance cannot verify a legacy/incomplete profile.
- Leaving the encrypted account field blank during edit preserves the stored value.

- [ ] **Step 2: Run the profile tests and verify RED**

Run: `php artisan test tests/Feature/Finance/SupplierPaymentProfileTest.php`

Expected: FAIL on missing conditional rules and unchanged verification state.

- [ ] **Step 3: Implement the minimum validation and controller mapping**

Extend `profileRules()` with a strict recipient allowlist and conditional rules:

```php
'recipient_type' => ['required', Rule::in(SupplierPaymentProfile::RECIPIENT_TYPES)],
'business_name' => ['nullable', 'string', 'max:50', 'required_if:recipient_type,business', 'prohibited_if:recipient_type,individual'],
'given_name' => ['nullable', 'string', 'max:50', 'required_if:recipient_type,individual', 'prohibited_if:recipient_type,business'],
'surname' => ['nullable', 'string', 'max:50', 'required_if:recipient_type,individual', 'prohibited_if:recipient_type,business'],
'recipient_country' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
'recipient_province_state' => ['required', 'string', 'max:255'],
'recipient_city' => ['required', 'string', 'max:255'],
'recipient_street_line_1' => ['required', 'string', 'max:255'],
'recipient_street_line_2' => ['nullable', 'string', 'max:255'],
'recipient_postal_code' => ['required', 'string', 'max:32'],
```

Pass `recipient_type` into nested profile validation during supplier creation. Normalize `recipient_country` to uppercase before persistence. Include all recipient fields in the existing change comparison so they invalidate Finance verification. Add a model completeness method used by Finance verification to reject incomplete legacy data.

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run: `php artisan test tests/Feature/Finance/SupplierPaymentProfileTest.php`

Expected: PASS with no raw account values in JSON assertions.

- [ ] **Step 5: Commit profile validation**

```bash
git add -- app/Http/Requests/StoreSupplierPaymentProfileRequest.php app/Http/Controllers/Erp/SupplierController.php app/Http/Controllers/Api/Finance/ProcurementExpenseController.php app/Models/SupplierPaymentProfile.php tests/Feature/Finance/SupplierPaymentProfileTest.php
git commit -m "feat: validate xendit supplier recipients"
```

### Task 3: Build dynamic Xendit recipients from immutable snapshots

**Files:**
- Modify: `app/Services/Finance/SupplierPaymentService.php`
- Modify: `app/Services/Finance/XenditPayoutService.php`
- Test: `tests/Feature/Finance/XenditSupplierPayoutTest.php`
- Test: `tests/Feature/Finance/SupplierManualPaymentTest.php`

- [ ] **Step 1: Write failing business and individual payout tests**

Assert the outbound request for Business contains:

```php
'recipient' => [
    'type' => 'BUSINESS',
    'business_name' => 'Test Supplier PH',
    'relationship' => 'SUPPLIER',
]
```

Assert the Individual request contains `type = INDIVIDUAL`, `given_name`, and `surname`, excludes `business_name`, and sends the same required address/account structure. In both cases, mutate the profile after attempt creation and assert the request still uses the encrypted attempt snapshot.

- [ ] **Step 2: Run the Xendit tests and verify RED**

Run: `php artisan test tests/Feature/Finance/XenditSupplierPayoutTest.php`

Expected: FAIL because the service still hardcodes `BUSINESS` and snapshots omit recipient fields.

- [ ] **Step 3: Extend the destination snapshot and payload**

Make `destinationSnapshot()` copy the verified recipient type, applicable identity fields, complete address, and existing account data. In `XenditPayoutService`, build the recipient identity conditionally from the snapshot and always add:

```php
'relationship' => 'SUPPLIER',
'address' => [
    'country' => $country,
    'province_state' => $provinceState,
    'city' => $city,
    'street_line_1' => $streetLine1,
    'street_line_2' => $streetLine2,
    'postal_code' => $postalCode,
],
```

Do not fall back to mutable Supplier identity/address data during payout creation.

- [ ] **Step 4: Write a failing safe-diagnostic test**

Fake an `API_VALIDATION_ERROR` containing one safe path plus an account number and secret-like value. Assert the API response identifies only the rejected field path and contains neither sensitive value.

- [ ] **Step 5: Run the diagnostic test and verify RED**

Run: `php artisan test tests/Feature/Finance/XenditSupplierPayoutTest.php --filter=validation`

Expected: FAIL because only the generic provider code is currently returned.

- [ ] **Step 6: Add minimal field-path diagnostics**

Extract only provider error `path` values matching a conservative path allowlist. Return at most the first safe path in the Finance message and log only the provider code/path. Do not return provider values or raw `errors` messages. Preserve the existing timeout, 429, 5xx, and idempotency behavior.

- [ ] **Step 7: Run payout tests and verify GREEN**

Run: `php artisan test tests/Feature/Finance/XenditSupplierPayoutTest.php tests/Feature/Finance/SupplierManualPaymentTest.php`

Expected: PASS.

- [ ] **Step 8: Commit payout behavior**

```bash
git add -- app/Services/Finance/SupplierPaymentService.php app/Services/Finance/XenditPayoutService.php tests/Feature/Finance/XenditSupplierPayoutTest.php tests/Feature/Finance/SupplierManualPaymentTest.php
git commit -m "fix: send valid xendit supplier recipients"
```

### Task 4: Add the shared recipient form and Finance review details

**Files:**
- Create: `resources/js/Pages/ERP/Procurement/components/SupplierPaymentProfileFields.tsx`
- Modify: `resources/js/types/procurement.ts`
- Modify: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`
- Modify: `resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx`
- Test: `resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx`

- [ ] **Step 1: Write failing frontend tests**

Verify Business is selected by default, toggling to Individual swaps the required identity fields, supplier address prefills a new profile without silently submitting missing province/postal values, edit loads all masked profile metadata, and both payload shapes contain the complete recipient address.

Verify Finance sees recipient type, recipient name, and masked destination before verification.

- [ ] **Step 2: Run focused frontend tests and verify RED**

Run: `node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx`

Expected: FAIL because the types and controls do not exist.

- [ ] **Step 3: Extend TypeScript contracts**

Add `SupplierRecipientType`, the identity/address fields to `SupplierPaymentProfile`, and required fields to `UpsertSupplierPaymentProfilePayload`. Keep account identifiers optional for edit-preserve behavior.

- [ ] **Step 4: Implement one reusable payment-profile field component**

Move only the duplicated Add/Edit payment profile controls into `SupplierPaymentProfileFields.tsx`. It receives form state, change handlers, reveal state, and edit metadata. Keep the supplier modal shells in `SuppliersManagement.tsx`.

Use native inputs/selects, Business as the initial type, and conditional Business/Individual fields. Include complete recipient address fields and existing bank/e-wallet controls.

- [ ] **Step 5: Update page state, prefill, and submissions**

Prefill a new payment profile from unambiguous supplier fields (`name`, `address`, `city`, normalized country), leaving province/state and postal code for explicit entry. Ensure changing recipient or destination type clears fields that are prohibited for the new selection. Submit no payment profile when the optional section is untouched; once any profile field is entered, submit the complete validated shape.

- [ ] **Step 6: Show safe review data in Finance**

Add recipient type/name and formatted masked recipient address to the existing Supplier Payment Profile review block. Do not expose full account data without the existing audited reveal action.

- [ ] **Step 7: Run focused tests and verify GREEN**

Run: `node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx`

Expected: PASS.

- [ ] **Step 8: Commit recipient UI**

```bash
git add -- resources/js/types/procurement.ts resources/js/Pages/ERP/Procurement/components/SupplierPaymentProfileFields.tsx resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx
git commit -m "feat: collect xendit supplier recipient details"
```

### Task 5: Keep every Supplier modal inside the viewport

**Files:**
- Modify: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`
- Test: `resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`

- [ ] **Step 1: Write failing modal structure tests**

Open Add, View, and Edit independently. For each modal, assert the dialog container has a viewport-relative maximum height and `overflow-hidden`, its body has `min-h-0 flex-1 overflow-y-auto`, and its header/footer are shrink-resistant.

- [ ] **Step 2: Run the modal tests and verify RED**

Run: `node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`

Expected: FAIL for View and Edit; Add already provides the target pattern.

- [ ] **Step 3: Reuse the Add modal layout classes**

Apply the existing bounded flex-column shell to View and Edit:

```text
max-h-[calc(100dvh-2rem)] sm:max-h-[calc(100dvh-3rem)]
```

Keep header/footer outside the scroll region and wrap each modal's content in a single `min-h-0 flex-1 overflow-y-auto` body. Add `role="dialog"`, `aria-modal="true"`, and heading linkage while touching the shells.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`

Expected: PASS.

- [ ] **Step 5: Commit the modal fix**

```bash
git add -- resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx
git commit -m "fix: contain supplier modals in viewport"
```

### Task 6: Run regression, security, and build gates

**Files:**
- Modify if required by test fixtures only: `tests/Feature/Finance/*.php`
- Modify if required by test fixtures only: `tests/Feature/Procurement/*.php`

- [ ] **Step 1: Update only broken verified-profile fixtures**

Where tests manually create verified profiles, add complete valid recipient identity/address data. Do not loosen production validation to preserve obsolete fixtures.

- [ ] **Step 2: Run backend regression tests**

Run:

```bash
php artisan test tests/Feature/Finance/SupplierPaymentProfileTest.php tests/Feature/Finance/XenditSupplierPayoutTest.php tests/Feature/Finance/SupplierManualPaymentTest.php tests/Feature/Finance/ProcurementExpenseReleaseTest.php
```

Expected: PASS.

- [ ] **Step 3: Run frontend regression tests**

Run:

```bash
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx resources/js/Pages/ERP/Finance/__tests__/SupplierPaymentDialog.test.tsx
```

Expected: PASS.

- [ ] **Step 4: Run security and diff hygiene checks**

Inspect the diff for secrets, unmasked account data, unsafe provider diagnostics, cross-shop access, and accidental raw snapshots. Run: `git diff --check`.

Expected: no output.

- [ ] **Step 5: Build production assets**

Run: `pnpm run build` (or the repository's available npm wrapper invoking the same `vite build` script).

Expected: Vite exits 0 and writes a fresh `public/build/manifest.json`. Stage generated build artifacts only if the user explicitly requests them for deployment.

- [ ] **Step 6: Commit fixture-only follow-up if needed**

```bash
git add -- tests/Feature/Finance tests/Feature/Procurement
git commit -m "test: update supplier recipient fixtures"
```

- [ ] **Step 7: Final branch verification**

Run: `git status --short --branch` and `git log --oneline -6`.

Expected: no uncommitted source changes; branch contains the focused implementation commits.
