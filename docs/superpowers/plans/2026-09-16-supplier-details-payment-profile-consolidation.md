# Supplier Details and Payment Profile Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove duplicate recipient identity/address inputs from the supplier payment section while preserving the existing supplier and Xendit payout workflow.

**Architecture:** Make the main supplier form the single UI source for recipient type, identity, and address. Keep the existing `SupplierPaymentProfile` persistence and API contract, mapping the shared supplier fields into the profile payload; leave only payout destination fields in the payment component. Existing profiles hydrate the unified form on edit, with legacy supplier fields as fallbacks.

**Tech Stack:** Laravel 12, React 18, TypeScript, Inertia, Tailwind CSS, Vitest, PHPUnit.

---

### Task 1: Lock the consolidated form contract with a frontend regression test

**Files:**
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`

- [ ] Add assertions that Add Supplier renders one `Supplier Details` group and one `Payment Destination` group.
- [ ] Assert recipient type and identity/address fields are inside Supplier Details and absent from Payment Destination.
- [ ] Assert Business → Individual switches the top identity fields and clears stale identity values in the submitted profile payload.
- [ ] Run the focused Vitest file and confirm the new assertions fail against the current duplicated layout.

### Task 2: Make Supplier Details the canonical form section

**Files:**
- Modify: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`

- [ ] Extend the local form state with recipient type, individual identity, address line 2, province/state, and postal code.
- [ ] Render recipient type and conditional Business/Individual identity fields in Supplier Details.
- [ ] Render the complete recipient address once, while retaining existing contact and procurement fields.
- [ ] Hydrate unified fields from an existing payment profile on edit and fall back to legacy supplier columns when no profile values exist.
- [ ] Derive the Individual supplier display name from given name and surname and retain the required `supplier.name` API field.
- [ ] Build the existing payment-profile request by combining Supplier Details with destination-only Payment Profile state.
- [ ] Sync an existing payment profile when identity/address changes, while preserving blank-account-number behavior for saved accounts.

### Task 3: Reduce Payment Profile to payout destination fields

**Files:**
- Modify: `resources/js/Pages/ERP/Procurement/components/SupplierPaymentProfileFields.tsx`

- [ ] Remove recipient type, recipient identity, and recipient address controls.
- [ ] Keep destination type, bank/e-wallet destination fields, account name, and account identifier controls.
- [ ] Preserve masked-account handling, show/hide behavior, labels, keyboard focus, and responsive layout.

### Task 4: Verify behavior and hygiene

**Files:**
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx` if existing expectations need alignment.

- [ ] Run the focused frontend test until green.
- [ ] Run the related frontend test suite and `npm run build` (or the repository's pnpm equivalent if available).
- [ ] Run `git diff --check` and relevant Laravel feature tests for supplier payment profiles.
- [ ] Review the diff for stale duplicate fields, unsafe account exposure, and unintended payment-workflow changes.
