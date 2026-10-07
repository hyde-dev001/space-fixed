# Configurable Retail Product Warranty — implementation results

Worktree: `C:/xampp/htdocs/solespace-master/.worktrees/qa-four-follow-up`; branch: `fix/qa-four-follow-up`; implementation baseline: `21cbd443629c31f4c3dee156ee105a2865866216`.

The approved parent/child revisions were incorporated into the existing plan before implementation. Work was sequential, without subagents. Browser interaction checks are verified. Full-suite comparison is complete. The warranty feature has no unresolved critical test failure; the repository-wide backend suite remains red as disclosed below.

## 1. Implemented architecture

One `RetailWarrantyIssuance` belongs to an eligible order and owns its opaque reference, immutable buyer/shop/order snapshots, ONE canonical private PDF and durable email-delivery state. Its `RetailWarranty` children track each original order-item line, variant, covered quantity, original policy, fulfillment start, calendar expiry and current coverage independently. There is no replacement workflow or separate warranty approval state machine.

The PDF and email list all covered items. The certificate preserves original coverage; current item status, remaining quantity and reservations are projected separately. Settings changes, partial refunds, expiry and administrative voiding do not rewrite the published document.

## 2. Database changes

Four additive migrations were introduced:

| Migration | Added data |
| --- | --- |
| `2026_10_07_075506_create_shop_retail_warranty_settings_table.php` | Independent shop retail policy, enabled flag, configurable title/duration/text and permanent first-enable cutover. One settings row per shop. |
| `2026_10_07_075507_create_retail_warranty_issuances_table.php` | Unique order/reference; immutable snapshots and fulfillment/timezone; PDF path/hash/generation metadata; durable delivery state, attempt token/count/time, accepted-message reference and failure code. |
| `2026_10_07_075508_create_retail_warranties_table.php` | Item children and scoped foreign keys, unique order item, immutable item/policy/date/original-quantity snapshots, reconciled successful quantity and audited void metadata. |
| `2026_10_07_075509_add_retail_warranty_lifecycle_context.php` | Trusted fulfillment capture on orders, ordinary/warranty request basis on existing online/POS refunds, nullable warranty relationships on their existing item lines. |

Indexes and foreign keys protect identity and historical records. The new indexed order column's rollback explicitly drops its index before dropping the column. New migrations were applied, reversed and reapplied on isolated SQLite and MariaDB fixtures; unrelated repair settings remained intact. No application database was reset, and no `.env` was created or edited.

## 3. Backend changes

- `RetailWarrantyService`: settings/cutover, authoritative capture, payment-eligible issuance, calendar calculations, batched safe projections, effective SQL filters, locked assessment validation, successful quantity reconciliation and audited voiding.
- `RetailWarrantyCertificateService`: local escaped Dompdf rendering, private storage, canonical path/hash verification and scoped PDF response. Remote assets, PDF JavaScript and embedded PHP are disabled.
- `DeliverRetailWarranty` and `RetailWarrantyMail`: ID-only encrypted queued delivery, one combined attachment, pre-send retry, durable transport claim and no blind resend after uncertain acceptance.
- `ReconcileRetailWarranties`: bounded recovery of captured paid purchases, coverage reconciliation, stale sending-to-unknown transition and safe pending/failed delivery enqueue. Scheduled every five minutes without overlap.
- Three models/factories and relationships on orders, items, shops and existing refund items.
- Dedicated owner settings validation and customer/owner/staff read/download/void endpoints. Existing controllers project a limited DTO instead of exposing storage, provider or mail metadata.
- Small hooks in supported fulfillment, POS checkout, paid settlement, collected COD and successful refund paths. Existing refund calculation, return inspection, approval, payout and recovery services remain authoritative.

Added `barryvdh/laravel-dompdf` v3.1.2 / Dompdf v3.1.6 and four transitive packages. Six packages were installed; no existing package was upgraded or removed. No frontend dependency was added.

## 4. Frontend changes

- My Orders: one Product Warranty summary/reference/download; per-item original terms, dates and live quantities; validated warranty context in the existing assessment modal. Registered POS purchases display shop-assisted assessment instructions.
- Shop Settings → Operations: independent Retail Product Warranty form and server-paginated Issued Warranties search/status/details/download with reason-confirmed administrative voiding. Repair settings remain separate.
- Existing owner/staff order details: read-only coverage and certificate context.
- Existing retail POS receipt/history: original combined certificate and shop-performed warranty assessment through the existing inspected-item picker. Submitting assessment creates a pending request and does not call approval or execution.
- Shared typed projection and small reusable `RetailWarrantyPanel`; existing Tailwind/native form controls. No new heavy client renderer, modal framework or PDF dependency.

## 5. Actual warranty flow

Owner enables retail policy → permanent purchase cutover is established → eligible purchase is created → authoritative fulfillment captures original policy/time/items/identity → legitimate paid facts (including collected COD before remittance) allow one issuance and its item children → after outer commit, queued delivery generates/reuses one combined PDF and sends one email → authenticated customer/authorized shop actors can download the original document and read live coverage.

Third-party receipt, approved final shop-owned delivery proof, direct pickup completion and completed POS checkout are supported. Early shop-owned customer receipt is not official fulfillment. Pending/shipped/failed/cancelled orders do not issue warranties. Disabled/pre-cutover captures remain ineligible; only a false eligibility marker is saved for those captures, without duplicate customer snapshots.

Delayed payment uses the already captured policy/start time. My Orders performs its existing verified payment reconciliation before projecting warranties, so the response that verifies late payment also shows the resulting issuance.

## 6. Refund integration

`request_basis=warranty` is context, not approval. The server checks purchase/customer/shop/item/warranty relationships, fulfillment, effective and stored status, exact expiry, available quantity, successful refunds, reservations and active delivery investigations. POS customer inspection submissions are rejected; authorized shop actors use existing required inspection dispositions.

Only valid covered selected quantities receive the ordinary-deadline exception. Existing evidence, staff assessment, return logistics, inspection, owner/Finance stages, collected-payment limits and payout/recovery remain required. Company shop-owned warranty requests receive required staff assessment without changing their logistics classification; owner presentation correctly waits on staff.

Open requests reserve quantity; rejected/failed requests release it. Successful product-refund lines consume only their associated quantity, including ordinary product refunds. Shipping-only/no-item financial records do not consume product quantity. Ambiguous legacy successful refunds without item attribution fail closed for additional assessment until item history is reviewed. A persisted successful-consumption floor prevents a later financial status change from silently restoring already consumed physical-item coverage.

Replaying a warranty reservation preserves its original basis and selected lines, including after expiry; changing the request to ordinary cannot rewrite it. A previous partial refund marking the parent payment refunded cannot auto-complete a new warranty request. Gateway fallback cannot enlarge a warranty item refund to the whole captured payment.

## 7. Idempotency

| Operation | Protection |
| --- | --- |
| Issuance | Order row lock and database-unique order/reference; existing issuance is reused. |
| Item coverage | Unique order-item relationship, created in the parent transaction. |
| Fulfillment policy | First capture is persisted once; disabled/pre-cutover boundaries cannot be replayed into eligibility. |
| Delivery dispatch | Explicit outer-commit callback; rollback discards it. Enqueue failure leaves durable pending coverage for recovery. |
| PDF | Locked issuance, one canonical private path, recorded SHA-256, original bytes reused; historical snapshots immutable. |
| Email | Durable pending/sending/sent/failed/unknown/skipped states and locked attempt token, plus queue uniqueness. Known sent, skipped or uncertain attempts never resend normally. |
| Consumption | Derived successful item quantities, monotonic persisted floor and changed-only reconciliation/audits; replay does not increment a counter twice. |
| Assessment | Root-order serialization, existing payment reservation and immutable matching idempotent replay. |

## 8. Security

Customer download uses the native authenticated customer principal and its stored purchase identity, never email matching. Owner management/download/void scopes to the native owner and enabled retail module. Staff download requires an employee account, positive explicit shop relationship, appropriate job-order or unified-POS permission and live retail module/account middleware. Other tenants receive no record.

Walk-in sales never attach an account by email. Legitimately collected email is a delivery channel; no-email buyers retain their issuance and shop-accessible PDF. Private downloads accept only an opaque database reference, use a server-derived stored path, verify integrity and return PDF attachment/private no-store/nosniff headers. JSON contains no storage path, mail token/state/provider reference or private audit payload. User text is escaped in React, email and PDF. Client input is bounded; raw infrastructure exceptions are not returned to customers.

## 9. Automated tests

Fresh focused command:

```powershell
php -d extension=gd artisan test tests/Feature/RetailWarranty
```

Result: **41 tests, 288 assertions, no failures**; PHPUnit labels all 41 as warnings because the worktree intentionally has no `.env`. The suite covers schema/defaults, all duration units and boundaries, settings/cutover/repair isolation, immutable snapshots, supported fulfillment/COD/POS paths, registered/walk-in identity, one real PDF and array-transport email, pre-send failure/unknown delivery, private access, reservations and forged quantities, existing online/POS approvals, successful consumption, scheduler expiry, true commit/rollback callbacks and migration roundtrip.

The required A qty2/B qty1/C qty1 scenario executes actual receipt, combined issuance/PDF/email, old one-year versus future five-day policy, customer projection and existing inspection/approval/refund execution. Refunding one A leaves quantities **1/1/1**, unchanged PDF and one email; requesting two A is rejected.

Actual isolated MariaDB 10.4.32 verification: all **325 migrations applied**; four new migrations reversed/reapplied preserving repair settings; four overlapping issuer processes left **one issuance/three children**. Competing quantity reservations returned **reserved** and **collision**, with the losing connection waiting **1.959 seconds** on the root lock; one active request reserved two A units. SQLite is not presented as concurrency evidence.

## 10. Regression tests

The related command includes all warranty tests, CustomerDeliveryReceipt, OrderFulfillmentPolicy, RetailPosPaymentFlow, RetailPosItemBasedRefundFlow, OrderRefundApprovalWorkflow, OrderItemBasedPartialRefundFlow, OrderRefundReturnInspection, OrderRefundRecoveryLifecycle, COD, Repair/Warranty, RepairWarrantySettings, ShopModuleRouteCoverage and OrderRefundOwnerProjection.

Recorded result before the final replay test was added: **246 tests without assertion failures and one route-catalog test failure; 15,589 assertions**. Catalog findings concern existing Xendit, repair, Finance/COD and procurement routes; no warranty route is in that finding. POS payment positive fixtures now use the existing attendance helper instead of weakening production middleware. The latest warranty-only rerun above includes the final replay guard and same-response payment projection fixes. A final regression also verifies that a newer assessment is selected after an earlier rejection; existing selection already passed, so no extra application change was required.

Completing full command: `php artisan test --log-junit=storage/logs/warranty-full.xml`, with process-inherited GD and `memory_limit=2048M`: **3,892 tests; 347 failed, 3,529 warnings, 3 skipped, 13 passed; 65,550 assertions** (1,865.16s, exit 2). All 38 warranty cases present in that run passed; the two later recovery/query cases and latest-assessment regression are included in the final focused rerun. Exact baseline comparison used native PHPUnit in a detached checkout at the base commit, with its original locked dependencies: 633 tests across the failing files. **346 failures matched the same baseline cases and primary reasons** (one generated Mockery mock-number difference normalized). The remaining repair-intake SQLite database-lock failure passed `php -d extension=gd artisan test tests/Feature/Logistics/LogisticsConcurrencyTest.php --filter=concurrent_repair_intake`: 1 test, 7 assertions, no failure. Full-suite red/resource attempts are retained, not represented as passes.

## 11. Build verification

| Check | Actual evidence |
| --- | --- |
| Full frontend | `pnpm run test:frontend --maxWorkers=2`: **298 files / 1,834 tests passed**. Initial default-parallel run had three unrelated five-second timeouts; the full lower-parallelism rerun resolved all three. |
| Production build | `pnpm run build`: **passed**, 1m53s; fresh `public/build` manifest/assets retained. Existing unresolved image-reference warnings remain. |
| PHP style | Targeted `php vendor/bin/pint --test` on all 31 new non-Blade PHP files: **passed**. Existing large controllers/services were not reformatted. |
| Diff hygiene | `git diff --check`: **passed**; tracked and untracked text whitespace checked; final inventory generated. |
| Backend configured command | `composer test` was run: first attempt hit default 300-second Composer timeout; timeout-lifted attempt reached the 512 MB PHP memory limit. Temporary process-only GD/2 GB settings supported the completed full-suite run; no global PHP configuration or `.env` changes. |
| Browser/PDF | Actual customer/owner pages loaded without JavaScript errors. Actual three-page combined PDF extracted A/B/C and was visually inspected without clipping. Customer/owner search/detail/download verified at desktop and 390px viewport; both downloads exactly match the original bytes; zero page JavaScript errors. Warranty panels fit the viewport. Existing unrelated owner settings controls cause a 17px whole-page overflow. |
| TypeScript/lint | No committed compiler configuration or frontend lint script exists; not claimed as run. Typed-boundary/manual review and frontend tests/build provide the recorded frontend evidence. |

## 12. Files changed

The revised plan's expected-file section and [final generated file inventory](2026-10-07-retail-warranty-file-inventory.md) list created/modified source, tests, documentation and deployment assets. Additional narrow integration files are the actual canonical owner monitoring controller/shared manager order type, the reusable issued-history view, and transaction/acceptance/consumption regression tests. Existing Finance COD callbacks are covered through the shared payment-settlement hook rather than duplicated payout logic.

Runtime QA databases, fixtures, logs, helper servers, screenshots and synthetic certificates remain ignored. The main worktree's unrelated changes were preserved. Warranty publication is authorized on `fix/qa-four-follow-up`; final commit and push are verified separately.

## 13. Remaining limitations and operational notes

- Transport-level exactly-once email requires provider support unavailable in the configured abstraction. Unknown acceptance is deliberately not automatically resent; operations must reconcile it with the provider before any manual delivery decision.
- Restore original private-storage backups if a recorded canonical PDF is permanently missing/corrupted; silently regenerating different historical bytes is prohibited. Initial PDF/storage/template failures retry safely and never delete coverage.
- Start the existing queue worker and scheduler, install the locked Composer dependencies, apply the additive migrations and ensure private storage/font-cache write permissions when deploying. Production migration, real outbound SMTP/provider delivery and real financial payout were not performed; tests fake those external channels.
- Existing missing-`.env`, PHPUnit metadata-deprecation and Node localstorage warnings remain. GD was enabled only for test processes. Existing image-reference build warnings remain. At a 390px viewport, unrelated owner-settings controls extend the whole page to 407px; the new warranty panel remains within the viewport.
- `composer audit --format=json` reported four advisories in three unchanged dependencies (Laravel, CommonMark and Flysystem); none in the added PDF packages. Updating those unrelated dependencies was not included.
- Full backend remains red: 346 failures reproduce on the base commit; one load-sensitive SQLite repair concurrency failure passed in isolation. No unmatched critical warranty failure remains. No TypeScript compiler/linter result or real SMTP/payment-provider success is claimed.

## Sequential review and completion record

Publication verification: the user authorized commit/push with fresh `public/build`. The unpublished warranty commit was rebased without source conflicts onto fetched `origin/solespace-b` at `76cbb1e9ec360884d7966cc9372aa97838c107da`, which already contains the prior four-QA commit. The final bundle was regenerated from that combined source. Fresh checks: `pnpm run build` passed (24.88s); `pnpm run test:frontend --maxWorkers=2` passed **299 files / 1,861 tests**; `php -d extension=gd artisan test tests/Feature/RetailWarranty tests/Feature/RetailPosPaymentFlowTest.php` completed **50 tests / 355 assertions with no failures**, retaining absent-`.env` warnings. All 399 manifest assets, 59 changed PHP syntax checks and diff hygiene were verified. The earlier full-backend baseline limitations above remain recorded; that full run was not repeated for publication. Runtime artifacts and the temporary pre-rebase build stash are excluded from the commit.

| Required item | Recorded result |
| --- | --- |
| Simplify / ponytail | Pass: one shop policy, one parent with item children, one service for coverage, native row locks/constraints, existing refund state machine, no adapter hierarchy or new frontend package. |
| Standards → Spec → risk review | Sequential review performed. Found/resolved indexed-column rollback, stored-expiry reactivation, commit dispatch timing, late-payment projection ordering, owner waiting-on-staff context and replay-basis guard/import. Full comparison resolved every unmatched finding: 346 baseline matches and one passing isolated SQLite concurrency rerun. Warranty relation reads were also reduced from six to two for three orders, with a failing-then-passing query-count regression. |
| Clean TypeScript | Manual pass: typed DTOs, focused reused panel/form/history, safe Axios narrowing and abort handling, no new `any`/assertion-based trust boundary. Compiler/lint not run. |
| Karpathy | Pass: approved assumptions retained, no unrelated application refactor, no separate claim/exchange workflow, concrete acceptance scenario verified. |
| Code splitting | Pass: reuse existing Inertia lazy page loading and Vite shared chunks. PDF library stays server-side; no measured reason to split the small warranty controls manually. |
| Gauge improvements | Required observable outcomes measured by 41 tests and actual MariaDB/PDF/mail evidence. Warranty parent/item reads: six to two for a three-order page. Main JS: 584,943 to 585,417 bytes (+474). All 399 manifest file references exist. Latency/render-count baseline not measured. |
| Security review | Scoped guards, tenant/item/FK validation, private path/integrity, escaped terms, durable email uncertainty and unchanged financial/inspection stages reviewed. |
| Verification-before-completion | Focused/backend-regression/frontend/build/migration evidence recorded; full/baseline/browser/syntax/manifest/diff evidence recorded. |
| Reuse / dead code | Existing helpers/components/services/framework facilities reused. New PHP unused imports removed by Pint; changed-area stale references/dead branches reviewed. No unfamiliar unrelated code deleted. |
| Writing / vault | Revised plan, progress, results and exact file inventory maintained; durable lessons recorded separately without credentials or personal data. |
