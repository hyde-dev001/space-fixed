# Unified Cashier POS Design

Date: 2026-04-08
Status: Approved
Owner: ERP Product and Engineering

## 1. Objective

Create a unified ERP Point of Sale experience handled by a new Cashier role.

The unified POS must support:
- Repair transactions (existing full repair POS capabilities)
- Retail walk-in shoe transactions (new retail mode)
- Repair and retail refund workflows

At the same time:
- Remove POS entry points from the Repairer UX
- Remove Repairer Job Order action that sends users to POS

## 2. Scope

In scope:
- New Cashier role
- New permission gate: access-unified-pos
- New ERP route: erp.cashier.point-of-sale
- Unified POS page with two modes: Repair and Retail
- Reuse existing POS ledger tables with module_type split (repair or retail)
- Backend mode validation, shop scoping, and permission enforcement
- Retail walk-in checkout and retail refund workflow
- Repairer flow update: no Proceed to POS action

Out of scope:
- Full redesign of all POS visuals
- New independent POS data store
- New customer-facing retail checkout flows outside ERP

## 3. Functional Requirements

### 3.1 Access and Navigation
- POS menu is visible only to users with access-unified-pos.
- POS menu is removed from Repair section by default.
- Repairer users without explicit POS permission cannot access the unified POS route.
- Legacy repairer POS route must have deterministic behavior (redirect or deny) and must not silently succeed.

### 3.2 Unified POS Modes

#### Repair Mode
- Preserve current repair POS capabilities:
  - Attach from repair orders
  - Manual repair queue handling
  - Receipt history
  - Repair refund request and execution lifecycle

#### Retail Mode
- Support in-shop walk-in retail checkout:
  - Product and variant selection
  - Stock availability check
  - Payment methods: cash, gcash, card
  - Receipt generation and history
- Support retail refund lifecycle:
  - Request
  - Approval
  - Execution
  - Inventory adjustment policy for returned items

### 3.3 Repairer Flow Changes
- Remove Proceed to POS trigger from Repairer Job Orders page.
- Repairer becomes payment-status observer only.
- Payment processing responsibility moves to Cashier POS users.

## 4. Architecture

## 4.1 Frontend
- One unified POS route and page shell.
- Shared components for:
  - customer and tender inputs
  - totals and tax breakdown
  - receipt preview and print
  - history and refund views
- Mode-specific adapters:
  - Repair adapter for repair endpoints and rules
  - Retail adapter for retail checkout and retail refund semantics

## 4.2 Backend
- Reuse POS ledger and receipt/refund data model.
- Distinguish mode using module_type values:
  - repair
  - retail
- Enforce business type compatibility at API level:
  - Repair mode only for repair or both shops
  - Retail mode only for retail or both shops
- Enforce auth and permission at route and controller layers.

## 5. Data and Transaction Model

### 5.1 Ledger Reuse
- Continue using pos_transactions, payment lines, receipts, and refunds.
- Retail entries must persist as module_type=retail.
- Repair entries continue as module_type=repair.

### 5.2 Atomicity
- Retail checkout must run in atomic transaction boundaries:
  - validate stock
  - reserve or decrement stock
  - write POS transaction and payment lines
  - write receipt payload

### 5.3 Idempotency and Locking
- Use idempotency key for duplicate-submit protection.
- Preserve phase locking for repair payment phases.

## 6. Security and Validation

- Route-level gate: auth:user + permission:access-unified-pos.
- Shop scope must be validated for every mutation.
- Mode-to-business-type compatibility must be enforced server-side.
- Refund stage actions must validate actor authority and stage eligibility.

## 7. Error Contract

Standardized machine-readable error codes to support stable frontend handling:
- AUTH_FORBIDDEN_SHOP_SCOPE
- BUSINESS_TYPE_FORBIDDEN_MODE
- POS_PERMISSION_REQUIRED
- STOCK_INSUFFICIENT
- PAYMENT_PHASE_ALREADY_SETTLED
- REFUND_ALREADY_EXISTS

Frontend should display user-friendly messages and retain internal code mapping for diagnostics.

## 8. Rollout Plan

Phase 1: Introduce
- Add Cashier role and access-unified-pos permission.
- Add unified POS route and menu visibility gate.
- Keep legacy route behavior explicit.

Phase 2: Migrate
- Enable retail mode in unified POS.
- Verify repair mode parity.
- Pilot rollout with selected shops or users.

Phase 3: Cutover
- Remove repairer sidebar POS entry.
- Remove repairer Proceed to POS action in Job Orders.
- Keep monitoring for payment and refund regressions.

## 9. Testing Strategy

### 9.1 Unit Tests
- Mode resolver by business type
- Access resolver by role and permission
- Totals and validation helpers for retail and repair

### 9.2 Feature Tests
- Cashier access allowed to unified POS
- Repairer without POS permission denied access
- Repairer Job Orders no longer expose Proceed to POS
- Retail checkout writes ledger, receipt, and stock movement
- Repair checkout parity retained
- Refund workflows for repair and retail by stage

### 9.3 Regression Tests
- Existing repair POS behaviors under Repair mode remain intact
- History and refund filters isolate mode correctly

## 10. Open Implementation Notes

- Keep route naming and permission naming explicit for maintainability:
  - Role: Cashier
  - Permission: access-unified-pos
  - Route: erp.cashier.point-of-sale
- Prefer additive migration first, then remove old entry points after verification.

## 11. Approval Record

Approved by request owner in brainstorming session on 2026-04-08.

