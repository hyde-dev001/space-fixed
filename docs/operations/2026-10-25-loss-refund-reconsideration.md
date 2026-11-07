# Owner-authorized loss-refund correction ? October 25, 2026

Worktree: `.worktrees/failed-delivery-recovery`; branch: `codex/failed-delivery-recovery`; unchanged HEAD: `da57cca9b29f800c7ce9dfb91ba98bba8c42d3d4`. The approved change adds **Shop Owner ? Approval Center ? rejected loss refund ? Send back for correction**. Existing uncommitted parcel-loss fixes are preserved. No commit, push, rebase, merge, deployment, production migration, dependency installation or environment-file edit was performed.

## Behavior

A company shop's rejected confirmed-loss claim becomes an actionable owner review, including when normal owner monetary approval is optional. The owner reads the original rejection/reviewer and existing evidence/notes, records correction instructions and confirms. A new linked refund starts with Staff and Finance pending; existing required owner approval cannot be downgraded. Staff receives its normal persisted review notice and sees the owner's instructions on Retail Job Orders. Finance explains when owner reconsideration is needed instead of repeating an impossible Staff-waiting instruction.

The parent remains rejected with its original reason, decision actor and financial history. Correction is recorded separately using existing replacement/recovery fields and an audit entry; it remains in Approval Center History with the linked request and instructions. Repeated rejection of the child requires another owner decision. Assignment, customer receipt, physical return, stock restoration and payout are not manufactured. Internal instructions are kept out of customer-facing refund notes.

The operation locks the existing order and rejected refund, reuses the captured-payment/reservation service and rejects altered replays, duplicate parent corrections, foreign-shop access, non-loss markers, provider-touched claims and conflicting reservations. Collected COD cash and remittance/payout gates remain required. One saved request and one identical replay were confirmed using two independent PHP processes on the guarded disposable MariaDB 10.4.32 server. No new schema or payment engine was introduced.

## Scope

- `app/Models/OrderRefund.php`: shared eligibility scope and rejected-predecessor relationship.
- `app/Services/OrderRefundService.php`: owner-only correction orchestration, existing reservation/approval policy reuse, audit and actor notices.
- `app/Http/Controllers/Api/RefundApprovalController.php`, `StaffOrderController.php`: tenant-scoped action/detail projections and Staff instructions.
- `app/Services/OwnerActionCenter/Adapters/OrderRefundAttentionAdapter.php`, `OwnerApprovalHistoryService.php`: pending reconsideration and retained correction history.
- `routes/shop-owner-api.php`, `config/shop_modules.php`: explicit owner financial POST route and catalog metadata.
- `resources/js/components/owner-action-center/OwnerApprovalDetailPanel.tsx`, `approvals/RefundApprovalDetails.tsx`: correction-only form, confirmation, stable accessible label, pending/maintenance/error handling and history details.
- `resources/js/Pages/ERP/STAFF/JobOrders.tsx`, `resources/js/Pages/ERP/Finance/refundApproval.tsx`: responsible-actor guidance. Existing initial parcel-loss fixes in these files remain.
- Regression tests: `tests/Feature/Logistics/LossRefundReconsiderationTest.php`; owner detail-panel and Finance return-gate frontend suites.
- Approved plan, this report, manual guide Section 15, durable learning note and fresh `public/build` assets. Ignored/output-local test helpers, synthetic databases, logs and screenshots are not release artifacts.

## Verification

Evidence is retained locally under `output/failed-delivery-recovery/loss-recovery/reconsideration/` and `output/loss-reconsideration-*.log`.

| Check | Observed result |
| --- | --- |
| Initial owner correction regression | Domain/HTTP behavior and correction UI reproduced missing behavior before implementation; initial focused cases then passed. |
| Catalog + initial domain | 27 tests / 31,541 assertions passed. Explicit new POST classification passes; no security middleware removed. |
| Refund/COD/owner-security/MFA/catalog final targeted command | 140 tests / 32,402 assertions passed. Includes collected-COD and unsafe-source cases. |
| Final history/domain/security command | 32 tests / 255 assertions passed; `loss-reconsideration-history-green.log`, rerun after the history/projection correction. A separate failing history regression reproduced the missing optional-policy Staff-rejection entry before its fix. |
| Broad backend command | 785 tests / 4,650 assertions; one existing HR coverage assertion failure and one expected GD-disabled fallback skip. The failing suspension test expects neither termination nor rehire coverage although both are configured in unchanged HEAD. Neither its assertion nor the HR registry was modified. No all-green broad-suite claim. |
| Broader affected frontend | 21 files / 212 tests passed, covering owner details, Staff, Finance and dispatcher pages. |
| Label/accessibility frontend | 5 files / 33 tests passed; final detail renderer/dialog rerun: two files / 22 tests passed after the last history fields. No TypeScript compiler or lint pass claimed. |
| Authenticated desktop browser | Chromium 151.0.7922.34: normal rider/dispatcher/Staff/Finance password, TOTP and camera Clock In; owner password/TOTP. Real UI loss report ? dispatcher loss confirmation ? Staff rejection ? owner correction ? Staff approval ? Finance rejection ? owner correction ? fresh Staff/Finance approvals. Passed; no payout executed. |
| Persisted record checks | Two original rejections and actors preserved, two actionable owner notices/deep links, two Staff correction notices, two audit entries, stock remains five, final payout eligibility true and no provider payout/execution evidence. |
| Concurrency | MariaDB 10.4.32: two independent simultaneous identical corrections produce one child, one created response and one replay. This is one new correction race, not a rerun of the entire logistics race matrix. |
| Mobile/keyboard | 390?844: stable correction-field label, focus wrap, no horizontal overflow, confirmation cancellation preserves form. Screenshot inspected. |
| Production assets | Fresh `pnpm run build`, exit 0, 24.59 seconds; 406 manifest entries / 407 referenced files all resolve. All 24 tracked OCR runtime files match HEAD byte-for-byte. `asset-integrity.json` records the digest. No development `public/hot` change. |

The browser runner's early attempts exposed a wrong accessible button name, the real first-login welcome dialog and an automatic success-alert dismissal assumption. Those harness attempts are not acceptance passes. Mobile testing also found a real implicit-label instability after typing; explicit label association fixed it, and the final mobile check passed. Screenshots are synthetic-only: `owner-rejection-1.png`, `owner-rejection-2.png`, `staff-correction-instructions.png`, `finance-loss-payout-ready.png`, `owner-correction-mobile.png`.

Commands used (from this worktree):

```powershell
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/LossRefundReconsiderationTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Feature/Cod tests/Feature/ShopOwner/ActionCenter/OrderRefundAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Unit/BusinessScaling/ShopModuleCatalogTest.php tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php --display-skipped
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/LossRefundReconsiderationTest.php tests/Feature/ShopOwner/ActionCenter/OrderRefundAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/PhaseThreeDecisionCharacterizationTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php
pnpm run test:frontend resources/js/components/owner-action-center/__tests__ resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/Pages/ERP/Finance/__tests__ resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
python output/failed-delivery-recovery/loss-reconsideration-browser.py
python output/failed-delivery-recovery/loss-correction-race.py
python output/failed-delivery-recovery/loss-correction-mobile.py
php -d extension=gd output/failed-delivery-recovery/loss-reconsideration-records.php
pnpm run build
node scripts/prepare-owner-ocr-assets.mjs --check
git diff --check
```

The installed cached pnpm was invoked through `npm.cmd exec --offline --package=pnpm@11.14.0 -- pnpm ...`; package-store reads required sandbox escalation. No dependency was installed/updated. New PHP test formatting passed Pint; the existing model has unrelated Pint conventions, so no repository-wide formatting pass is claimed. Syntax and whitespace are checked separately.

## Sequential review and remaining limits

Simplification/reuse: existing reservation, policy snapshots, owner adapter/detail/history, notifications, audit and links; no migration/dependency/parallel workflow. Standards and spec: owner-only authorization, immutable parent, linked child, real review stages and history checked. TS/React: typed state, stable definition dependencies/labels, request-in-flight guard, safe text rendering and maintenance/pending behavior. Bundle splitting: small existing UI path; no heavy import added. Improvement evidence: the failing correction/history cases and desktop/mobile journeys; speed/load improvement not measured. Security/Laravel: same-shop/order/customer loss evidence, financial caps, COD and provider-touch denial, CSRF/MFA inherited from existing routes, private instructions and no payout call. Fresh verification evidence is listed above.

No production sign-off is implied. The separately deferred repair-refund payout screenshot privacy issue remains unchanged. Hostinger configuration, live provider settlements, production load, external email delivery and unrelated repository suites were not verified. Existing notification delivery conventions remain; this change does not add a new reliable-email/outbox system. The seven prior logistics migrations and matching source/build/runtime still require the separately approved release process; this follow-up adds no migration.


Final hygiene: PHP syntax passed for all 13 changed/new PHP paths; `git diff --check` passes. HEAD and the empty staging index are unchanged, and prior dirty-file hashes outside the intentional correction extensions match the saved baseline. Final build manifest SHA-256: `c4a099719bc865f059bc027b062220ca705b18cec0186ec169fb2c14e55c19f3`. Authenticated owner History also opened the preserved Staff rejection/instructions read-only. The dedicated MariaDB, PHP and Vite listeners were stopped; synthetic databases/evidence remain local. All changes remain unstaged.
