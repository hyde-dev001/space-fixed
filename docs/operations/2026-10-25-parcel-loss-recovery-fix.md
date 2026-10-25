# Parcel-loss approval and incident visibility correction — October 25, 2026

Worktree `.worktrees/failed-delivery-recovery`, branch `codex/failed-delivery-recovery`. Local implementation only: no commit, push, deployment, production connection, production migration, environment edit or dependency change. Existing unrelated output is retained.

## Reproduced causes

- `Shipments.tsx` showed attempt/proof badges but no open-incident indicator before opening delivery details. It now shows **Incident needs review / Review incident** and preserves **Parcel loss confirmed** after investigation closes.
- `OrderRefundService::reserveConfirmedLossRefund` initialized non-COD company claims with an automatic owner-approved flag, while the approval service and Finance UI still required an actual Staff decision. Staff UI required a pending flag, creating an unreachable review stage. New company loss claims now start pending. Existing system claims are reviewable only when their request identity matches an actual cancelled, confirmed-loss leg in the same shop/order and no Staff/owner decision or Finance approval has been recorded. A real Staff approval replaces only the old automatic flag; it never invents an approval actor or bypasses snapshotted owner requirements.
- `notifyRefundApprovalRequested` excluded company system-loss claims from Staff routing; `LogisticsNotificationService` notified order Staff only for cancellation. Company loss reservations now request Staff review; internal incident/loss occurrences notify Staff with the existing persistent after-commit delivery and occurrence deduplication. Historical processed events are not replayed automatically.
- Staff status/Finance detail copy could request inspection or imply the wrong review stage for an unreturned lost parcel. Staff receives an explicit server-authorized review action; Finance explains the pending actor and retains its normal approval/payout gates. Loss payout-ready text no longer claims that returned stock was inspected.
- Staff **Activate Receive** relied on order status/carrier text without checking confirmed loss. A failing HTTP test demonstrated a 200 activation on a lost parcel. It is now hidden for a projected loss and denied with 422 when the order's current shipment has a confirmed-loss leg. No receipt, delivered status, stock or payment is manufactured.

## Verification

The new approval tests first reproduced the pending-versus-approved mismatch and legacy Staff 422. UI tests reproduced the missing incident indicator, missing legacy review action and misleading Finance notice. Notification tests reproduced zero Staff notices. The receipt-activation regression first reproduced an invalid successful activation.

| Check | Result |
| --- | --- |
| Broad Logistics/COD/refund/inspection/repair/employee MFA suite | 700 tests / 4,002 assertions, exit 0; one GD-disabled fallback skip. This run preceded the final receipt-activation guard. |
| Final approval/incident/notification/tracking suite including receipt guard | 44 tests / 434 assertions, exit 0; the same fallback skip in a GD-enabled process. |
| Separate actual GD-disabled fallback | 1 test / 20 assertions, exit 0. |
| Broad Logistics/Finance/Staff frontend suite | 27 files / 338 tests, exit 0; before the final receipt guard and resolved-loss badge test. |
| Final focused UI suite | 3 files / 89 tests, exit 0. |
| Authenticated Chromium 151.0.7922.34 | Separate rider, dispatcher, Staff and Finance accounts; normal password/TOTP/camera Clock In; actual incident upload, list indicator, loss investigation, Staff notices, Staff eligibility approval, Finance approval and enabled payout action. Final rerun verifies Activate Receive is absent and a stale activation POST receives 422. |
| Persisted browser records | Actual Staff/Finance actors, `return_status=not_required`, product stock stays 5, payout eligible. No provider payout executed. |
| Fresh final build | Exit 0, 28.43 seconds; 406 manifest entries and all referenced assets/imports resolve. All 24 tracked OCR runtime files unchanged; OCR integrity check passed. |
| Hygiene | PHP syntax and diff checks passed; no environment/package/lock/OCR changes; index empty. Dedicated loopback listeners stopped; synthetic databases/evidence retained. |

Ignored logs are `output/loss-recovery-*.log`. Browser fixture, record check, results and screenshots are under `output/failed-delivery-recovery/loss-recovery/`; output-local runners reuse the existing guarded MariaDB environment. These are synthetic evidence, not release artifacts or live financial transactions. The [manual guide](failed-delivery-recovery-manual-testing.md#confirmed-parcel-loss--paid-retail-delivery) includes next-page instructions.

Sequential review: existing services, fields, permissions and notification mechanisms reused; no new migration or logistics/refund engine. Removed UI ambiguity without relaxing approval, attendance, tenant, custody or captured-payment gates. Changed TS/React boundaries reviewed; no heavy dependency or new lazy split. Root-cause failing tests precede fixes. No performance improvement is claimed. No configured TypeScript/lint pass is claimed.

Fresh browser coverage is company-shop online-paid Retail Delivery loss with owner approval disabled by its explicit test-shop policy. Required-owner stages, legacy continuation, foreign-shop denials, COD holds and financial/return safeguards have automated regression coverage; this is not a new COD-loss or every-shipment-purpose browser matrix. Provider settlement, real-host files, scheduler operation and existing payout-screenshot privacy remain outside this local correction. Existing ambiguous/manual loss rows that fail the identity/evidence predicates remain blocked for investigation; no bulk repair or automatic approval is authorized.
