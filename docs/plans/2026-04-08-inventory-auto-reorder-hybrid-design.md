# Inventory Auto-Reorder Hybrid Design

Date: 2026-04-08
Status: Approved for planning
Owners: ERP Inventory, Procurement

## 1. Problem Statement

The current inventory system stores reorder fields at item level, but automatic stock request creation is not consistently triggered across all stock deduction paths.

Current behavior summary:
- Repair workflow material usage already creates auto stock requests when stock falls at or below reorder level.
- Shoes checkout and other stock-out paths do not consistently auto-create stock requests.
- Upload UI currently passes hardcoded reorder defaults instead of explicit user-configured thresholds.
- Shoes require per size plus per color reorder control to avoid hidden stockouts in specific variants.

## 2. Goals

1. Enforce real-time auto-reorder creation on stock mutation paths that reduce stock.
2. Support per size plus per color thresholds for shoes.
3. Keep repair material behavior working and align it with the same dedupe and upsert rules.
4. Add reconciliation safety net to catch edge paths not yet wired to real-time triggers.
5. Avoid duplicate pending requests while keeping quantity recommendations current.

## 3. Non-Goals

1. No full rewrite of all inventory modules into a single orchestrator in this phase.
2. No auto-approval bypass in procurement approval flow.
3. No changes to refund workflows or unrelated finance modules.

## 4. Approved Approach

Hybrid approach:
- Real-time targeted integration now.
- Reconciliation job as safety net.

Why this was selected:
- Fastest path to production value.
- Resilient against legacy and uncovered mutation paths.
- Low migration risk compared with full architecture rewrite.

## 5. Data Model Changes

### 5.1 inventory_sizes

Add required reorder fields per size row:
- reorder_level: integer, min 1
- reorder_quantity: integer, min 1

Rules:
- reorder_quantity must be greater than or equal to reorder_level.
- For shoes, these values define the threshold at exact size plus color scope.

Backfill:
- For existing rows, copy from parent inventory_items.reorder_level and inventory_items.reorder_quantity.
- If parent values are invalid or zero, normalize to safe minimum defaults during migration.

### 5.2 stock_request_approvals

Add optional variant linkage for dedupe identity and procurement traceability:
- inventory_size_id nullable foreign key
- inventory_color_variant_id nullable foreign key

Keep existing readable fields:
- requested_size
- requested_color
- is_auto_generated

Add composite lookup index for open auto requests:
- shop_owner_id
- inventory_item_id
- inventory_size_id
- inventory_color_variant_id
- request_source
- status
- is_auto_generated

## 6. Request Identity and Upsert Rules

### 6.1 Shoes

Identity key:
- shop_owner_id
- inventory_item_id
- inventory_size_id
- inventory_color_variant_id
- request_source = manual
- is_auto_generated = true

### 6.2 Repair Materials

Identity key:
- shop_owner_id
- inventory_item_id
- request_source = repair
- is_auto_generated = true
- inventory_size_id = null
- inventory_color_variant_id = null

### 6.3 Open Request Deduplication

For same identity, consider request open when status is one of:
- pending
- needs_details

Behavior:
- If open request exists, update quantity_needed only when recomputed need is higher.
- If none exists, create a new stock request.

## 7. Quantity Formula

For both categories:
- quantity_needed = max(1, reorder_quantity - current_stock_at_scope)

Scope rules:
- Repair materials: item-level current stock.
- Shoes: per size plus per color current stock.

## 8. Real-Time Trigger Integration Map

Use a shared domain service, for example ReorderAutomationService, with a single entry:
- evaluateAfterMutation(context)

Trigger after successful stock reduction commit in these paths:
1. Shoes checkout deductions.
2. Repair material usage deductions.
3. Manual stock_out and negative adjustment operations.
4. Future mutation handlers that decrease stock.

Execution steps:
1. Build scope payload from mutation context.
2. Read current stock at scope.
3. Evaluate threshold breach.
4. Compute quantity_needed.
5. Upsert stock request using identity rules.
6. Emit notification payload for procurement visibility when created or escalated.

## 9. Reconciliation Job

Job name proposal:
- inventory:reconcile-auto-reorder

Schedule:
- Every 10 minutes.

Responsibilities:
1. Scan repair materials below threshold.
2. Scan shoes per size plus color below threshold.
3. Re-apply the same upsert logic used by real-time service.
4. Optionally align stale open auto requests to higher computed quantity_needed when required.

Operational guardrails:
- Process per shop in chunks.
- Idempotent identity lookup before create.
- Metrics logging for created, updated, skipped, and failed counts.

## 10. Workflow and Approval Routing

Approved routing:
- Shoes auto-generated requests should route as manual source into procurement queue.
- Repair auto-generated requests remain repair source and follow existing repair inventory to procurement behavior.

No workflow bypass:
- Procurement approval remains required.

## 11. UI and API Contract Updates

### 11.1 Upload Inventory UI

Required inputs:
- Item-level reorder level and reorder quantity are required for all uploads.
- Shoes size rows require reorder level and reorder quantity per size.

Behavior:
- Remove hardcoded default-only behavior from client submission.
- Show validation errors clearly for missing or invalid threshold fields.

### 11.2 API Validation

Enforce server-side validation for:
- Item-level reorder fields.
- Shoes size-level reorder fields.
- Numeric constraints and logical constraints.

## 12. Error Handling and Safety

1. Wrap reorder evaluation and upsert in database transactions where mutation context requires strict consistency.
2. Fail-safe mode:
- Stock mutation succeeds.
- Reorder creation failure logs structured error and is recovered by reconciliation job.
3. Use explicit logging keys:
- shop_owner_id
- inventory_item_id
- inventory_size_id
- inventory_color_variant_id
- request_id
- action create or update or skip

## 13. Testing Strategy

### 13.1 Unit Tests

1. Threshold evaluation for item-level and size-plus-color level.
2. Quantity formula correctness.
3. Identity key generation.
4. Upsert behavior for open requests.

### 13.2 Feature Tests

1. Shoes checkout crossing threshold creates one auto request at size-plus-color scope.
2. Repeated deductions update same pending request, no duplicate creation.
3. Repair material usage still auto-creates repair request correctly.
4. Manual stock_out and negative adjustment trigger evaluation.
5. Reconciliation job creates missing request when real-time trigger was skipped.
6. Concurrency scenario with simultaneous deductions does not create duplicate open requests.

## 14. Rollout Plan

Phase 1:
- Add schema changes and backfill migration.
- Add ReorderAutomationService core logic.
- Wire selected real-time deduction paths.

Phase 2:
- Enable reconciliation schedule.
- Add monitoring dashboard counters and logs.

Phase 3:
- Expand wiring to all remaining stock mutation paths.
- Optional future consolidation into centralized stock mutation orchestrator.

## 15. Acceptance Criteria

1. Any stock deduction that crosses threshold creates or updates exactly one open auto request for its scope.
2. Shoes reorder behavior is variant-aware per size plus color.
3. No duplicate pending auto requests exist for same identity.
4. Reconciliation recovers missed triggers without creating duplicates.
5. Existing repair auto-reorder behavior remains functional.
