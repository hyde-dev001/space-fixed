# Retail Delivery Cancellation Awareness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Alert retail-order staff and show the latest shop-owned delivery cancellation on the affected order without changing its `shipped` status.

**Architecture:** Extend the existing logistics event notification service for `delivery_cancelled` events whose shipment source is an order. The staff orders API will attach the latest cancelled shipment's customer-facing reason; Job Orders renders it as a read-only warning.

**Tech Stack:** Laravel 12, PHPUnit, Spatie permissions, Inertia React/TypeScript.

---

### Task 1: Notify retail-order staff

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsNotificationTest.php`
- Modify: `app/Services/Logistics/LogisticsNotificationService.php`

- [ ] **Step 1: Write the failing test**

Create a shop, an order sourced shipment, and a user with `access-staff-job-orders`; record the customer-visible `delivery_cancelled` event. Assert the user receives exactly one high-priority, action-required `logistics_delivery_failed` notification pointing to `/erp/staff/job-orders` and including the cancellation message.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php --filter=retail_order_staff`

Expected: FAIL because cancelled delivery events do not notify order staff.

- [ ] **Step 3: Write minimal implementation**

Map `delivery_cancelled` to the existing `LOGISTICS_DELIVERY_FAILED` type. For only customer-visible, order-source cancelled events, select same-shop users with `access-staff-job-orders` and create one high-priority notification per user with shipment/order IDs, the event message, and the Job Orders action URL. Ignore the paired internal cancellation event to prevent duplicates and preserve the customer-facing reason.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php --filter=retail_order_staff`

Expected: PASS.

### Task 2: Expose cancellation on staff orders

**Files:**
- Modify: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write the failing test**

Request the staff orders API and load the Job Orders route for an order with multiple logistics shipments. Assert both payloads include only the latest customer-visible cancelled shipment's `status` and cancellation `message`, while the order remains `shipped`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Logistics/SourceModuleShipmentRequestTest.php --filter=cancelled`

Expected: FAIL because the API has no logistics cancellation payload.

- [ ] **Step 3: Write minimal implementation**

Bulk-load order-source shipments with `status = cancelled` and their latest customer-visible `delivery_cancelled` event, choose the most recently created shipment using `created_at` then `id`, and return `delivery_cancellation` as either `null` or `{ status: 'cancelled', message: string }`. Apply the same serialization to the Job Orders route's `initialOrders` payload. Do not update the order status.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Logistics/SourceModuleShipmentRequestTest.php --filter=cancelled`

Expected: PASS.

### Task 3: Render the staff alert

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`

- [ ] **Step 1: Add the minimum UI contract**

Add the optional `delivery_cancellation` field to the local order type and map the API payload into it.

- [ ] **Step 2: Render the warning**

In the order detail modal, render a red `Delivery cancelled` alert only when `delivery_cancellation` exists, showing its reason and stating that reassignment is handled in Logistics.

- [ ] **Step 3: Build and type-check**

Run: `npm run build`

Expected: exit code 0.

### Task 4: Verify the integrated flow

**Files:**
- Verify: `tests/Feature/Logistics/LogisticsNotificationTest.php`
- Verify: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`
- Verify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`

- [ ] **Step 1: Run focused backend tests**

Run: `php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`

Expected: PASS.

- [ ] **Step 2: Run production build**

Run: `npm run build`

Expected: exit code 0.

- [ ] **Step 3: Commit only feature files**

Run: `git add app/Services/Logistics/LogisticsNotificationService.php app/Http/Controllers/Api/StaffOrderController.php routes/web.php resources/js/Pages/ERP/STAFF/JobOrders.tsx tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php && git commit -m "feat: alert staff to cancelled retail deliveries"`
