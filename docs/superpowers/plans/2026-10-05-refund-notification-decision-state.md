# Executed repair refund notification and decision controls

Branch/worktree: `fix/platform-fee-balance`, `.worktrees/platform-fee-balance`; starting commit `e42ac376a540d5c408ea20e20082af7df6bdc4fc`. Initial working tree is clean. This is a follow-up to the user's executed-refund screenshots, after the earlier feature push.

## Root cause and acceptance

- `RepairPosRefundService::markRefundSucceeded` sends the executed notice through `notifyRefundParties`. Its owner URL fallback always calls `NotificationService::ownerApprovalActionUrl`, which selects `bucket=needs_my_decision`, even for `succeeded` refunds.
- `ActionCenter` creates a fallback selected item for an old deep link and sets `owner_action_required` when the view is pending. Queue membership alone therefore cannot establish a current decision.
- `RepairRefundWorkflowController::transformApprovalRefund` correctly sends `status: Refunded`, `rawStatus: succeeded`, and `owner_projection.owner_action_required: false`. `RefundApprovalDetails` renders the raw state, but `OwnerApprovalDetailPanel::isTerminal` only examines the display status and does not recognize Refunded or Processing. It also ignores the server's negative owner-action projection.
- Backend `RepairPosRefundService::approve` and `reject` already deny the repeated decision. The screenshot demonstrates a misleading UI, not proof that a second refund was paid.

Acceptance: new resolved owner notifications open History; existing links load current details without Approve/Reject for executed or processing refunds; negative server owner-action projections remain read-only; pending owner decisions remain available; repeat-decision APIs still reject final refunds. No financial state, authorization, provider verification, existing notification row or environment file is rewritten.

## File-level plan

1. Add failing modal regressions using the actual camel-case API projection, plus a positive pending decision. Add a real manual-refund/inbox/API regression in `NotificationCriticalFlowsTest`.
2. Extend the existing validated `NotificationService::ownerApprovalActionUrl` with an optional History view. Route resolved fallback owner refund notices through it; preserve explicit pending review links and customer destinations.
3. Reuse the refund raw status and negative owner projection in the shared modal eligibility check. Reuse the existing read-only footer and modal styling; gate the submit handler with the same decision condition.
4. Run narrow frontend/backend tests, then adjacent approval/history/refund coverage, build, PHP syntax and diff hygiene. Review Standards, Spec, security, typed boundaries, reuse and dead code sequentially.

## Verification

Logs are under `%TEMP%/solespace-refund-notification-20261005/`.

| Check | Result |
| --- | --- |
| Original modal regression | RED: four read-only scenarios exposed Approve/Reject. Positive pending decision passed. |
| Real manual refund/inbox/API regression | RED: succeeded refund owner notice stored a pending-decision URL. GREEN: 1 test/10 assertions, including actual API projection and repeat-decision 422 guards. |
| Legacy nested status compatibility | RED: one legacy order-refund shape exposed controls after the initial refund-specific branch. Existing approval-status fallbacks restored. |
| Final adjacent frontend | PASS: 7 files/54 tests, exit 0, 15.15 seconds. `pnpm run test:frontend resources/js/components/owner-action-center resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/Pages/ShopOwner/Approvals/__tests__/ActionCenterDeepLinks.test.tsx --maxWorkers=2`. |
| Adjacent backend | PASS: 12 files/173 tests/1081 assertions, exit 0, 44.610 seconds. `php -d memory_limit=1536M vendor/bin/phpunit --log-junit <temp>/backend-adjacent.xml <12 paths>`; temporary process environment explicitly testing/SQLite/:memory:. Covers notifications, refund execution/split/recovery/authorization/source boundaries, resolution exclusion, owner routes/history/security, attention adapter and owner projection. |
| Production build | PASS: `pnpm run build`, exit 0, 34.98 seconds. Tracked build output refreshed; 395 manifest entries/397 files; all file/import references exist. |
| Syntax / hygiene | `php -l` passes for both changed services and changed PHP test; `git diff --check` passes. |
| Authenticated browser | Connector inventory has no enabled apps/browsers. Actual authenticated retry remains manual. No real refund/provider activity was performed. |
| Full-suite limits | Full frontend/Composer suites were not rerun for this small follow-up. The previous published commit's 1813 frontend passes and 3462/371/12 Composer totals are historical evidence, not a fresh full-suite result for these changes. No TypeScript compiler/linter claim. |

## Sequential review

Standards/Spec/security: only the validated URL helper, shared owner-notification fallback and shared modal decision gate changed. Existing pending-review overrides and customer `/my-repairs` destinations remain. Terminal/current server-projection vetoes apply to both refund sources; legacy nested approval statuses remain supported. Other approval types retain their previous status logic, including active packages with pending price approval. The submit handler and visible footer use the same eligibility condition. No API decision, money, tenant, RBAC, attendance, module or provider rule changed.

Simplify/reuse/dead-code: existing URL validation, History view, raw status, owner projection, modal, footer and type guard reused. No dependency, component, endpoint, migration or unused import added; no `NotificationService` subclass overrides found. Typed unknown detail handling and literal source types retained; no `any` added. Karpathy scope review passes. No new heavy import or code split; bundle/performance improvement not measured. Verification evidence is the red-to-green behavior above, not a full-suite or deployed completion claim.

## Manual retry

Serve this worktree, refresh the browser and open the existing executed notice: details should show Refunded without Approve/Reject, even when its saved URL still says needs_my_decision. A new executed notice should select History and remain read-only. Processing refunds should remain read-only; a genuinely pending owner refund should still permit its authorized decision. Do not execute a real refund merely to test this presentation fix.

## Follow-up publication checks

The user authorized a follow-up commit and feature-branch push. A fresh `git fetch origin --prune` confirmed the starting feature remote at `e42ac376a540d5c408ea20e20082af7df6bdc4fc` and the target base unchanged at `92759450c2dda69ec10c691631edd62398385e91`; no additional rebase was needed.

Before staging, the same focused frontend command passed again: 7 files/54 tests, exit 0, 50.93 seconds (`frontend-publication.log`). The same 12-file backend command passed again in testing/SQLite/:memory:: 173 tests/1081 assertions, exit 0, 52.893 seconds (`backend-publication.log`, `backend-publication.xml`). All 397 production build files match the SHA-256 fingerprint captured after the successful build; no source implementation changed after that build. Diff hygiene passes.

Publication is limited to the eight intended source/test/documentation paths above and tracked `public/build`. The final commit SHA and remote confirmation are reported in the handoff. No merge into `solespace-b` or deployment is authorized or performed.
