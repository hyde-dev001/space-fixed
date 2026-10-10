# Post-payment Supplier Resolution Design

## Goal

Require Procurement to choose a supplier resolution before acting on a post-payment issue, and keep return handling and supplier-decline outcomes inside the tracked workflow.

## Workflow

1. Inventory reports a post-payment issue.
2. Procurement chooses Replacement or Refund.
3. Procurement chooses Return Required or Return Waived for either resolution.
4. Replacement follows the existing sent, accepted/declined, in-transit, and receiving flow.
5. Refund waits for supplier proof; Finance verifies the refund after proof submission.
6. If the supplier declines a refund, Procurement records the required reason and may switch to Replacement or close the unresolved quantity as a loss/short fulfillment.

## Constraints

- Reuse existing `resolution`, `replacement_status`, `return_status`, `decline_reason`, and workflow status fields.
- Enforce transitions on the backend, not only through hidden UI controls.
- Preserve receiving-defect behavior and tenant/permission checks.
