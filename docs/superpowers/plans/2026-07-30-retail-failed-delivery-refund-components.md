# Retail Failed-Delivery Refund Components Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make retail failed-delivery refunds retain or include shipping according to who caused the terminal attempt.

**Architecture:** Pass the existing reason code into `OrderRefundService`, then adjust the amount inside the existing transactionally locked reservation. For ambiguous failures, reuse the Finance approval endpoint and require one of the two valid shipping scopes. Keep the return-to-shop workflow unchanged.

**Tech Stack:** Laravel, Eloquent, React, TypeScript, SweetAlert2, PHPUnit feature tests

---

### Task 1: Add failing responsibility tests

**Files:**
- Modify: `tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php`

- [x] Add customer, operations, and ambiguous amount/note cases.
- [x] Assert the rider terminal-attempt path passes `recipient_unavailable` and reserves PHP 1,000 instead of PHP 1,100.
- [x] Run the focused tests and confirm they fail on the current full-refund behavior.

### Task 2: Apply the policy

**Files:**
- Modify: `app/Services/OrderRefundService.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`

- [x] Pass the reason code into `reserveFailedDeliveryRefund`.
- [x] Classify the reason and write an auditable Finance note.
- [x] Subtract shipping inside the locked reservation only for customer-caused failures.
- [x] Run the focused tests and confirm they pass.

### Task 3: Require the Finance decision for ambiguous failures

**Files:**
- Modify: `app/Services/OrderRefundService.php`
- Modify: `app/Http/Controllers/Api/RefundApprovalController.php`
- Modify: `resources/js/Pages/ERP/Finance/refundApproval.tsx`

- [x] Reject Finance approval when the shipping decision is missing.
- [x] Accept only products-only or full-with-shipping amounts.
- [x] Show exactly those two options in the Finance approval modal.

### Task 4: Regression verification

**Files:**
- Verify: `tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php`
- Verify: `tests/Feature/Logistics/LogisticsApiTest.php`

- [x] Run the full refund and logistics test files.
- [x] Run the Finance frontend tests and production build.
- [x] Run syntax and diff checks.
- [x] Confirm no migration, dependency, or unrelated refund-flow change.
