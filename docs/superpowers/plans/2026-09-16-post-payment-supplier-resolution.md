# Post-payment Supplier Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an explicit, backend-enforced Replacement/Refund and Return/Waive workflow for post-payment supplier issues.

**Architecture:** Extend the existing supplier-adjustment state transitions and endpoints rather than adding schema. Reuse the shared React panel and API service; preserve all authorization and tenant scoping.

**Tech Stack:** Laravel 12, PHPUnit, React 18, TypeScript, Vitest

---

### Task 1: Backend transitions

**Files:**
- Modify: `app/Services/SupplierAdjustmentService.php`
- Modify: `app/Http/Controllers/Erp/SupplierAdjustmentController.php`
- Modify: `routes/procurement-api.php`
- Test: `tests/Feature/Procurement/SupplierReplacementTest.php`
- Test: `tests/Feature/Finance/SupplierRefundTest.php`

- [ ] Add failing tests for post-payment resolution selection, required return decision, refund decline, replacement fallback, and loss closure.
- [ ] Extend the existing transition methods with the minimum stage-aware rules.
- [ ] Add a procurement-authorized refund-decline endpoint with validated reason/reference.
- [ ] Run the focused PHPUnit tests.

### Task 2: Procurement UI

**Files:**
- Modify: `resources/js/services/purchaseOrderApi.ts`
- Modify: `resources/js/Pages/ERP/Procurement/components/SupplierAdjustmentsPanel.tsx`
- Test: `resources/js/Pages/ERP/Procurement/__tests__/SupplierAdjustmentsPanel.test.tsx`

- [ ] Add failing role/state visibility tests.
- [ ] Show resolution choices first, then Return/Waive, then the selected resolution actions.
- [ ] Add supplier refund-decline capture using the existing confirmation pattern.
- [ ] Run the focused Vitest suite.

### Task 3: Verification

- [ ] Run focused backend and frontend tests.
- [ ] Run `npm run build`.
- [ ] Run `git diff --check` on changed source/test files.
