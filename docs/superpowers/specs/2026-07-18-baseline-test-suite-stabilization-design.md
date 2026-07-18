# Baseline Test Suite Stabilization Design

## Goal

Restore the existing baseline unit suite by aligning stale tests with current job contracts and preventing inconsistent inventory deductions. This work is separate from the failed-delivery refund branch.

## Low-Stock Alerts

`CheckLowStockJobTest` verifies inventory-alert creation, not notification recipients. Fake the low-stock and out-of-stock events in those tests so synchronous notification listeners do not require seeded Spatie permissions. Production listeners and permission behavior remain unchanged.

## Overdue Supplier Orders

Keep `CheckOverdueOrdersJob` scoped by its required `shopOwnerId` and preserve its current responsibility: detect qualifying orders and dispatch `SupplierOrderOverdue` without inventing an `overdue` database status. Update tests to:

- construct the job with the shop owner ID;
- explicitly assign each supplier-order fixture to that same shop owner;
- assert the event is dispatched for an overdue `confirmed` order;
- use valid `draft` status for the excluded-order case;
- assert no event is dispatched for excluded orders.

No enum or migration change is needed.

## Inventory Safety

`InventoryItem::decrementStock()` must throw `InvalidArgumentException` for non-positive quantities and deductions larger than available stock before changing inventory or writing a movement. Valid deductions keep their current behavior. Strengthen the existing over-deduction test to prove quantity and movement history remain unchanged, and add explicit zero- and negative-quantity cases.

## Verification

1. Run each affected unit test file independently.
2. Run the three files together to prove isolation.
3. Run the full PHP suite to expose any remaining independent baseline failures.
4. Address additional deterministic failures only after root-cause investigation; do not hide failures or broaden production behavior speculatively.

PHPUnit doc-comment metadata warnings are out of scope because they do not fail the current suite.
