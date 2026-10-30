# Rider Assignment Notification Route Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make delivery-assignment notifications open the rider-accessible deliveries page without breaking existing notification links.

**Architecture:** Correct the notification URL at its source. Add a permission-aware redirect in the shipments controller for legacy links while keeping unauthorized users forbidden.

**Tech Stack:** Laravel, PHPUnit

---

### Task 1: Notification target

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsNotificationTest.php`
- Modify: `app/Services/Logistics/LogisticsNotificationService.php`

- [ ] Change the assignment-notification expectation to `/erp/logistics/deliveries` and verify it fails.
- [ ] Run `php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php --filter=rider_is_notified_when_a_delivery_is_assigned`; expect failure showing `/erp/logistics/shipments`.
- [ ] Change the individual rider notification target and verify the focused test passes.
- [ ] Re-run the same command; expect one passing test.

### Task 2: Legacy rider link

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`

- [ ] Expect an authorized rider visiting `/erp/logistics/shipments` to redirect to `/erp/logistics/deliveries`; retain an unauthorized-user 403 assertion and verify failure.
- [ ] Run `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=logistics_rider_can_access_my_deliveries`; expect the current 403 assertion mismatch.
- [ ] Change `shipments()` to return `Response|RedirectResponse`, importing `Illuminate\Http\RedirectResponse`.
- [ ] Before dispatcher authorization, redirect only users who have `operate-logistics-deliveries` and lack `assign-logistics-deliveries`; users with both retain shipments access and users with neither still reach the existing 403 guard.
- [ ] Re-run the focused access test; expect it to pass.
- [ ] Run `php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php`; expect both feature files to pass.
