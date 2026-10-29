# Individual owner payment and refund access fix

User request: restore individual owners' Platform Balance access and let their authorized retail refunds proceed without a Finance employee.

Contract: owner-authenticated, tenant-scoped platform balance APIs are core self-service routes. Individual retail refund approval satisfies the required approval stage; company Staff/Finance/Owner rules remain. Already owner-approved individual refunds work without rewriting Finance history. Actual return/inspection, COD remittance/destination, amount, provider and replay guards remain. No production data repair, live money operations, migration or dependency change.

Sequential work:

1. Reproduce core page/API mismatch with module enforcement enabled, and individual approval/execution failures with no employee fixture.
2. Reuse the platform balance core route contract for its APIs; centralize required refund approval checks and update owning projections/actions.
3. Run focused platform/refund/COD and frontend regressions, review tenant/actor/payment boundaries, build locally and record evidence.

Likely areas: `config/shop_modules.php`, `OrderRefund` model, `OrderRefundService`, refund/order projections and existing Platform Balance, owner retail, customer orders and Approval Center views. Preserve unrelated attendance edits. Tests use disposable SQLite and provider fakes; no concurrent PHPUnit. Publication and production operations are outside this new fix request.

Initial red: five failures in `output/individual-owner-finance-red.log`: individual balance read/payment/reconciliation returned 403, owner approval left return awaiting approval, and an already owner-approved received refund was not executable.

Implemented flow:

- Platform Balance page and its owner APIs share the core self-service contract. The authenticated owner can load/pay/reconcile their balance without a Finance module. Existing company payment requests and provider/payment guards remain in their existing services.
- Individual retail requests use the snapshotted owner-approval policy. When required, the actual owner approves; when disabled, no employee approval is substituted. Company approval stages remain separate.
- Already-approved individual requests with a pending Finance field can continue: awaiting approval projects as awaiting actual return, real shipment/receipt/inspection remain necessary, and an eligible received refund exposes the existing owner payout endpoint. No stored Finance approval or employee identity is fabricated. Explicit rejection still blocks payout.
- Owner/customer projections and Approval Center describe the actual required stage. Individual COD requests are discoverable without Finance initial approval; settled remittance and valid payout destination remain mandatory. No COD settlement bypass was added.
- Existing provider execution/idempotency, tenant ownership, amount, inventory and return services remain authoritative. No hosted rows were inspected or changed.

Verification (October 29, 2026):

All PHP commands used `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` and verified absent cached config. No concurrent PHPUnit. Frontend uses installed runners because pnpm/Corepack are unavailable; no dependency installation.

```powershell
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/PlatformFee tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/StaffOrderRefundPayloadTest.php tests/Feature/Cod/CodRefundTest.php tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php --no-progress --colors=never --do-not-cache-result --log-junit output/individual-owner-finance-backend.xml
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/OrderRefundApprovalWorkflowTest.php --no-progress --colors=never --do-not-cache-result --log-junit output/individual-owner-finance-final-controls.xml
node node_modules/vitest/vitest.mjs run resources/js/components/owner-action-center/__tests__/approvalRendererParity.test.tsx resources/js/Pages/ERP/Finance/PlatformBalance.test.tsx 'resources/js/Pages/ShopOwner/Orders/order management/__tests__' resources/js/Pages/UserSide/Orders/__tests__ --reporter=json --outputFile=output/individual-owner-finance-frontend.json
node node_modules/vite/bin/vite.js build --outDir output/individual-owner-finance-build
git diff --check
```

- Broad backend: exit 0, **126 tests / 15,717 assertions**. Final focused approval controls after discovery/notification cleanup: exit 0, **19 tests / 186 assertions**. Includes provider-faked owner payout exactly once, wrong-tenant 404, missing-return 422, rejection guard, legacy approved return continuation, policy toggle, company stages and individual COD discovery/unsettled-cash denial.
- Frontend: exit 0, **88 passed**, including actual rendered owner order labels and Approval Center Finance-not-required display. Customer and existing owner order controls passed.
- Build: exit 0, **3,858 modules / 38.70 seconds**. Existing unresolved `auth-geometric-pattern.svg` warning remains. Output is local verification only; `public/build` remains unchanged.
- Initial five desired-behavior failures were read before implementation. Intermediate checks exposed an invalid 401-vs-403 employee denial expectation, incorrect test method/payment fixture references, a random repair-only shop fixture on a retail test, and an error-state variable typo; corrected without weakening tenant/return/company denials. Final checks above pass.
- `git diff --check`: exit 0. Unrelated attendance files/output preserved. No migration, environment, dependency, generated production assets, commit, push or deployment in this task.

Sequential review record: simplify/reuse pass (existing route catalog, approval, return, inventory, notification and provider execution reused); Standards then Spec pass for this bounded contract; security/risk pass for reviewed owner guard/tenant routes, real actor fields, rejection/return/COD and provider replay guards; TS/React readability pass for added typed server flags and existing display components. Removed only branches made unreachable by this change. No new dependencies or heavy imports; performance/bundle improvements not measured. No new durable cross-project lesson warranted a vault entry.

Limits: no hosted browser journey, provider sandbox/live call, production-data repair or real database contention test performed. No full application/frontend suite, compiler or lint claim; repository has no configured frontend compiler/lint gate. Fresh local scope checks do not establish the state of the screenshot's particular hosted refund. Publication/deployment remains separate.


Commit follow-up: the user explicitly requested committing this fix with a fresh build. Ran the declared build steps with installed tools: `node scripts/prepare-owner-ocr-assets.mjs`, then `node node_modules/vite/bin/vite.js build` into actual `public/build`. Exit 0, 3,858 modules, 1 minute 1 second. `python output/verify-public-build.py` verified 407 manifest entries and 408 emitted asset references, including import/dynamic-import bindings and path containment. OCR `--check` passed with no tracked `public/ocr` diff. Existing unresolved `auth-geometric-pattern.svg` warning remains.

Fresh commit gate: focused Platform Balance API/payment workflow and refund approval tests passed 34 tests / 292 assertions; the preceding frontend command rerun passed 88 tests. Evidence: `output/individual-owner-finance-commit-controls.xml`, `.log`, `output/individual-owner-finance-commit-frontend.json`, and `output/individual-owner-finance-public-build.log`. Scoped source, tests, this plan, and fresh manifest/assets are included together. Unrelated attendance files and local output are excluded. This request authorizes the local commit; no push, merge, deployment, live provider operation or production-data repair was requested.


Rebase follow-up: the user authorized rebasing onto the latest `origin/solespace-b` so the PR can merge, rebuilding assets and pushing with a lease. Base: `f889c8a886da1f2259b51e98cf22543af8be4bcb`; original fix: `4851ab4cd5351d9a68eb29653f704439c201e6e9`. All 917 unmerged paths were generated `public/build` files. The 17 source/test/document files replayed byte-for-byte before this evidence note. Resolved assets by resetting only the build index to the base, then regenerating actual `public/build` from the combined source; no application conflict resolution was necessary.

Fresh rebased verification: the broad backend command above passed **127 tests / 15,727 assertions** (exit 0), recorded in `output/individual-owner-rebased-backend.xml` and `.log`. The frontend command above passed **88 tests** (exit 0), recorded in `output/individual-owner-rebased-frontend.json`. Declared build steps passed, **3,858 modules / 1 minute 41 seconds**; `output/individual-owner-rebased-public-build.log`. Manifest verifier passed **407 entries / 408 referenced assets**; OCR `--check` passed with no tracked OCR change. The existing SVG resolution warning remains. Diff checks passed. Unrelated attendance edits were backed up and temporarily stashed for the rebase and are excluded from the rewritten commit. This authorizes the feature-branch update only, not merging the PR, deployment or live financial/data operations.
