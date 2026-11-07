# Shop Owner Phase 4 Configurable Approval Design

## Goal

Make the seven Shop Owner approval families real, tenant-scoped workflows while allowing the Shop Owner to decide in Settings whether each family includes a Shop Owner decision stage. Consolidate all owner review and decision work into the Phase 3 Action Center so approvals have one sidebar destination and one understandable interaction model:

1. Refund Approval;
2. Price Approvals;
3. Payslip Approval;
4. Salary Adjustment Approval;
5. Purchase Request Approval;
6. Expense Approvals;
7. Repair Reject Approval.

Phase 4 reuses each existing domain workflow. It does not create a universal approval engine or merge unrelated records into one table. The Action Center is the centralized presentation and decision surface; domain services remain authoritative for state changes.

## Approved settings rule

Each family has an independent `enabled` toggle under Shop Settings > Approval Workflow.

- **ON:** newly submitted records include the Shop Owner stage.
- **OFF:** newly submitted records may skip only the Shop Owner stage and continue to an already-authoritative downstream decision stage proven to exist for that family.
- Toggle state is evaluated and snapshotted when the record enters its approval workflow.
- Changing a toggle affects only records submitted afterward. It does not reroute, remove an approver from, or add an approver to an in-flight record.
- The server owns the decision. Client-provided responsibility flags are ignored.
- Missing or malformed settings fail safe to **ON** so an invalid configuration cannot silently bypass owner review.

### No-invented-workflow gate

Before implementing an OFF path for any family, characterization must identify an existing authoritative non-owner decision stage, its actor, state transition, authorization rule, audit behavior, and final/downstream effect.

- Phase 4 may connect an OFF path only to that proven existing stage.
- Phase 4 may not invent a fallback approver, broaden a permission, rename an operational action as an approval, create a synthetic status, or collapse required separation of duties merely to make OFF possible.
- The existence of a Finance, HR, Procurement, or Manager role elsewhere in the module is not proof that the role is authorized to decide this request.
- If no valid existing non-owner decision stage exists, implementation for that family must stop before production changes. The focused family design must be updated, reviewed, and explicitly approved before work resumes.
- Until that focused decision is approved, the safe behavior remains owner-required; the implementation must not silently reinterpret OFF.

The Phase 4 toggle is intentionally binary. Legacy amount-limit values may remain stored for compatibility, but they do not override the toggle and are not shown as active workflow controls. This avoids ambiguous cases where a toggle says ON but an amount threshold still skips the owner.

Operational records that are not approval requests, such as procurement-receipt expenses or payroll accounting entries, remain under their existing operational controls and are not inserted into an unrelated owner queue.

## Workflow matrix

| Family | Toggle ON | Candidate Toggle OFF route to verify during characterization | Final/downstream responsibility |
| --- | --- | --- | --- |
| Refund | Finance initial review -> Shop Owner decision -> Finance final/execution | Finance initial review -> Finance final/execution | Finance validates and executes payout; return/refund safety gates remain authoritative |
| Price | Finance initial review -> Shop Owner decision -> Finance final apply | Finance review/final apply | Finance is the only actor that applies the approved price |
| Payslip | Finance checker -> Shop Owner decision -> Finance review/final -> disbursement | Finance checker -> Finance review/final -> disbursement | Finance retains final payroll checks and disbursement |
| Salary Adjustment | HR proposal -> Shop Owner decision -> HR apply | HR proposal -> authorized non-proposer HR/Manager review -> HR apply | HR applies the effective-dated salary change |
| Purchase Request | Finance initial review -> Shop Owner decision -> Finance final release | Finance initial review -> Finance final release | Finance releases the request for purchasing |
| Expense | Finance initial review -> Shop Owner decision | Finance review/finalization | Finance retains accounting and settlement responsibility |
| Repair Reject | Repairer rejection request -> Shop Owner decision -> Manager final review | Repairer rejection request -> Manager final review | Manager finalization and repair reassignment behavior remain authoritative |

The OFF column records candidate routes based on the current architecture, not authorization to implement them. Each route must pass the no-invented-workflow gate first. Skipping the owner stage is not auto-approval. The next proven existing stage performs its own decision, and no payout, price application, disbursement, salary mutation, purchase release, settlement, or terminal repair rejection occurs merely because the toggle is OFF.

## Policy snapshot contract

The effective boolean `requires_owner_approval` is frozen at submission.

- Workflows already represented by `Approval.approval_roles` (Price, Payslip, and manual Expense) snapshot the setting by constructing their role map once.
- `RepairRequest.requires_owner_approval` remains the Repair Reject snapshot.
- Refund records, Purchase Requests, and Salary Changes receive the smallest persisted boolean snapshot needed to avoid consulting live settings during later actions or list queries.
- Existing in-flight records are preserved. A migration/backfill derives their snapshot from their current state: records already at or past an owner stage remain owner-required; untouched records use a documented conservative default.
- Queue queries, notifications, Action Center adapters, and mutation authorization all read the same persisted workflow state/snapshot.

## Settings contract

`ProcurementSettings.settings_json.approval_pages` remains the shared settings boundary and is expanded to seven keys:

- `refund_approval`
- `price_approval`
- `payslip_approval`
- `salary_adjustment_approval`
- `purchase_request_approval`
- `expense_approval`
- `repair_reject_approval`

The Settings UI shows all seven labels exactly as the sidebar does. Saving validates complete booleans server-side and preserves unrelated settings. Legacy `limit` values are retained in stored JSON during rollout but are ignored by the Phase 4 policy and omitted from the active controls.

## Existing boundaries to reuse

- `ShopOwnerApprovalPolicyService` remains the single settings-to-policy boundary.
- `OrderRefundService` and existing retail/repair refund controllers remain authoritative for refund transitions and payout safety.
- `PriceChangeApprovalService` and the product/repair price controllers remain authoritative for Price workflows.
- `PayslipApprovalService` and `PayslipApprovalController` remain authoritative for payroll transitions.
- `SalaryChangeApprovalService` and `SalaryChangeController` remain authoritative for salary changes.
- `PurchaseRequestService` and `PurchaseRequest` remain authoritative for Purchase Request transitions.
- `ExpenseApprovalService`, `ApprovalService`, `Approval`, and `ApprovalHistory` remain authoritative for manual Expense approvals.
- `RepairWorkflowController` and `RepairRequest` remain authoritative for Repair Reject decisions.
- Existing Shop Owner pages, canonical routes, audit records, notifications, and Phase 3 Action Center adapters are extended instead of replaced.

## Actor and authorization contract

- Only the authenticated Shop Owner identity, or an explicitly linked owner identity already supported by the domain, can satisfy an included owner stage.
- When the owner stage is omitted, only an already-authoritative actor proven by characterization can perform the next decision; OFF never grants or broadens approval authority.
- Salary Adjustment OFF may use an existing authorized non-proposer reviewer only if characterization proves that reviewer already owns the decision. Otherwise Salary Adjustment OFF is blocked pending a focused design update.
- Every list and mutation is scoped by the authenticated actor's `shop_owner_id`.
- Repeated, stale, cross-shop, wrong-stage, and post-finalization mutations fail without changing state.
- Rejections require a bounded reason. Money and proposed values are resolved from locked server records.

## Centralized Action Center contract

- The Shop Owner sidebar exposes one `Action Center` entry with the current `Needs My Decision` count. The `Approval Pages` submenu and its seven links are removed.
- `Needs My Decision` is the default Action Center tab. Existing `Urgent Exceptions` and `Waiting on Others` tabs remain available and retain their Phase 3 responsibilities.
- The approval queue includes eligible filters for `All`, `Refund`, `Price`, `Payslip`, `Salary Adjustment`, `Purchase Request`, `Expense`, and `Repair Reject`, each with a count. Module/business eligibility still determines which filters are available.
- Selecting an approval keeps the owner on the Action Center. Desktop uses a stable master-detail layout with the queue and a right-side decision panel; small screens use an accessible full-screen sheet.
- The detail panel loads authoritative domain data on demand and uses the existing domain mutation endpoint for Approve or Reject. The Action Center does not become a second approval state machine.
- No Approve/Reject control appears directly on a condensed list row. The owner must open the detail panel and review the decision summary before acting.
- Toggle-OFF records never enter `Needs My Decision` and never expose owner mutation controls. They may appear only in a truthful non-actionable context/history view if that view is explicitly supported.
- Owner notifications are emitted only when an included owner stage is reached. Their canonical destination is the matching Action Center deep link.
- Legacy seven-page URLs and old notification links redirect to the matching Action Center record while preserving tenant scope. Unknown, inaccessible, or completed records resolve to a safe Action Center state with an explanatory message.
- Domain queue, notification, and Action Center qualifications share one source of truth and must have count/list parity.

### Canonical deep-link contract

The Action Center uses one stable URL shape that identifies both domain type and record, for example:

`/shop-owner/action-center?bucket=needs_my_decision&approval=order_refund:123`

The typed key avoids ambiguity between order refunds and repair/POS refunds. Filtering, opening, browser back/forward, refresh, and copied links preserve the selected item. Closing the panel removes only the `approval` parameter and retains the active bucket/filter/page.

### Approval queue information hierarchy

Every row uses the same scan order:

1. approval type and truthful status;
2. primary subject/title;
3. amount or material impact when applicable;
4. requester and submitted time;
5. waiting age/urgency;
6. a clearly labelled `Review` control.

Status is communicated with text and icon, never color alone. Secondary metadata is progressively disclosed so the list remains scannable.

### Decision panel information hierarchy

Every family panel uses the same shell and section order while rendering only its domain-relevant fields:

1. **Decision summary** - what is being requested, current stage, requester, amount/impact, and what approval will do;
2. **Request details** - domain-specific values and before/after comparisons;
3. **Evidence and notes** - attachments, reason, and supporting context when present;
4. **Workflow and history** - completed actors, current responsibility, and next actor;
5. **Decision footer** - `Approve` as the single primary action and `Reject` as a separated destructive action.

Approval requires a confirmation that restates the record and consequence. Rejection opens a labelled required-reason field with inline validation. Actions disable while submitting, reject duplicate clicks, announce success/error through an accessible live region, refresh counts/list from the server, and return focus predictably. The interface does not automatically approve, auto-open an unrelated record, or hide a failed mutation.

### Responsive and accessibility contract

- Core review and decision behavior works from 320px through desktop without horizontal page scrolling.
- Interactive targets are at least 44 by 44 CSS pixels with visible keyboard focus.
- Desktop detail width preserves readable line length; mobile uses one vertical reading order and a footer that does not cover content.
- The detail sheet traps focus, closes with Escape when safe, returns focus to its originating row, and warns before dismissing entered rejection text.
- Loading uses stable skeleton space; empty, partial-degradation, stale, permission, and server-error states state both the cause and recovery action.
- Light/dark contrast meets WCAG AA, status never relies on color alone, browser zoom to 200% remains usable, and reduced-motion preferences are respected.
- Existing SoleSpace typography, colors, spacing, radii, icons, and dark-mode conventions are reused. Phase 4 adds no new design dependency or standalone theme.

## Family-specific safety requirements

### Refund

OFF routes from Finance initial review to Finance final/execution. Return receipt, delivery-attempt, refundable amount, payment method, gateway idempotency, and already-refunded gates remain mandatory. Owner approval, when included, authorizes the request but never executes payout by itself.

### Price

Product, repair-service, and repair-package requests construct either the existing three-level role map (ON) or existing Finance-only map (OFF). Only Finance final approval applies the proposed price. Current-price baselines and rejection restoration remain intact.

### Payslip

ON preserves Finance -> Owner -> Finance -> Finance Final. OFF removes only Owner and preserves the remaining ordered Finance checks. Single, batch, legacy, and disbursement paths must honor the snapshotted role map.

### Salary Adjustment

ON reserves the decision stage for the Shop Owner. The proposed OFF route to a non-proposer HR/Manager reviewer must first be proven as an existing authoritative decision path; a generic permission or ability to apply salary is insufficient. If that proof fails, implementation stops for this family. No decision directly mutates salary; HR applies only an approved effective-dated change.

### Purchase Request

ON uses Finance initial -> Owner -> Finance final. OFF uses Finance initial -> Finance final. Retired auto-approval behavior cannot skip Finance stages, and Purchase Order creation remains restricted to approved requests.

### Expense

Only manual Expense approval requests use this toggle. ON constructs Finance -> Owner; OFF constructs Finance-only. Procurement-receipt and payroll-generated expenses remain outside this manual workflow, and settlement cannot advance a pending approval.

### Repair Reject

The toggle controls only the owner review between repairer rejection request and Manager final review. When ON, owner rejection preserves existing reassignment behavior. When OFF, Manager remains responsible for the final decision. Neither path permits premature terminal rejection.

## Rollout sequence

1. Start from completed Phase 3C tip `0124f7228` and freeze the current seven-family state/route matrix.
2. Characterize and prove the authoritative OFF destination for each family; stop and return to focused design review for any family that lacks one.
3. Expand Settings and central policy tests only for families whose ON and OFF contracts are approved.
4. Add immutable policy snapshots and conservative backfill rules where existing records lack them.
5. Implement and verify one family at a time, including ON, OFF, and in-flight toggle-change tests.
6. Replace the seven owner-page entry points with one accessible Action Center master-detail workspace, then align notifications and legacy redirects.
7. Complete sequential standards, specification, security, reuse, dead-code, and verification reviews.

## Acceptance criteria

1. Settings exposes a working ON/OFF toggle for all seven approval families.
2. ON includes a real tenant-scoped Shop Owner stage; OFF omits only that stage and preserves the remaining workflow.
3. Toggle changes do not reroute in-flight records.
4. Invalid or missing configuration fails safe to owner-required.
5. Every implemented OFF path points to a characterized, already-authoritative downstream decision stage.
6. No fallback approver, permission, status, transition, or synthetic approval workflow is invented to support OFF; a family without a valid route stops for focused design review.
7. No client flag, registration shortcut, generic permission, retired job, or legacy amount limit overrides the snapshotted decision.
8. Wrong-shop, wrong-role, repeated, stale, and self-approval attempts are denied.
9. Downstream money and state effects remain separate, idempotent, and auditable.
10. The sidebar has one Action Center entry instead of a seven-link Approval Pages submenu.
11. All seven approval families can be filtered, opened, understood, approved, or rejected without leaving the Action Center when their workflow contract permits owner action.
12. The shared queue and decision panel remain usable on mobile, keyboard, screen reader, dark mode, and 200% zoom, with clear loading/error/stale feedback.
13. Legacy approval URLs and notifications resolve to the matching Action Center item without cross-shop disclosure.
14. Notifications and Action Center items appear only when owner action is truly required.
15. Existing domain services and Phase 3 Action Center architecture are reused; only minimal snapshot schema is added where required.
