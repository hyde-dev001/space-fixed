# Four QA Follow-up Implementation Plan

**Goal:** Fix review report persistence, independent report resolution, appeal notification routing, and Finance business-date validation on origin/solespace-b.
**Base:** 14e3db0453; branch `fix/qa-four-follow-up`. Execute sequentially; preserve other worktrees.
**Constraints:** Keep tenant isolation, privileged authorization, Finance RBAC, attendance, module gates, suspension provenance, notification isolation, and audit history intact. No new dependencies or migrations unless required.

- [x] QA1: Trace CRM and owner review controllers, ReviewReport, CustomerReviews, and moderation integration. Add failing API and component tests for persisted report state, dismissed-history duplicate rejection, separate reviews, and tenant isolation. Expose history by exact review/shop, serialize report creation, and update modal from authoritative success.
- [x] QA2: Extend FlaggedAccountWorkflowTest for suspend/dismiss and suspend/suspend on separate reports, retry, side-effect counts, and invalid suspension identity. Reuse AccountSuspensionService validation and locking without relaxing unrelated suspension operations.
- [x] QA3: Trace SuspensionAppealService, notification API, click resolver, and canonical admin route. Add a failing notification-click test; repair the narrow URL boundary and preserve mark-read and principal authorization.
- [x] QA4: Extend Finance regression coverage at the UTC/Manila boundary for yesterday/today/tomorrow and update. Reuse app.shop_timezone, expose the server business date to the native date input, and display backend validation messages through existing modal conventions.
- [x] Run focused tests after each fix, related regression suites, frontend tests/build, and git diff --check. Review Standards, Spec, security, simplification, TS clarity, reuse, and dead code sequentially.
- [x] Record results, files changed, migrations, exact commands, review gates, and remaining manual acceptance checks. Record only durable learning.
