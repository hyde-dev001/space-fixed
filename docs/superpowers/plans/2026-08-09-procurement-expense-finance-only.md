# Procurement Receipt Expense Finance-Only Implementation Plan

> **For agentic workers:** Execute this plan sequentially with test-first checkpoints.

**Goal:** Keep purchase-order receipt expenses as Finance records without creating a Shop Owner/multi-level expense approval workflow, and make Finance notifications open the real Finance page.

**Architecture:** Reuse the existing receipt expense creation and Finance expense endpoints. Procurement receipts will remain linked to `finance_expenses`, but will not create an `Approval` row; manually entered expenses continue using the existing four-step workflow. Shop Owner expense boundaries remain unchanged and continue excluding receipt-linked expenses.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit feature tests, Inertia React/TypeScript, Vite.

---

### Task 1: Add regression coverage

**Files:**
- Modify: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`

- [x] Assert a received PO creates one submitted expense with no approval row.
- [x] Assert the Shop Owner cannot list or directly approve the receipt-linked expense.
- [x] Assert Finance receives a notification with a valid `/finance?section=expense-tracking` action URL.
- [x] Assert Finance approve/reject actions are blocked for receipt expenses while the record remains available for review.
- [x] Run the focused tests and confirm the new expectations fail before implementation.

### Task 2: Implement the Finance-only boundary

**Files:**
- Modify: `app/Services/ExpenseApprovalService.php`
- Modify: `app/Http/Controllers/Api/Finance/ExpenseController.php`
- Modify: `app/Services/NotificationService.php`
- Modify: `app/Http/Controllers/ApprovalController.php`
- Modify: `resources/js/components/common/NotificationDropdown.tsx`
- Modify: `resources/js/Pages/Notifications/NotificationList.tsx`

- [x] Stop `submitProcurementExpense()` from creating a multi-level `Approval` record.
- [x] Keep idempotent receipt expense creation and Finance notification behavior.
- [x] Normalize legacy receipt expenses with pending approval rows before Finance approve/reject actions.
- [x] Route new and legacy expense notifications to valid Finance or Shop Owner pages.
- [x] Keep generic manually entered expense workflows unchanged.

### Task 3: Verify and review

- [x] Run the focused Finance and Procurement tests.
- [x] Run `git diff --check` and relevant frontend/build checks if generated output changes.
- [x] Review the diff against the Finance-only procurement spec and scan changed areas for dead code.
- [ ] Stage only files belonging to this fix; preserve unrelated Logistics changes.
