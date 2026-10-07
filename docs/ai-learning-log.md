# AI Learning Log

## 2026-08-13 — Frontend delivery workflow

- After completing frontend code changes, follow `docs/git-workflow.md`.
- Generate and include a fresh `public/build`, run the relevant pre-push checks, and push only the task's feature branch.
- Leave Pull Request creation to the user unless they explicitly request otherwise.

## 2026-08-13 - Database ID hydration

- Treat foreign-key IDs as integers at Eloquent model boundaries. MySQL/MariaDB can hydrate integer columns as strings, which breaks strict authorization comparisons unless the model casts those IDs; cover the boundary with a string-hydration regression test.

## 2026-08-13 - Pre-authentication continuation

- When a pre-authentication flow cannot reliably carry authorization through a rotated session, use a short-lived authenticated proof for the next step. Keep the database token authoritative and one-time, and never persist or log the proof.

## 2026-08-14 - Shared session guard isolation

- Multiple Laravel session guards can coexist in one browser session. Involuntary lifecycle enforcement must remove only the invalid guard, preserve unrelated authenticated guards and session data, rotate the session identifier, and leave route-specific middleware responsible for selecting the required actor.
- After auth middleware selects a native guard, shared controller actor helpers must honor that selection before guard fallbacks. Owner-first lookup can silently replace an authenticated employee when both sessions exist; cover same-shop and foreign-shop secondary sessions with positive and denial regressions.

## 2026-08-14 - Middleware priority preserves framework prerequisites

- Laravel's `Middleware::priority()` replaces the framework priority list. Any custom list must retain cookie decryption and session startup before authentication; otherwise session-backed API routes can return `401` before reading a valid browser session, especially when Sanctum does not classify the production host as stateful.

## 2026-08-23 - Checkout voucher suggestion state

- Keep voucher eligibility and claim state server-authoritative. Reuse checkout pricing and `ShippingVoucherService` so payment suggestions cannot advertise a logistics discount outside Shop-owned coverage.

## 2026-08-15 - Shop module route catalog parity

- A new named Shop Owner route is incomplete until its authoritative route bucket, method override, and module `supporting_routes` entry are updated together; `ShopModuleCatalogTest` detects drift between route registration and the capability catalog.

## 2026-08-22 - Centralized approval contract

- When a workflow is presented through a shared Action Center, freeze the approval policy at submission and let the existing domain service remain authoritative. Queue adapters, notifications, detail panels, and mutations should all derive responsibility from that same persisted snapshot.
- A named route added for a shared detail or summary surface must be added to the authoritative route catalog in the same change, including tenant-scoped `show` routes; route-catalog parity tests catch this boundary before deployment.

## 2026-08-24 - Presentation retirement and compatibility routes

- Retiring an owner presentation requires removing its flag, payload/API, metadata, middleware, generated route map, and orphaned types together; retain only an explicit, one-way GET compatibility redirect when old bookmarks still matter.
- A catalog validator should distinguish navigation-only core routes from record pages that require supporting API evidence; an empty supporting-route list must not be solved by inventing a picker API.

## 2026-08-30 - Render-loop callback initialization

- In a React effect, declare callbacks used by the first animation-frame invocation before calling the loop; a source-order regression contract protects against minified temporal-dead-zone failures.

## 2026-09-01 - Repair courier handoff gates

- Keep payment, delivery-plan locking, external courier tracking, and physical repair handoff as separate gates. Customer-arranged return tracking remains editable after balance payment and becomes immutable only after the employee records the handoff.
- Derive repair POS customer identity from the locked repair request. Never persist UI sentinel IDs or caller-supplied guest details when the repair already contains the authoritative customer snapshot.

## 2026-09-05 - Article audience boundary

- Let the server-selected article audience control employee guide visibility. Client-side per-article permission filtering can hide valid same-role guides when shared props are incomplete; keep business and registration filtering for Shop Owner variants.
- Do not inject a role-specific sidebar link to a shared page unless that account can open the route.

## 2026-09-01 - Retail return method authority

- Persist the selected retail return method as the authority: valid Staff-entered third-party tracking starts `in_transit` in customer return fields without creating a Shop-owned shipment; physical receipt and inspection remain a separate Staff action.
- For Shop-owned returns, only the tenant-scoped canonical delivered return leg can authorize inspection. Stale Shop-owned shipment records must not override an explicit third-party method, and POS sales do not need customer receipt acknowledgement.

## 2026-09-02 - Logistics role navigation

- The legacy employee `role` column may remain `STAFF` for logistics employees; use the Spatie `Logistics Dispatcher` and `Logistics Rider` roles for role-specific navigation and route boundaries.
- Hiding a restricted dashboard link is not sufficient: direct dashboard and stats requests must enforce the same role boundary server-side and redirect riders to `My Deliveries`.

## 2026-09-02 - Responsive first-open loader

- For a server-rendered first-open loader, apply the responsive bypass before adding its activation class so desktop can render immediately without an invisible handoff delay; keep a matching CSS guard for late or stale markup.

## 2026-09-03 - Portable LIKE escaping

- Explicit backslash escape clauses in SQL LIKE predicates can parse as an unterminated string in MySQL/MariaDB even when SQLite accepts them; use a portable escape marker and escape the marker, percent signs, and underscores in bound search values.

## 2026-09-03 - Flow-scoped shop policies

- Keep shared refund/payment terms in the published version, but filter retail and repair sections at the active-policy read boundary; preserve the same version ID and content hash so customer acceptance remains tied to one immutable policy version.

## 2026-08-30 - Identity screening rejection order

- Evaluate deterministic novelty/non-document signals and clear selected-type mismatches before low-confidence fallback; otherwise an obvious fake can be routed to manual review.
- For multi-side identity uploads, replace or reject the paired sides together and enforce required sides at the server boundary so images from different documents cannot be combined.

## 2026-09-06 - Article entitlement boundaries

- Normalize legacy business-type labels through the shared business access service at both server and client boundaries; direct article URLs must enforce the same feature and account-configuration scope as the filtered article hub.

## 2026-09-08 - Monochrome dropdown ownership

- Native `<select>` option highlight colors belong to the browser/OS; consistent monochrome selection requires shared custom trigger/listbox rendering across pages, while retaining a hidden native control only when a real form contract (`name`, `required`, `form`, or `multiple`) needs it.
- SweetAlert selects are injected after React renders, so they need a document observer and DOM enhancer to receive the same selected, hover, and focus treatments; the enhancer must skip SweetAlert2's hidden template select when no real input was configured.

## 2026-09-15 - Procurement receipt authority

- Completion and payable projections must identify the authoritative Final Receipt by domain meaning, not by the latest posted receipt, because later supporting replacement receipts must not create another payable or replace the original accounting source.
- Payment-dependent UI actions must consume backend eligibility projections and repeat the same guard at mutation time; receipt status alone cannot prove that its expense is fully settled.

## 2026-09-17 - Payroll financial authority

- Keep one shared payroll calculation and snapshot path from the period-effective salary through approval; approval and disbursement must validate the same financial fingerprint and use stored net pay.
- Keep employer statutory shares in the snapshot and UI as separate non-deduction values; only employee deductions reduce net pay.
- In a multi-level payslip workflow, derive the queue stage from the centralized approval's current approver role; the payroll row can remain `pending` while Finance has already forwarded it to the Shop Owner.
- Scheduled auto-clockout must persist both the checkout time and its diagnostic marker; otherwise a legitimate closing-time checkout appears indistinguishable from a manual one.

## 2026-09-19 - COD Xendit wallet payout payload

- Xendit v3 domestic wallet payouts need the customer recipient address and wallet mobile details in addition to `WALLET` plus the selected `PH_*` channel; build those fields from the immutable order snapshot and keep the saved destination encrypted and masked outside the payout request.

## 2026-09-19 - Provider payout and completed delivery reconciliation

- A payout accepted by Xendit can remain `processing` locally when a webhook cannot reach a local environment; reconcile the stored payout ID from Xendit's status endpoint before rendering Finance and customer order state.
- Keep approved delivery proof available from the completed shipment state as a legacy-data fallback, while preserving customer ownership and proof approval checks.

## 2026-09-19 - Customer proof delivery without GD

- Customer proof URLs can appear valid while the file endpoint fails with 503 when PHP GD is disabled; serve the validated private image directly as a local-environment fallback while keeping approval, ownership, MIME, and no-store checks.

## 2026-09-22 - Privileged page access boundaries

- Keep admin page access as a server-side boundary layered after account, MFA, capability, and reauthentication checks; sidebar filtering is presentation only.
- Treat Dashboard as an always-available landing page, but omit restricted module metrics and audit activity unless the corresponding page is assigned.
- Filter privileged notifications with the same page catalog before pagination and unread counts so the shared notification route cannot become a deep-link bypass.

## 2026-09-21 - Individual owner refund approval routing

- Payment settlement notifications should resolve the persisted shop display name at the notification boundary so admin alerts identify the paying shop instead of using a generic message.

- Delivery method alone must not route an individual shop owner’s online refund through Staff and Finance; centralize the third-party workflow classification and reuse it for approval, notifications, and API stage projections.

## 2026-09-25 - Observer-aware delivery accounting

- When an order status transition triggers financial observers, logistics completion must save the guarded order through Eloquent; query-builder bulk updates bypass the observer and silently omit ledger charges.

## 2026-09-27 - Platform fee reliability and COD settlement

- Refresh the reliability snapshot after confirmed platform-fee payment and initialize it from retained history when a balance page has no snapshot; otherwise paid business fees can remain absent from the score until the daily job runs.
- Treat Finance-confirmed COD remittance—not rider-reported cash collection—as the authoritative paid transition that allows the marketplace-fee observer to create its charge.

## 2026-10-02 - Refund approval stages

- Treat the `requires_owner_approval` value captured when a refund is reserved as authoritative. An explicit disabled setting skips only the Shop Owner decision; it must not skip Finance or the return and payout gates. Persist Staff, Shop Owner, and Finance decisions in their own audit fields.


## 2026-10-04 - Private notification identity and competing repair resolutions

- Scope private query data and mutation completions to guard, authenticated principal and tenant. Cancel departed reads and reset local notification UI state when that identity changes; endpoint-only keys cannot isolate account switching.
- Refund and warranty admission must lock the same original repair before checking current competing records. Intake receipt is not customer handover. Delivery-only compensation and service refunds have different warranty consequences.
- Persist provider refund reservation before contacting the payment provider, then submit outside the admission transaction. Send workflow notifications after commit so a rollback cannot leave a customer with an approval email for an uncommitted decision.


## 2026-10-04 - Owner proxies and workload identity

- New owner audit proxies use the canonical Spatie Shop Owner role with a null legacy role. The MySQL legacy role enum cannot store Shop Owner; SQLite tests alone do not establish enum compatibility.
- Workload membership requires a same-shop Employee relationship and excludes owner identity even when old Staff roles or assignments remain. Never infer owner identity by comparing User and ShopOwner primary keys.


## 2026-10-04 - Logistics movement admission and continuation

- Geographical coverage does not grant Logistics permission. Recheck persisted module eligibility under the shop/source transaction before each new internal shipment, replacement, return or retry movement. Derive third-party tracking exceptions from the persisted source rather than caller flags.
- OFF continuation must use the particular persisted started leg, its tenant and parent lifecycle. An active parent, saved carrier or assignment is insufficient, and a later pending leg does not inherit an earlier leg's start. Preserve original warranty transport constraints through claim approval and child recovery/plan editing.


### Repair material planning

Freeze quantities and source IDs when the selected work is booked, and replace them only during an authorized pre-lock edit. A null legacy snapshot and an intentionally empty snapshot have different meanings. Preserve stored legacy plans and actual usage; any initialization from current templates must identify that provenance. Reversed usage still leaves stock history, so deleting the live usage row must not reopen service editing.

- Payment return completion markers must describe verified signed context, not merely resource identity. Preserve SPA history state and a retryable signed continuation when canonicalizing; storage access can fail. Flags do not establish provider payment.
- Platform Fee service collection must exclude delivery and use the shared persisted collection authority, while comparing against the fee's existing service base. Terminal aliases and observer eligibility fields matter; current settings cannot prove historical fee rates. User, owner and POS evidence tenant namespaces require explicit linkage.


## 2026-10-04 — Tenant references and read-only exports

- User IDs and shop owner IDs are separate namespaces. Finance user actors must resolve the tenant through shop_owner_id; a coincident primary key cannot confer shop access.
- Read-only owner controls should use the same projected capabilities as guarded handlers, while backend mutation boundaries remain enforced. A report download does not imply report generation or review permission.
- A historical CSV presentation change must address already stored files. Serve a versioned sibling built from the saved report snapshot; retain original artifact/path and generation/review decisions, including when the original file is missing. Snapshot assignee names for new reports and batch legacy fallbacks within the tenant.
- Spreadsheet escaping belongs to textual presentation boundaries. Escape formula-leading user text after leading whitespace/control characters, while preserving known numeric monetary/count cells. CSV delimiter quoting alone does not prevent spreadsheet formulas.

- For an existing Employee fixture, clock in that exact record. The repository's clockInEmployee helper creates a new Employee; calling it on an already linked fixture can create ambiguous duplicate email/tenant links and correctly trigger fail-closed authorization.
- System-created delivery-loss refund claims are not ordinary company customer submissions. Notification recipient classification should follow their saved workflow while retaining existing after-commit, idempotency and tenant boundaries; do not alter payment approval rules to repair an alert.
- UTF-8 CSV bytes alone do not establish spreadsheet auto-detection. Default Excel opening misread accented names and peso/item symbols; a UTF-8 signature at the shared export boundary preserves those characters without changing numeric cells. Verify the actual application import as well as CSV parsing, and retain historical original artifacts.

- Controller preflight cannot authorize a later internal dispatch after a module toggle. Revalidate persisted module state in the shared assignment/batch/start transaction, taking the shop lock before dispatch records. Enabled positive fixtures should declare their module state rather than depend on missing initialization.
- External courier tracking can legitimately record in_transit and pickup timestamps. Those fields are not evidence of internal custody; classify the persisted source before granting internal assignment or continued shop-owned movement. Retain external tracking updates and historical records.
- Under InnoDB repeatable read, acquiring a row lock does not refresh an earlier consistent-read snapshot. State checked after waiting for a competing mutation must use a locking current read, with a consistent parent-before-child lock order. Verify the competing connection's actual wait and final revalidation; stale-model sequential tests cannot prove this behavior.
- When an application replaces Laravel's default notifications table with a custom inbox schema, framework database-channel sends are incompatible even if Notification::fake tests pass. Exercise the real inbox write and after-commit path, fake only external delivery, and reuse the application's notification service. An after-commit delivery exception can appear as a failed API response after the business record has already been saved.

## 2026-10-05 — Publishing verified work after rebase

- A rebase invalidates earlier build output. Regenerate tracked deployment assets from the rebased source, check every manifest reference, and stage source/documentation paths and build output explicitly; keep runtime cache and unrelated local work outside the commit.
- Failure comparisons across upstream updates must account for shifted source lines. Match an exact case first, then a uniquely identified class/case when only its location moved; retain failure-reason and response-status checks. Ambiguous matches require investigation, and baseline matching does not make a failing full suite green.

- Notification links preserve historical intent and may outlive a decision. Derive refund controls from current raw workflow status and the server's owner-action projection, preserving legacy status fallbacks; a display label such as Refunded is not the stored status succeeded. Resolved notifications should open the existing read-only History view, while old links still require the same live-detail gate.

## 2026-10-06 — Review moderation and business-date boundaries

- Review-report identity is the reporting shop plus review type and review ID, not the customer or open moderation status. Serialize creation by locking the review row even before a report exists; historical dismissal does not erase the reporting fact.
- A new moderation decision and a new account suspension are separate operations. Reuse a validated current suspension only for report resolution; keep ordinary account lifecycle conflict checks and per-report audit events.
- Administrative in-app notification URLs must be relative because the inbox deliberately rejects absolute links. Keep email links absolute, and normalize historical notifications by their known event to the named authenticated route.
- Finance date-only validation uses `app.shop_timezone`, while timestamp storage remains UTC. Share the server business calendar date with date inputs instead of deriving it through browser UTC conversions.

## 2026-10-07 — Immutable retail coverage and delivery

- A multi-item purchase can have one historical certificate/email while each original order item tracks independent quantities. Keep published document facts separate from live reservations, successful consumption and effective expiry.
- Snapshot promises at authoritative fulfillment, including delayed-payment context. A permanent first-enable purchase cutover and a captured ineligible marker prevent status/event replay from creating retroactive promises.
- Queue uniqueness does not establish delivery correctness. Persist a transport attempt before sending, distinguish definite pre-send failure from uncertain acceptance, and prevent normal retries of uncertainty. Catch enqueue failure after the outer commit so durable business records remain successful and recoverable.
- Idempotent recovery must preserve both saved context and selected lines. Checking only the incoming request basis leaves an existing warranty reservation rewritable through an ordinary replay.
- Read projections must follow existing payment reconciliation when that reconciliation can create the entity being displayed. Effective expiry reads should remain separate from scheduled expiry writes.
- Reversible additive migrations must remove indexes before dropping indexed columns. Verify only the new migrations' down/up boundary when older unrelated migration downs are incompatible with the test database.
## 2026-10-08 - Shipping classification and dispatcher projections

- Carrier-only shipping forms must update the canonical delivery method when the carrier changes; preserving a previous non-null method can misclassify a newly selected shipping business. Derive through the existing resolver inside the locked shipping transition and test both directions.
- Internal dispatcher pools, suggestions and controls must follow the same delivery classification as mutation policy. Third-party tracking is readable but cannot become an internal rider assignment through a permission grant. Use batched tenant-scoped classification for collection reads.
- An empty 403 does not identify a missing role permission. Check policy reason and deployed backend; warning logs will be absent when the configured log level is error. Return safe nonblank denial feedback without logging sensitive fields or weakening authorization.
- COD collection must gate delivery proof submission as well as the final delivered transition. Reuse the shared collection check in the proof service and mirror that prerequisite in the rider page.

## 2026-10-08 - Registered warranty configuration access

- Registration may persist legacy display values such as `both (retail & repair)` while seeded records use canonical `both`. Normalize persisted business types through `BusinessAccessControlService` before capability checks; preserve the stored value unless a deliberate data migration is needed.
