# Shop Owner Phase 3A Action Center Rollout Guide

Phase 3A adds a read-only Owner Action Center for four existing owner decision workflows:

- Order Refunds
- Repair/POS Refunds
- Expenses
- Purchase Requests

The Action Center is discovery only. Existing approval pages remain the authorization, validation, mutation, confirmation, notification, and audit surfaces. Phase 3A does not complete Phase 3B or Phase 3C.

## Configuration

The thesis build uses the committed defaults in `config/owner_action_center.php`:

| Setting | Thesis default | Meaning |
| --- | ---: | --- |
| `owner_action_center.enabled` | `true` | Action Center evaluation is available for every valid canonical-shell owner. |
| `owner_action_center.allowlisted_shop_ids` | `[]` | Empty means all valid Shop Owners; a non-empty list remains available for focused test overrides. |
| `owner_action_center.coverage.refunds` | `true` | Enables both Order and Repair Refund adapters. |
| `owner_action_center.coverage.expenses` | `true` | Enables Expense coverage for company owners. |
| `owner_action_center.coverage.purchase_requests` | `true` | Enables Purchase Request coverage for company owners. |

Phase 3A no longer reads the `SHOP_OWNER_ACTION_CENTER_ENABLED`, `SHOP_OWNER_ACTION_CENTER_SHOP_IDS`, or source-coverage environment variables. No `.env` change is required for local, thesis, or deployed environments. The Phase 2 canonical shell remains a prerequisite, while existing owner authorization, tenant scope, and domain workflow checks remain authoritative.

Bounds are fixed in configuration and validated at the route boundary:

- Home summary: 5 candidates.
- Full queue default: 20 per page.
- Maximum page size: 50.
- Maximum page depth: 100.
- Each adapter receives at most `page × per_page` candidates, subject to the shared contract maximum.

After deploying a config change, clear and rebuild Laravel's configuration cache using the normal deployment procedure. Then verify company and individual owner contexts, healthy and degraded source states, deep links, and the existing approval workflows.

The selection policy still supports explicit programmatic `false` values and non-empty allowlists for automated rollback/security tests. Those are not required deployment settings and are not read from `.env`.

## Coverage and readiness evidence

| Owner-facing source | Adapter keys | Authoritative destination | Eligibility boundary | Readiness evidence |
| --- | --- | --- | --- | --- |
| Refunds | `order_refunds`, `repair_refunds` | `shop-owner.refund-approvals?refund_type=order|repair&refund={id}` | Existing Shop Owner refund policy, tenant relation, owner stage, active source state, and finance stage. | `OrderRefundAttentionAdapterTest`, `RepairRefundAttentionAdapterTest`, `ActionCenterDeepLinks.test.tsx`, existing refund return-gate tests. |
| Expenses | `expenses` | `shop-owner.expense-approvals?expense={id}` | Company owner, same-shop expense, submitted manual expense, and pending Shop Owner approval. Procurement-receipt expenses are excluded. | `ExpenseAttentionAdapterTest`, `ActionCenterDeepLinks.test.tsx`. |
| Purchase Requests | `purchase_requests` | `shop-owner.purchase-request-approval?purchase_request={id}` | Company owner, same-shop request, and `pending_shop_owner` state. | `PurchaseRequestAttentionAdapterTest`, `ActionCenterDeepLinks.test.tsx`, existing Purchase Request approval tests. |

The four adapters are independently registered and health-reported. A failed Order Refund adapter does not erase healthy Repair Refund data, and a failed source is never represented as zero successful work.

## Runtime states and rollback

| State | Owner-visible behavior |
| --- | --- |
| `none` | Supported sources are healthy; counts and items are authoritative for enabled coverage. |
| `partial` | Healthy-source data remains visible, with partial-coverage language and failed adapter labels. |
| `unavailable` | The Action Center states that counts are unavailable; it does not report zero decisions. |
| `no_enabled_adapters` | The Action Center is treated as configuration-disabled and canonical Home keeps the Phase 2 placeholders. |
| `not_selected` | The owner is outside Phase 3A; canonical Home remains the Phase 2 presentation. |

On a common coordinator failure, `/shop-owner/action-center` redirects once to `/shop-owner/home`. Home keeps the Phase 2 placeholder surface. There is no redirect back to the failed Action Center route.

## Telemetry contract

Action Center operational events log only bounded operational fields:

- `shop_id`
- enabled, healthy, and failed adapter keys
- degradation status
- adapter/read duration in milliseconds
- result count
- validated source, page, and per-page values
- a validated `X-Request-ID` or `X-Correlation-ID` value, when supplied

Record titles, customer or staff names, source references, amounts, refund details, expense reasons, purchase-request descriptions, payment details, and approval reasons are not logged. Adapter exceptions are reported and recorded as failed adapter health; authorization and model-not-found failures are rethrown to the route boundary rather than converted into successful empty data.

Phase 2 selection telemetry remains the source of canonical-shell presentation and selection-reason evidence.

## Query and performance evidence

The adapter readiness tests assert constant query counts when changing from one qualifying row to multiple qualifying rows:

- `OrderRefundAttentionAdapterTest::test_read_query_count_does_not_grow_with_qualifying_rows`
- `RepairRefundAttentionAdapterTest::test_read_query_count_does_not_grow_with_qualifying_rows`
- `ExpenseAttentionAdapterTest::test_read_query_count_does_not_grow_with_qualifying_rows`
- `PurchaseRequestAttentionAdapterTest::test_read_query_count_does_not_grow_with_qualifying_rows`

The coordinator evidence tests assert:

- Home passes candidate limit `5` to every enabled adapter.
- Full queue page 4 with 7 items per page passes candidate limit `28`.
- Each adapter is read once per coordinator refresh, independent of one versus forty returned candidates.

Absolute latency and a persisted before/after query-count baseline were not measured reliably outside the test database. The evidence currently establishes bounded candidate requests and query-count invariance; latency remains an operational rollout measurement.

## Verification matrix

| Area | Evidence |
| --- | --- |
| Tenant isolation and stable ID | `OwnerActionCenterSecurityTest` cross-shop full/Home assertions and existing per-adapter tenant tests. |
| Query input validation | `OwnerActionCenterSecurityTest` rejects unsupported source, invalid page, and invalid per-page values with deterministic 302/422 validation behavior. |
| Safe telemetry | `OwnerActionCenterSecurityTest` asserts exact bounded log keys and excludes business-record content. |
| Failure handling | Authorization and model-not-found failures do not become successful empty results; route failure redirects once to Home. |
| Existing workflows | Approval page deep-link characterization plus existing Purchase Request/refund return-gate regressions. |
| UI states | Action Center and canonical Home frontend tests cover healthy, empty, partial, unavailable, no-enabled, filters, and pagination behavior. |
| Browser verification | Run the controlled local browser scenarios below when the application and actor fixtures are available; record exact environment and screenshots as QA evidence only. |

## Controlled browser scenarios

At desktop and representative mobile widths, verify with test Shop Owner fixtures:

1. Valid individual and company owners, plus invalid registration context.
2. Company and individual owners with eligible and ineligible coverage.
3. Healthy mixed queue, healthy empty queue, partial Refund failure, all-enabled failure, and no-enabled configuration.
4. Home count/top items agree with the full queue under fixed source state.
5. Source filters, Refresh, page normalization, semantic `Open workflow` links, visible focus, dark/light contrast, reduced motion, and no horizontal overflow.
6. Order Refund, Repair Refund, Expense, and Purchase Request focused links open only records returned by the existing scoped APIs.
7. Stale focused links remain usable and do not manufacture a modal or consume the query.
8. A programmatic Phase 3A disable returns Home to Phase 2 placeholders without changing the canonical shell, approval URLs, authorization, or domain data.

## Verification and review record

Verification run on 2026-08-15 in the isolated Phase 3A worktree:

- `php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter tests/Feature/ShopOwner/CanonicalShell --compact` — PASS, 197 tests, 2,277 assertions. PHPUnit emitted the repository’s existing `file_get_contents` warnings.
- `.\node_modules\.bin\vitest.cmd run` — PASS, 112 test files, 612 tests. `pnpm` was unavailable, so the checked-in Vitest binary was used directly. Node emitted the existing invalid `--localstorage-file` warning.
- `.\node_modules\.bin\vite.cmd build` — PASS, built in 19.89 seconds. Generated `public/build` artifacts were restored and are not part of the change.
- `git diff --check` — PASS.
- Standalone TypeScript type-check and frontend lint — not run; the repository has no committed scripts/configuration for either gate.
- Browser verification — not run. No local listener or actor/fixture harness was available in this worktree; no automated browser coverage is claimed.

Sequential review record:

1. Simplification — PASS; no safe deletion or dependency replacement found.
2. Laravel/repository standards — PASS; PHP syntax, targeted backend suite, and route/controller conventions verified.
3. Phase 3A spec — PASS; the acceptance matrix is covered without representing Phase 3B/3C functionality.
4. TypeScript/React clean code and performance — PASS; bounded props, native links, stable list keys, no new `any`, and no unnecessary code split.
5. Minimum-scope/assumptions — PASS; existing workflows, policies, and tenant identity are reused.
6. Code splitting — N/A; no genuinely heavy conditional dependency was added.
7. Gauge improvements — bounded candidates and constant query/read behavior are measured by tests; absolute latency and persisted before/after query totals are not measured.
8. Security — PASS; tenant isolation, fixed filters, failure propagation, server-owned destinations, and safe logs are covered.
9. Verification-before-completion — PASS after the commands above are rerun on the final tree.

## Rollback

The normal thesis deployment does not require an environment rollback switch. If a code-level rollback is necessary, deploy the previous verified revision and refresh Laravel's configuration cache. Automated tests retain explicit policy overrides to verify that Phase 3A can degrade to Phase 2 placeholders without changing approval URLs, authorization, or domain data.

## Scope boundary

Phase 3A supports only Refunds, Expenses, and Purchase Requests, with Refunds internally split into Order and Repair adapters. Exceptions, notifications, waiting-on-staff work, pricing, payroll, salary, suspension, repair rejection, and high-value repair decisions are unsupported and must not be represented as available Action Center functionality. Phase 3 remains open for separately designed Phase 3B and Phase 3C work.
