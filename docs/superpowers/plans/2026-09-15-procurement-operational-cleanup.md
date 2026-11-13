# Procurement Operational Cleanup Implementation Plan

> **For agentic workers:** Execute sequentially in the existing `fix/procurement-supplier-payment-workflow` worktree. Do not create a branch/worktree, commit, or push.

**Goal:** Correct procurement completion, approval, receiving projections, and Supplier Adjustment handoffs using the existing domain.

**Architecture:** Keep all current records and transactional services. Add readiness/presentation methods to the existing Purchase Order Receipt, Purchase Order, and Supplier Adjustment services; controllers expose those projections and React consumes them.

**Tech Stack:** Laravel 12, PHPUnit, Inertia 2, React 18, TypeScript, Vite, Tailwind CSS.

---

### Task 1: P0 Purchase Order completion

**Files:**
- Modify: `tests/Feature/Procurement/SimplifiedReceivingWorkflowTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`
- Modify: `app/Services/PurchaseOrderService.php`

- [x] Add failing tests for short fulfillment completion, zero-payable completion, and supporting replacement receipts.
- [x] Run the focused tests and confirm expected failures.
- [x] Make completion inspect only the authoritative Final Receipt and require an expense only for non-zero payable value.
- [x] Run the focused tests and existing receiving/payment tests.

### Task 2: Purchase Request and Stock Request correctness

**Files:**
- Modify: `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`
- Modify: `tests/Unit/Services/PurchaseRequestServiceTest.php`
- Modify: `tests/Feature/Procurement/ProcurementConcurrencyTest.php`
- Modify: `app/Models/PurchaseRequest.php`
- Modify: `app/Services/PurchaseRequestService.php`
- Modify: `app/Http/Controllers/Erp/PurchaseRequestController.php`
- Modify: `app/Services/StockRequestApprovalService.php`
- Modify affected Owner Action Center projection/tests if required by the changed terminal status.

- [x] Add failing tests for single-pass Finance/Owner approval, decimal totals, and locked Stock Request decisions.
- [x] Run focused tests and confirm expected failures.
- [x] Change new PR transitions while preserving historical `pending_finance_final` handling.
- [x] Calculate PR totals with integer cents and format decimal text.
- [x] Lock Stock Request rows before validating decision state.
- [x] Run focused workflow and concurrency tests.

### Task 3: Backend readiness and Supplier Adjustment projection

**Files:**
- Modify: `tests/Feature/Procurement/SimplifiedReceivingWorkflowTest.php`
- Modify: `tests/Feature/Procurement/SupplierAdjustmentTest.php`
- Modify: `tests/Feature/Notifications/InventoryNotificationTest.php`
- Modify: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Services/SupplierAdjustmentService.php`
- Modify: `app/Http/Controllers/Erp/SupplierAdjustmentController.php`
- Modify: `app/Services/NotificationService.php`

- [x] Add failing projection, filter, and permission-recipient tests.
- [x] Return accounted, accepted, defective, replacement, unresolved, payable, readiness, blocker, owner, action, and human-label fields.
- [x] Filter Supplier Adjustments server-side by PO, status, and current owner/actionability.
- [x] Route Inventory handoffs by receiving/view capability and include stable PO/adjustment deep-link parameters.
- [x] Run focused backend tests.

### Task 4: Role-correct operational UI

**Files:**
- Modify: `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/components/SupplierAdjustmentsPanel.tsx`
- Modify: `resources/js/Pages/ERP/procurement/PurchaseOrders.tsx`
- Modify: `resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx`
- Modify: `resources/js/services/purchaseOrderApi.ts`
- Modify related frontend tests.

- [x] Add failing component tests for capability-gated finalization and human adjustment projection.
- [x] Render backend quantities/readiness; remove frontend state-machine reconstruction.
- [x] Hide Final Receipt from Procurement and retain backend authorization.
- [x] Add action-specific confirmation/loading/success feedback and controlled evidence preview.
- [x] Add server-filtered actionable queue and exact-record selection through stable query parameters.
- [x] Run frontend tests when tooling is available and run the frontend build.

### Task 5: Review and verification

- [x] Run focused backend workflows and the full Procurement plus relevant Finance test set.
- [x] Run the frontend suite and production build through the local binaries because pnpm is unavailable.
- [x] Run `git diff --check`.
- [x] Review authorization, tenant scope, lock order, upload privacy, decimal arithmetic, duplicate side effects, reuse, dead code, and acceptance conditions.
- [x] Record only durable lessons in `docs/ai-learning-log.md` if a reusable lesson was discovered.
