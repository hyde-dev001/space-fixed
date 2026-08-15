# Shop Owner Phase 3A Action Center Rollout Guide

Phase 3A adds a read-only Owner Action Center for four existing owner decision workflows:

- Order Refunds
- Repair/POS Refunds
- Expenses
- Purchase Requests

The Action Center is discovery only. Existing approval pages remain the authorization, validation, mutation, confirmation, notification, and audit surfaces. Phase 3A does not complete Phase 3B or Phase 3C.

## Configuration

The bounded configuration lives in `config/owner_action_center.php`:

| Setting | Environment variable | Default | Meaning |
| --- | --- | ---: | --- |
| Global rollout | `SHOP_OWNER_ACTION_CENTER_ENABLED` | `false` | Enables Phase 3A evaluation after Phase 2 canonical selection. |
| Shop allowlist | `SHOP_OWNER_ACTION_CENTER_SHOP_IDS` | empty | Comma-separated positive Shop Owner primary keys. |
| Refund coverage | `SHOP_OWNER_ACTION_CENTER_REFUNDS_ENABLED` | `true` | Enables both Order and Repair Refund adapters. |
| Expense coverage | `SHOP_OWNER_ACTION_CENTER_EXPENSES_ENABLED` | `true` | Enables Expense coverage for company owners. |
| Purchase Request coverage | `SHOP_OWNER_ACTION_CENTER_PURCHASE_REQUESTS_ENABLED` | `true` | Enables Purchase Request coverage for company owners. |

The Phase 2 canonical shell remains a prerequisite. Phase 3A uses the same stable `shop_owner.id` allowlist identity as Phase 2; it does not use email, business name, registration changes, or module state as cohort identity.

Bounds are fixed in configuration and validated at the route boundary:

- Home summary: 5 candidates.
- Full queue default: 20 per page.
- Maximum page size: 50.
- Maximum page depth: 100.
- Each adapter receives at most `page × per_page` candidates, subject to the shared contract maximum.

No `.env` values are committed by this change.

## Safe rollout order

1. Deploy with the Phase 2 canonical-shell flag off and the Phase 3A flag off.
2. Verify the canonical routes and existing approval destinations remain available under the existing presentation.
3. Enable the Phase 2 canonical shell for internal/test Shop Owner IDs only.
4. Enable Phase 3A with an empty Phase 3A allowlist. Behavior must remain Phase 2 placeholders.
5. Add one internal/test Shop Owner primary key to the Phase 3A allowlist.
6. Verify company and individual owner contexts, healthy and degraded source states, deep links, and rollback.
7. Expand the allowlist only after every adapter readiness row below is green and the browser scenarios are recorded.

Removing a Shop Owner ID from `SHOP_OWNER_ACTION_CENTER_SHOP_IDS`, or disabling `SHOP_OWNER_ACTION_CENTER_ENABLED`, removes only the Phase 3A enhancement. It does not remove `/shop-owner/action-center`, existing approval URLs, the canonical shell, or any domain data.

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

1. Phase 3A off, owner not allowlisted, and owner allowlisted.
2. Company and individual owners with eligible and ineligible coverage.
3. Healthy mixed queue, healthy empty queue, partial Refund failure, all-enabled failure, and no-enabled configuration.
4. Home count/top items agree with the full queue under fixed source state.
5. Source filters, Refresh, page normalization, semantic `Open workflow` links, visible focus, dark/light contrast, reduced motion, and no horizontal overflow.
6. Order Refund, Repair Refund, Expense, and Purchase Request focused links open only records returned by the existing scoped APIs.
7. Stale focused links remain usable and do not manufacture a modal or consume the query.
8. Disabling Phase 3A returns Home to Phase 2 placeholders without changing the canonical shell, approval URLs, authorization, or domain data.

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

Use the following order:

1. Remove the affected Shop Owner primary key from `SHOP_OWNER_ACTION_CENTER_SHOP_IDS`.
2. Confirm the owner receives the Phase 2 Home placeholders and no Action Center item in canonical navigation.
3. If required, set `SHOP_OWNER_ACTION_CENTER_ENABLED=false` as the global kill switch.
4. Keep existing approval pages and compatibility URLs unchanged.

Rollback requires no data migration, route removal, authorization change, or domain-state reversal.

## Scope boundary

Phase 3A supports only Refunds, Expenses, and Purchase Requests, with Refunds internally split into Order and Repair adapters. Exceptions, notifications, waiting-on-staff work, pricing, payroll, salary, suspension, repair rejection, and high-value repair decisions are unsupported and must not be represented as available Action Center functionality. Phase 3 remains open for separately designed Phase 3B and Phase 3C work.
