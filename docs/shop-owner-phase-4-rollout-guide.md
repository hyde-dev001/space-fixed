# Shop Owner Phase 4 Approval Rollout Guide

## Scope and release boundary

Phase 4 adds seven independent binary Shop Owner approval policies and consolidates owner review into the Phase 3C Action Center:

1. Refund;
2. Price;
3. Payslip;
4. Salary Adjustment;
5. Purchase Request;
6. Expense;
7. Repair Reject.

The Action Center is a presentation and decision surface. The existing domain services remain the only authorities for transitions, locking, authorization, audit records, payout, price application, payroll disbursement, salary application, purchasing release, settlement, and terminal repair rejection. `OFF` skips only the snapshotted owner stage; it is never implicit approval.

Phase 3C is a prerequisite. The owner must be selected into the canonical Action Center rollout, and its decision, exception, and waiting adapters must be healthy enough to distinguish zero work from partial or unavailable data. The Phase 3C deployment and recovery evidence remain in [the Phase 3C rollout guide](shop-owner-phase-3c-rollout-guide.md).

## Deployment order

Use the normal release process in this order:

1. Confirm the release is based on the completed Phase 3C state at `0124f7228` or a descendant, and confirm the canonical owner shell and Action Center rollout are enabled for the intended cohort.
2. Apply migrations in timestamp order. The Phase 4 schema additions are additive:

   - `2026_08_22_120000_add_owner_approval_snapshots_to_phase_four_workflows.php` adds nullable snapshots to refunds, purchase requests, and salary changes.
   - `2026_08_22_130000_add_approval_workflow_to_repair_services.php` adds repair-service workflow metadata used by the existing price approval sequence.

3. Let the snapshot migration complete before accepting new approval submissions. It is retry-safe and does not consult current Settings for an in-flight record.
4. Deploy the application code, route catalog, Action Center assets, notification producers, and tests together. Clear and rebuild Laravel configuration/routes through the standard release pipeline; do not edit `.env` as a Phase 4 rollout step.
5. Restart notification and queue workers after the code release. Drain or requeue old jobs according to the existing queue procedure; do not resend a decision notification by hand without its existing idempotency/deduplication behavior.
6. Verify all seven Settings controls are present as booleans. Missing or malformed policy data must read as owner-required.
7. Start with all seven toggles `ON` for the first cohort. Move a family to `OFF` only after its characterized downstream authority and smoke checks are green.

### Conservative backfill rules

The migration derives only the smallest snapshot needed to keep existing records stable:

- automatic cancellation refunds are explicitly `OFF` because they never entered the approval workflow;
- request refunds and POS refunds with no reliable legacy evidence default to `ON`;
- draft purchase requests and cancelled salary proposals remain outside approval; submitted/active legacy records with unknown history default to `ON`;
- Price, Payslip, manual Expense, and Repair Reject reuse their existing approval role map or persisted boolean state;
- no `approvals` or duplicate universal approval table is introduced;
- no migration down is part of operational rollback, and completed domain effects are never reversed by a schema rollback.

After migration, inspect counts by table and policy snapshot. Any unexpected `NULL` on a submitted record is a release blocker; do not infer a setting from `updated_at` or from the current Settings value.

## Seven-family smoke matrix

Use non-production-safe fixtures in a test or staging tenant. For each row, create one record with the toggle `ON`, verify it reaches the Action Center, open its typed deep link, confirm the decision summary, and exercise an approved/rejected test mutation. Repeat with `OFF` and verify the next existing authority, not an automatic approval.

| Family | `ON` check | `OFF` check | Effect that must not happen from the owner-stage skip |
| --- | --- | --- | --- |
| Refund | Finance initial -> `order_refund` or `repair_refund` owner queue -> existing Finance final path | Finance continues through the existing Finance decision path | No payout, gateway call, or return-receipt bypass |
| Price | Product, repair-service, and package requests use the owner level and typed price key | Existing Finance-only level remains authoritative | No price application before Finance final approval |
| Payslip | v4 approval shows the owner level between Finance stages | Finance checker/final stages remain in order | No disbursement before final payroll approval |
| Salary Adjustment | Owner decision records the exact owner actor | Existing authorized non-proposer reviewer decides | No salary mutation; HR application remains separate |
| Purchase Request | Finance initial -> owner -> Finance final release | Finance final release remains required | No purchase order creation or purchasing release |
| Expense | Manual Expense owner decision appears; procurement-receipt/payroll sources do not | Manual Expense uses existing Finance approval | No settlement or reversal mutation from approval alone |
| Repair Reject | Repairer rejection -> owner -> Manager final review | Manager remains the final decision authority | No terminal rejection before Manager finalization |

Record tenant, source type, snapshot value, current status, actor, audit timestamp, Action Center count before/after, notification destination, and the downstream status after each test. Do not use real payment, payroll, salary, or customer data.

## Action Center and deep-link checks

The canonical selection shape is:

`/shop-owner/action-center?bucket=needs_my_decision&approval=<source_type>:<positive_id>`

The allowed typed source keys are:

| Source key | Family/detail |
| --- | --- |
| `order_refund` | Retail order refund |
| `repair_refund` | Repair/POS refund |
| `product_price_change` | Product price change |
| `repair_price_change` | Repair service price change |
| `repair_package_price_change` | Repair package price change |
| `payslip` | v4 payslip approval |
| `salary_change` | Salary adjustment |
| `purchase_request` | Purchase request |
| `expense` | Manual Expense |
| `repair_rejection` | Repair rejection owner stage |

For each key verify that opening, refresh, browser back/forward, filter changes, and closing the panel preserve the queue context. Invalid, inaccessible, completed, cross-shop, malformed, non-positive, or unsafe IDs must leave the queue available and expose no mutation control.

The sidebar has one `Action Center` link and an optional positive pending-count badge. Zero, unavailable, or invalid summary responses show no badge and do not remove the link. The queue owns all Approve/Reject controls: condensed rows expose only a labelled `Review` action, and the detail panel handles confirmation, bounded rejection reasons, CSRF, stale responses, refresh, announcements, Escape, focus return, and responsive layout.

## Legacy URLs and notifications

Legacy page route names remain registered as tenant-safe redirects. Verify all seven families, including old query parameters, redirect to the matching typed Action Center source. Malformed links land on a safe queue state without disclosure or action controls. Existing Finance/ERP operational pages remain available where they are still authoritative; they are not replaced by a generic owner mutation endpoint.

Owner-stage notifications are emitted only when the frozen record actually requires the owner. Their destination is the typed Action Center link. Toggle `OFF` does not send an owner decision notification. A compatibility notification that still names the old repair-rejection URL is safe because that named route now redirects to the canonical queue; do not change high-value repair behavior as part of this rollout.

## Monitoring after release

Watch these signals for each cohort and each family:

- Action Center summary count equals the queue's `Needs My Decision` count for the same owner and filter;
- adapter health distinguishes healthy zero, partial, unavailable, disabled, and invalid coverage;
- 404/409/422 detail and mutation responses remain bounded and do not change state on stale or wrong-stage requests;
- owner notifications have typed destinations and no duplicate bursts after worker restart;
- audit records retain the exact owner, Finance, HR, Procurement, or Manager actor and stage;
- refund gateway/idempotency, settlement/reversal, payroll disbursement, and price-application metrics remain unchanged outside their existing domain paths;
- no logs contain customer details, payment data, receipt paths, payroll values, rejection reasons, or raw Action Center row payloads.

Investigate a count/list mismatch as a source-of-truth or snapshot defect. Do not patch it by adding a second React-side domain query or by changing a queue count independently of the adapter contract.

## Rollback and forward reconciliation

Rollback is deliberately bounded:

1. If a family has a workflow problem, stop changing that family’s toggle and set new submissions to `ON` (the fail-safe) while preserving existing snapshots.
2. If the Action Center presentation is unhealthy, roll back the application release to the last verified release through the normal deployment system. Leave additive migrations, settings JSON, snapshots, audit rows, and completed domain effects in place. Confirm the prior release tolerates additive nullable columns before switching traffic.
3. Do not run migration `down()`, delete snapshot columns, remove route names, or restore a legacy page by ad-hoc file copying in production.
4. Keep the legacy redirect route names and domain mutation endpoints stable during rollback. A redirect failure is a routing incident, not permission to broaden an endpoint.
5. After rollback, reconcile records forward through the authoritative service for their domain. Refunds use the existing finance/gateway reconciliation; prices use Finance approval or an authorized correction; payroll uses its approval/disbursement ledger; salary uses HR’s effective-dated correction; purchasing and expenses use their existing release/settlement/reversal workflows; repair rejection uses Manager finalization or reassignment. Never undo a completed money or state effect with direct SQL.

Changing a toggle affects only records submitted after the change. A toggle rollback cannot remove an owner from, or add an owner to, an in-flight record.

## Sequential review and verification record

| Review gate | Result | Evidence/notes |
| --- | --- | --- |
| Ponytail simplification | Pass | Retired seven superseded owner page components, removed the old seven-link sidebar/fallbacks, and kept Finance/ERP operational workflows and domain services. No duplicate policy reader or generic mutation endpoint was added. |
| Standards review | Finding/resolved | Route-catalog parity initially missed the new summary/detail routes; `c5cb03061` added the eight entries and isolated catalog/coverage tests now pass. Laravel service boundaries and existing React/Tailwind conventions remain in use. |
| Spec/acceptance review | Pass | The 15-criterion checklist below and the reconciled matrix cover all seven ON/OFF authorities, snapshots, Action Center behavior, redirects, safety, and reuse. |
| Clean TypeScript/React review | Pass | Focused Action Center, renderer parity, sidebar, and decision-footer tests pass; build passes. No committed standalone TypeScript compiler or lint script exists, so no type-check/lint pass is claimed. |
| Code splitting | Pass / no split needed | The Action Center uses static imports for seven small renderers. The fresh production build measured `ActionCenter-BuBGTLPh.js` at 46.34 kB / 10.69 kB gzip; no measured benefit justified additional lazy-loading complexity. |
| Improvement gauge | Measured where available | Sidebar legacy links: 7 before -> 0 after. Action Center pre-change bundle baseline: not measured; post-change size is recorded above. Adapter bounded-query assertions are included in the Action Center suite. |
| Security/integrity review | Pass | Owner guard, canonical rollout, tenant-scoped reads/mutations, strict typed selection, CSRF, stale/409 handling, row-lock/domain-service boundaries, idempotency, and audit actors are covered by the focused security/workflow tests. |
| UI/UX review | Pass by static/component evidence; browser not run | Shared hierarchy, review-only rows, confirmation, required bounded rejection, keyboard/focus behavior, 44px targets, live announcements, responsive shell, and recoverable states are covered by component tests. Authenticated browser smoke was not run because no test Shop Owner credentials were available. |
| Reuse/dead-code audit | Pass | No runtime imports of the seven deleted owner pages remain. Existing domain endpoints, adapters, result contracts, icons/styles, route redirects, and ERP workflows are reused. |
| Verification-before-completion | Pass with documented exceptions | Focused PHP gates and build pass. Full frontend has one unrelated Riders baseline failure; Composer test hit its 300-second timeout with widespread unrelated failures. Exact results are below. |

### Fifteen acceptance criteria

| # | Criterion | Result/evidence |
| ---: | --- | --- |
| 1 | Settings exposes all seven binary toggles | Pass: `ShopOwnerApprovalSettingsTest` and settings UI test |
| 2 | ON includes a tenant-scoped owner stage; OFF preserves the rest | Pass: seven family workflow tests and Action Center adapters |
| 3 | Toggle changes do not reroute in-flight records | Pass: snapshot migration and submission snapshot tests |
| 4 | Missing/malformed configuration fails safe to owner-required | Pass: policy service unit tests |
| 5 | Every OFF path uses a characterized authority | Pass: all seven matrix rows remain `PROVEN` |
| 6 | No fallback actor/permission/status/workflow is invented | Pass: domain services and matrix reconciliation; no universal approval table |
| 7 | Client flags, shortcuts, retired jobs, and limits cannot override snapshots | Pass: policy, snapshot, and security characterization tests |
| 8 | Wrong-shop, wrong-role, replay, stale, and self-approval attempts are denied | Pass: workflow, route, and Action Center security suites |
| 9 | Downstream effects remain separate, idempotent, and auditable | Pass: refund, price, payroll, salary, purchasing, expense, and repair suites |
| 10 | One Action Center entry replaces the seven-link group | Pass: sidebar tests; 7 legacy links -> 0 |
| 11 | All seven families filter, open, understand, approve, and reject in the Action Center where permitted | Pass: centralized Action Center tests and seven renderer parity fixtures |
| 12 | Responsive/accessibility/error behavior is preserved | Pass by component tests; authenticated browser matrix not run without credentials |
| 13 | Legacy URLs and notifications resolve to matching typed records without disclosure | Pass: redirect/security/notification tests |
| 14 | Notifications and queue items appear only when owner action is required | Pass: snapshot-aware adapters, notification tests, and count/list parity tests |
| 15 | Existing services and Phase 3C architecture are reused | Pass: matrix evidence, registry/service reuse, route catalog, and dead-reference scan |

## Verification evidence

### Focused backend gates

```powershell
php artisan test tests/Unit/Services/ShopOwnerApprovalPolicyServiceTest.php tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php tests/Unit/Services/PurchaseRequestServiceTest.php tests/Unit/Models/PurchaseRequestTest.php
# PASS: 58 tests, 266 assertions, 18.08s

php artisan test tests/Feature/ShopOwner/ShopOwnerApprovalSettingsTest.php tests/Feature/ShopOwner/ApprovalPolicySnapshotMigrationTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/RepairOnlineRefundWorkflowTest.php tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php tests/Feature/Finance/RepairPriceApprovalSmokeTest.php tests/Feature/Finance/PayslipApprovalWorkflowTest.php tests/Feature/HR/SalaryChangeOwnerApprovalTest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php tests/Feature/Manager/ManagerRepairRejectionTest.php
# PASS: 99 tests, 571 assertions, 29.18s

php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter
# PASS: 182 tests, 1,120 assertions, 38.05s

php artisan test tests/Unit/BusinessScaling/ShopModuleCatalogTest.php tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php
# PASS: 10 tests, 26,771 assertions, 8.10s
```

### Frontend and build gates

- `pnpm run test:frontend` could not run because `pnpm` is not available on this environment's PATH.
- Equivalent local binary run: `node_modules/.bin/vitest.cmd run --reporter=dot` — 117 files, 655 passing tests, 1 failure. The remaining failure is the unchanged `resources/js/Pages/ERP/Logistics/__tests__/Riders.test.tsx` filter-request assertion at line 66; the file fails in isolation with the same result.
- Focused centralized frontend run: 7 files, 45 tests passed, including sidebar, Action Center, decision footer, detail panel, selection parser, and renderer parity.
- `node_modules/.bin/vite.cmd build` — passed, 3,714 modules transformed; `ActionCenter-BuBGTLPh.js` 46.34 kB / 10.69 kB gzip. Tracked `public/build` output was restored after verification; generated untracked assets/cache were preserved and not staged.
- No committed standalone TypeScript compiler or frontend lint script exists; neither is claimed as passed.

### Broader suite and browser limitations

- `composer test` — did not finish within Composer's 300-second process timeout. It produced widespread failures outside this Phase 4 scope. The route-catalog failures were isolated, fixed, and re-run green. Remaining representative unrelated baseline failures include `Tests\Unit\Security\MfaDependencyContractTest`, `Tests\Unit\Services\PrivilegedMfaServiceTest`, canonical-shell expectations in `Tests\Feature\BusinessScaling\InertiaModuleStateShareTest`, and the owner ERP access expectation in `Tests\Feature\BusinessScaling\OwnerAdministrativeModuleAccessTest`.
- Browser smoke for all seven families was not run. The local Laravel/Playwright probe reached `/login`, but no authenticated Shop Owner test credentials were available. No production decision was attempted.

## Handoff boundary

This guide records implementation and verification only. Do not merge, push, deploy, or call the wider Shop Owner program complete without separate user authorization.
