# Shop Owner Phase 5 Maker/Checker Matrix

This matrix freezes the seven Phase 4 approval families for Phase 5 route
retirement. It relies on the authoritative [Phase 4 approval
matrix](shop-owner-phase-4-approval-matrix.md), not permission names or
legacy page visibility. `ON` is the existing owner stage. `OFF` skips only
that stage and uses the existing checker named below; it is never approval,
release, payout, application, settlement, disbursement, or terminal rejection
by itself.

`N/A` in a creation/correction column means the current evidence establishes
no owner initiation/correction transition. It must not be filled by a legacy
page redirect. A correction that can be proved only by an unreviewed
authorization guess is an explicit Phase 5 exclusion.

## Revision notice (2026-08-25)

This table remains the baseline evidence for the existing staff-maker, owner-
stage, OFF-authority, and correction paths. The revised Phase 5 design
supersedes the interim rule that treated an owner-maker path as blocked merely
because the owner stage was enabled. Before implementation, Task 1 must
re-characterize each owner-maker route and record one of the following:

- a proven independent Finance or designated-authority route that omits the
  maker's own owner-authorization stage;
- an explicitly characterized low-risk automated/direct policy; or
- a safe unresolved state that keeps the record out of the owner's approval
  queue; no owner-maker path is enabled without that authority.

No owner-made record may be approved by the same Shop Owner, and this revision
does not authorize a fallback approver, synthetic status, or hidden approval.

| Family | Owner initiation path and creation boundary | Staff maker field | ShopOwner maker field | Maker set at creation | Different submitter supported | ON behavior | Proven OFF authority | Existing correction transition | Authorized correction actor | Correction state guard | Correction audit | Correction notifications | Correction downstream effects | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Refund | No owner-initiated creation is assumed. `OrderRefundService::reserveOrderRefund` creates a locked, idempotent reservation from order/return flows. | return/finance requester context; finance reviewer fields | `OrderRefund.shop_owner_id` is the tenant, not a maker field | reservation payload under `DB::transaction`; order lock and idempotency key | yes; request/return workflow actor may differ from owner | exact owner calls `approveRequestedRefund` at `shop_owner` stage | same-shop Finance calls `RefundApprovalController::financeApprove` / existing Finance branch | N/A — rejection/re-request semantics were not characterized as a correction transition | N/A | N/A | existing reviewer IDs/timestamps and notification path record approval, not a correction | existing `dispatchRefundApprovalNotifications` for stage outcome | payout remains separately guarded by received return, finance/owner approval, lock, and gateway checks | N/A_NO_OWNER_INITIATION |
| Price | No owner initiation. Staff submits `PriceChangeRequest` with an `Approval` role map. | request proposer/reviewer fields on `PriceChangeRequest` | exact owner identity is an Approval role, not maker | `Approval.approval_roles` is frozen when the request is created | yes; staff proposer and owner checker are distinct | owner level 2 uses `PriceChangeApprovalService::approvePriceChange` | existing one-level Finance `ApprovalService::approve` path | No authoritative amend/resubmit transition identified in Phase 4 evidence | N/A | N/A | `Approval::recordLevelReview` covers approval levels only | existing approval notifications only | price application stays in final reconciliation after approval | EXPLICITLY_EXCLUDED |
| Payslip | No owner initiation. HR/payroll submission creates the v4 Approval workflow. | payroll/HR creator fields; level reviewers | exact owner is level-2 checker, not maker | v4 `Approval.approval_roles` and workflow version at submission | yes; HR/payroll maker is different from owner | existing level-2 owner decision under `Approval::canApprove` | existing Finance checker/final stages in the OFF role map | No authoritative correction/resubmission transition identified; payroll adjustment must not be inferred | N/A | N/A | approval history and payroll reviewer fields only | existing payroll/approval notifications only | final approval makes payroll ready; disbursement remains separate | EXPLICITLY_EXCLUDED |
| Salary Adjustment | No owner initiation. A proposer creates `SalaryChange`; only the non-self-proposer `auth:user` reviewer path is proven as a checker. The Action Center adapter excludes the matched ShopOwner self-proposer. | proposer identity; proposer self-review is denied at the owner boundary | owner actor saved as approver, not maker | `SalaryChange` pending record; proposer field is the maker boundary | yes, for the characterized non-proposer `auth:user` reviewer only; ShopOwner self-proposer submission/decision is excluded | `SalaryChangeController::approve` records only the proven non-self-proposer decision | existing non-proposer `approve-salary-change` reviewer through `SalaryChangeApprovalService::approveSalaryChange` | No existing correction transition was characterized; separate HR `apply` is not correction | N/A | N/A | `approved_by`, `approved_at`, notes and `auditCustom('salary_change_approved')` cover the proven success path | approval notifications, if any, are not proof of a correction flow | owner self-proposer records are kept out of the owner queue and direct owner decisions fail before mutation | EXPLICITLY_EXCLUDED |
| Purchase Request | Owner does not create a request in the approval path. `PurchaseRequestService::submitToFinance` submits a draft. | `requested_by` / requester protections | `approved_by_shop_owner_id` is checker, not maker | submission to `pending_finance`; requester is frozen on record | yes; Finance reviewer must not be requester | exact owner `approveByShopOwner` moves to `pending_finance_final` | same-shop non-requester Finance `releaseByFinance` final decision | No authoritative edit-and-resubmit correction transition identified | N/A | N/A | reviewer/owner/final fields, `appendApprovalNote`, stage notifications | existing stage notifications only | PO conversion still requires `approved`; no correction may create a PO | EXPLICITLY_EXCLUDED |
| Expense | Owner may create a manual expense through `shop_owner.finance.expenses.store`, but Phase 4 approval evidence does not establish it as a valid owner-maker/checker path. Creation locks source to manual workflow. | expense creator/submitter from controller request context | owner is a possible creator and later exact owner stage; owner initiation is not enabled in Phase 5 | manual Expense + Approval role map at submission; generated procurement/payroll sources excluded | unknown for owner-created manual expense; no self-check proof in authority matrix | exact owner stage only for high-value manual role map | existing Finance `ExpenseApprovalService::approveExpense` one-level manual route | No authoritative correction transition identified | N/A | N/A | approval history/expense approval fields only | existing approval notifications only | owner-created manual expenses remain unavailable until an independent maker/checker route is characterized; staff-created path remains authoritative | EXPLICITLY_EXCLUDED |
| Repair Reject | No owner initiation. Repairer rejection creates `RepairRequest.status=repairer_rejected`; Manager starts review. | repairer rejection actor/reason/time, Manager reviewer | owner reviewer fields `owner_reviewed_by[_id]`, not maker | repairer rejection state then Manager decision establishes owner pending state | yes; repairer maker, Manager, and owner are distinct | exact owner `approveOwnerRejection`: `owner_approval_pending` → `manager_reviewing` | Manager with `userHasManagerReviewAccess` finalizes from `manager_reviewing` | Existing owner rejection return-to-flow path is visible in controller comments, but Phase 4 does not characterize its exact transition/audit/notification contract | unproven | unproven | unproven | unproven | only `finalizeRejection` can reach terminal `rejected`; no owner correction or fallback action is enabled | EXPLICITLY_EXCLUDED |

## Resolution checkpoint

All seven OFF authorities remain authoritative: `PROVEN` in the Phase 4 matrix.
Refund remains `N/A_NO_OWNER_INITIATION`. Price, Payslip, Salary Adjustment,
Purchase Request, Expense, and Repair Reject are explicitly excluded for the
uncharacterized owner-maker and correction expansions. Phase 5 preserves their
proven staff-maker decision paths and read/redirect evidence, but does not
implement an owner correction, retry, amendment, resubmission, fallback
approver, or self-approval bypass.

| Family | Owner-maker route | No independent route behavior | Owner UI/API behavior | Focused evidence | Verdict |
| --- | --- | --- | --- | --- | --- |
| Refund | N/A; no owner initiation path is in scope | no owner-created refund record is inferred | existing Action Center stage remains available only for characterized records | Phase 4 refund workflow tests | N/A_NO_OWNER_INITIATION |
| Price | no owner creation; staff maker remains the only characterized route | no amend/resubmit route is invented | owner approval remains the existing level-2 decision; no owner-maker/correction action | `PriceChangeApprovalWorkflowTest` | EXPLICITLY_EXCLUDED |
| Payslip | no owner payroll generation or maker route | no payroll correction/resubmission is invented | owner level-2 decision remains available for HR-created payroll | `PayslipApprovalWorkflowTest`, payroll denial tests | EXPLICITLY_EXCLUDED |
| Salary Adjustment | owner self-proposer is not an owner decision route | owner self-proposer is excluded from queue and direct decision | owner UI/API returns no actionable item and direct self-proposer decision is denied | `ApprovalAuthorityCharacterizationTest`, Action Center owner self-proposer coverage | EXPLICITLY_EXCLUDED |
| Purchase Request | owner creation is not enabled in the approval path | no edit-and-resubmit correction is invented | staff/Finance-created request keeps existing owner stage; no owner-maker action | `PurchaseRequestWorkflowTest` | EXPLICITLY_EXCLUDED |
| Expense | manual owner creation is not enabled until independent routing is characterized | no correction or self-approval fallback is invented | staff-created owner-stage decisions remain; owner creation is denied | `ExpenseApprovalWorkflowTest`, `ExpenseSettlementTest` | EXPLICITLY_EXCLUDED |
| Repair Reject | no owner rejection initiation | no return-to-flow/correction action is invented | characterized owner review remains; unresolved correction stays unavailable | `ManagerRepairRejectionTest`, `ApprovalAuthorityCharacterizationTest` | EXPLICITLY_EXCLUDED |
