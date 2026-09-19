# COD Refund Destination Channels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Execute this plan inline with test-first checkpoints.

**Goal:** Replace the COD refund destination free-text bank/GCash flow with live Bank Account and E-wallet selectors backed by the shop's Xendit payout channels.

**Architecture:** Reuse the existing `XenditPayoutService` channel normalizer. Add a refund-scoped customer endpoint and server-side channel validation in `OrderController`; keep destination values encrypted in `OrderRefund`, and make `CodRefundPayoutService` route new destinations by the saved Xendit channel code while preserving legacy records. Update the existing SweetAlert flow in `MyOrders.tsx` with type and channel selectors.

**Tech Stack:** Laravel 12, PHPUnit, React 18, TypeScript, Vitest, SweetAlert wrapper, Xendit HTTP client.

---

### Task 1: Backend regression coverage

**Files:**
- Modify: `tests/Feature/Cod/CodDisputeRefundWorkflowTest.php`
- Modify: `tests/Feature/Cod/CodRefundTest.php`

- [ ] Add failing tests for customer-scoped channel loading, unsupported-channel rejection, and selected-channel routing.
- [ ] Run the focused tests and confirm they fail because the endpoint and channel-code handling are missing.

### Task 2: Customer channel endpoint and validation

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/UserSide/OrderController.php`

- [ ] Add an authenticated route scoped by refund id.
- [ ] Require the authenticated customer to own an approved COD refund whose payout has not started; allow replacing an existing destination before payout.
- [ ] Load the shop's connected Xendit integration and return the existing normalized `banks`/`e_wallets` metadata.
- [ ] Revalidate submitted channel codes against the same live list before saving.

### Task 3: Xendit routing

**Files:**
- Modify: `app/Services/Finance/CodRefundPayoutService.php`

- [ ] Use the stored channel code for new bank and e-wallet destinations.
- [ ] Preserve the existing `PH_GCASH` and legacy bank-channel fallback for old rows.

### Task 4: Customer UI

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx`

- [ ] Fetch channels when the customer opens the destination action.
- [ ] Select Bank Account or E-wallet, then select the exact supported provider.
- [ ] Submit the selected channel code with the account details and show the existing error/success feedback.
- [ ] Show the saved destination in masked form and allow editing until payout processing starts.

### Task 5: Verification

- [ ] Run focused Laravel feature tests.
- [ ] Run focused frontend tests and `pnpm run build`.
- [ ] Run PHP syntax checks and `git diff --check`.
