# Logistics Role Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Separate rider delivery processing from dispatcher shipment management.

**Architecture:** Reuse the existing shipment controller/data; add a rider-deliveries page route and make dispatcher shipment access depend on the delivery-management permission.

**Tech Stack:** Laravel, Inertia React, PHPUnit.

---

### Task 1: Route authorization

- [ ] Add a failing access test for a rider's My Deliveries route and dispatcher-only Shipments route.
- [ ] Add `deliveries()` to `ErpLogisticsController`, scoped to the logged-in rider's assignments.
- [ ] Add the route and verify the focused access test passes.

### Task 2: Separate pages

- [ ] Create `ERP/Logistics/MyDeliveries.tsx` by reusing the existing rider action UI.
- [ ] Remove rider processing actions from `Shipments.tsx`; keep assignment and proof approval only.
- [ ] Add permission-aware sidebar links and run the focused Logistics tests and build.
