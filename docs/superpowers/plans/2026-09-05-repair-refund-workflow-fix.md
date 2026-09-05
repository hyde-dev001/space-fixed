# Repair Refund Workflow Fix

## Objective

Keep customer-booked repairs on one canonical repair record and keep repair-service refunds out of the retail merchandise-return workflow.

## Findings

- Linked POS checkout locks and settles the existing `RepairRequest` identified by `repair_request_id`; it creates a `PosTransaction`, not a second repair. A separate POS repair request is intentional only for a walk-in checkout without a repair ID.
- Repair refunds already use `PosRefund.module_type = repair` and `module_reference_id` to identify the repair, but the shared repair refund entry point does not reject a non-repair source transaction.
- Repairer refund queue and approve/reject actions scope by shop but not by the assigned repairer.
- The repair Finance serializer exposes `returnStatus = received`, which falsely suggests retail return inspection even though the repair refund service has no such prerequisite.
- Customer repair refund output reads a non-existent `owner_status` attribute instead of the canonical `shop_owner_status` column.
- Finance approval UI uses retail inspection copy for both refund domains.

## Minimal changes

1. Guard the repair refund service against non-repair POS transactions.
2. Scope repairer refund reads and review mutations to the assigned repairer and shop.
3. Make the repair Finance payload explicitly source-aware (`repair`, repair number, receipt number, no retail return status).
4. Return the canonical owner approval status to My Repairs.
5. Show repair-specific Finance approval guidance while preserving retail inspection guidance.
6. Add backend and frontend regression coverage for source boundaries, tenant/assignment isolation, canonical identity, synchronized status, and retail return gates.

## Explicitly unchanged

- No schema or migration changes.
- No new refund state machine.
- No changes to `OrderRefundService` or legitimate retail Staff receipt/inspection behavior.
- No change to repair lifecycle semantics or existing POS manual walk-in creation.

## Verification

- Run focused repair/refund and retail regression tests.
- Run focused Finance React tests, frontend test suite, and production build.
- Run `git diff --check` and review changed files for authorization, source scoping, and dead code.
