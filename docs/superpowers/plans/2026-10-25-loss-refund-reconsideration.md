# Owner-authorized loss-refund reconsideration

Approved through the October 25 conversation: use **Shop Owner → Approval Center**; the owner reviews rejection history and sends the case back for correction, without approving a payout. Preserve the existing uncommitted parcel-loss fixes, private evidence and financial authority.

- [x] Add failing domain/HTTP tests, then an atomic owner-only correction operation in the existing refund service/controller. Preserve the rejected row and its decisions; create a linked request through existing reservation/captured-payment checks. Require reason, request identity and same-shop confirmed loss; reject concurrent/altered replays and unsafe financial states.
- [x] Reuse the existing order-refund Approval Center adapter/detail panel. Include qualifying rejected loss claims even when normal owner monetary approval is optional. Show **Send back for correction**, original rejection and correction history. Restart Staff and existing Finance/owner approvals; send existing persisted notifications to the responsible actors.
- [x] Verify owner/employee/foreign-shop boundaries, duplicate requests, repeated rejection cycles, preserved original decisions, no refund over-reservation and no premature payout. Run focused tests, catalog/security regressions, authenticated synthetic browser actions and a fresh build/OCR integrity check. Update the manual guide with next pages and actual verification limits.

Likely areas: `OrderRefundService`, `RefundApprovalController`, `OrderRefundAttentionAdapter`, owner refund projection/detail components, owner route/catalog, existing Staff/Finance projections, tests and the operations guide. Reuse `replacement_refund_id`, existing audit records and reservation locks before introducing schema. Do not relabel a rejected approval as a failed provider payout. No commit, push, merge, deployment or production migrations are authorized.

Verification and exact limitations: [execution record](../../operations/2026-10-25-loss-refund-reconsideration.md). Existing unrelated HR coverage assertion and deferred payout screenshot privacy finding are recorded separately. No Git publication or deployment performed.
