# Shop Owner Phase 2 rollout guide

This guide covers the controlled rollout of the canonical Shop Owner shell. The shell changes presentation and stable entry URLs only; existing controllers, module decisions, authorization middleware, APIs, and domain routes remain authoritative.

## Baseline verification evidence

The stabilized baseline currently records:

- `php artisan test tests/Feature/ShopOwner/CanonicalShell tests/Unit/Services/OwnerShell tests/Unit/Support/OwnerShell --compact` — **150 passed, 1,945 assertions**.
- `php artisan test tests/Feature/ShopOwner/CanonicalShell tests/Feature/BusinessScaling/OwnerErpRolloutConfigurationTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php tests/Feature/BusinessScaling/ShopModuleMiddlewareTest.php tests/Feature/BusinessScaling/InertiaModuleStateShareTest.php --compact` — **146 passed, 2,102 assertions**.
- `php artisan test tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php --compact` — **5 passed, 12,372 assertions**.
- `node node_modules/vitest/vitest.mjs run` — **110 test files, 602 tests passed**.
- `node node_modules/vite/bin/vite.js build` — **3,705 modules transformed; build passed**.
- `composer test` — **not completed**: the repository-wide suite exceeded the 120-second verification window and reported failures outside the focused Phase 2 gates before termination; the focused and owner/ERP regression gates above are the authoritative Phase 2 evidence.

The repository environment does not expose `pnpm`, so the equivalent local Vitest/Vite entry points were used. No standalone TypeScript or lint script is committed, so neither is claimed here. Browser verification remains a rollout-time check: this worktree had no local application listener or authenticated cohort fixtures available.

## Configuration

| Variable | Meaning | Safe initial value |
| --- | --- | --- |
| `SHOP_OWNER_CANONICAL_SHELL_ENABLED` | Global presentation kill switch | `false` |
| `SHOP_OWNER_CANONICAL_SHELL_SHOP_IDS` | Comma-separated Shop Owner primary keys allowed into the canonical presentation | empty |
| `SHOP_OWNER_ERP_WORKSPACE_ENABLED` | Existing ERP workspace and compatibility fallback gate only | preserve current value |

The allowlist uses the Shop Owner primary key. It never uses email, registration text, or mutable profile data. No `.env` change is required to deploy the routes or code.

## Deployment and rollback order

1. Deploy with the canonical shell flag off. Verify that canonical URLs are registered, bookmarked capability pages render, and existing Shop Owner and employee ERP presentations remain intact.
2. Enable the global flag with an empty allowlist. The selected presentation must remain `existing` for every shop.
3. Add internal/test Shop Owner IDs one at a time. Verify individual and company contexts, module eligibility, tenant isolation, canonical URL stability, and both owner page families.
4. Expand the allowlist only after the capability evidence table below has no unresolved authorization, behavior, or browser parity finding for the intended capability.
5. To roll back, remove the affected Shop Owner ID or set `SHOP_OWNER_CANONICAL_SHELL_ENABLED=false`. Canonical URLs remain registered and continue to render their underlying capability in the existing frame.

Rollback does not disable or rewrite domain routes, module rows, authorization decisions, or the existing ERP workspace. The ERP workspace fallback remains available only when its existing eligibility rules pass.

## Presentation selection and safe telemetry

The server selects one complete presentation before the Inertia response is committed. The stable selection reasons are:

- `global_disabled`
- `shop_not_allowlisted`
- `shop_allowlisted`
- `invalid_registration_context`
- `cohort_evaluation_failed`
- `shell_composition_failed`

Selection telemetry is emitted only on the first session selection or when the presentation/reason pair changes. It contains the stable `shop_id`, presentation, reason, and correlation/session identifier. It must not contain email addresses, credentials, arbitrary client metadata, or permission evidence.

## ERP fallback

The secondary `Open existing ERP Workspace` link is visible only for a canonical Shop Owner presentation that also passes the existing ERP workspace eligibility check. Opening it re-runs rollout and workspace eligibility on the server before redirecting to `shop-owner.erp.workspace`.

Allowed fallback reasons are exactly `missing_destination`, `missing_action`, `verification`, and `user_preference`. Allowed source keys are server-owned capability/page keys from `OpenOwnerErpFallbackRequest`; arbitrary reasons, source text, and external return URLs are rejected. Fallback telemetry records only the stable `shop_id`, fixed reason, fixed source key, and correlation/session identifier.

## Per-capability evidence

The automated parity evidence covers canonical route existence, compatibility source mapping, component parity, tenant identity for module pages, unauthenticated behavior, and module-denial status/code parity where applicable. A capability remains incomplete when it still depends on an unresolved `missing_action` or `missing_destination` fallback.

| Capability | Canonical route | Compatibility source | Auth/behavior evidence | Browser result | Fallback still required | Migration complete |
| --- | --- | --- | --- | --- | --- | --- |
| Home | `shop-owner.shell.home` `/shop-owner/home` | `shop-owner.dashboard` | Tested; existing dashboard component and tenant behavior | Record per rollout cohort | No | Yes |
| Retail | `shop-owner.shell.operate.retail` `/shop-owner/operate/retail` | `shop-owner.erp.module`, `module=retail` | Tested; module denial/status and tenant parity | Record per rollout cohort | No | Yes |
| Repair | `shop-owner.shell.operate.repair` `/shop-owner/operate/repair` | `shop-owner.erp.module`, `module=repair` | Tested; module denial/status and tenant parity | Record per rollout cohort | No | Yes |
| Customers | `shop-owner.shell.operate.customers` `/shop-owner/operate/customers` | `shop-owner.erp.module`, `module=crm` | Tested; module denial/status and tenant parity | Record per rollout cohort | No | Yes |
| Payments | `shop-owner.shell.operate.payments` `/shop-owner/operate/payments` | Authorized Retail/Repair POS source routes only; no single compatibility landing | Landing and safe-link behavior tested; direct action parity remains bounded by source-route eligibility | Record Retail and Repair POS scenarios | Yes — retain fallback until every source action is migration-complete | No |
| Finance | `shop-owner.shell.oversee.finance` `/shop-owner/oversee/finance` | `shop-owner.erp.module`, `module=finance` | Tested; module denial/status and tenant parity | Record per rollout cohort | No | Yes |
| Workforce | `shop-owner.shell.oversee.workforce` `/shop-owner/oversee/workforce` | `shop-owner.erp.module`, `module=hr` | Tested; module denial/status and tenant parity | Record per rollout cohort | No | Yes |
| Inventory | `shop-owner.shell.oversee.inventory` `/shop-owner/oversee/inventory` | `shop-owner.erp.module`, `module=inventory` | Tested; module denial/status and tenant parity | Record per rollout cohort | No | Yes |
| Procurement | `shop-owner.shell.oversee.procurement` `/shop-owner/oversee/procurement` | `shop-owner.erp.module`, `module=procurement` | Tested; module denial/status and tenant parity | Record per rollout cohort | No | Yes |
| Logistics | `shop-owner.shell.oversee.logistics` `/shop-owner/oversee/logistics` | `shop-owner.erp.module`, `module=logistics` | Tested; module denial/status and tenant parity | Record per rollout cohort | No | Yes |
| Reports | `shop-owner.shell.reports` `/shop-owner/reports` | `shop-owner.erp.manager.reports` | Tested; reused manager-backed page | Record per rollout cohort | No | Yes |
| Audit | `shop-owner.shell.audit` `/shop-owner/audit` | `shop-owner.erp.manager.audit-logs` | Tested; reused manager-backed page | Record per rollout cohort | No | Yes |
| Settings: Profile | `shop-owner.shell.settings.profile` `/shop-owner/settings/profile` | `shop-owner.settings`, `initial_section=profile` | Tested; existing settings component and mutations | Record per rollout cohort | No | Yes |
| Settings: Modules & Team | `shop-owner.shell.settings.modules-team` `/shop-owner/settings/modules-team` | `shop-owner.settings`, `initial_section=modules-team` | Tested; existing settings component and mutations | Record per rollout cohort | No | Yes |
| Settings: Payments & Approvals | `shop-owner.shell.settings.payments-approvals` `/shop-owner/settings/payments-approvals` | `shop-owner.settings`, `initial_section=payments-approvals` | Tested; existing settings component and mutations | Record per rollout cohort | No | Yes |
| Settings: Operations | `shop-owner.shell.settings.operations` `/shop-owner/settings/operations` | `shop-owner.settings`, `initial_section=operations` | Tested; existing settings component and mutations | Record per rollout cohort | No | Yes |
| Settings: Policies & Compliance | `shop-owner.shell.settings.policies-compliance` `/shop-owner/settings/policies-compliance` | `shop-owner.settings`, `initial_section=policies-compliance` | Tested; existing settings component and mutations | Record per rollout cohort | No | Yes |
| Settings: Subscription | `shop-owner.shell.settings.subscription` `/shop-owner/settings/subscription` | `shop-owner.settings`, `initial_section=subscription` | Tested; existing settings component and mutations | Record per rollout cohort | No | Yes |

## Browser verification checklist

For each test cohort, record the shop primary key, flag values, viewport, URL, result, and screenshot or browser-log reference. Cover:

- individual Retail, individual Repair, company Retail, and company `both` owners;
- Operate/Oversee ordering, default expansion, unavailable-item text, and empty-group omission;
- canonical bookmarks and compatibility URLs without redirect loops;
- owner-mode ERP pages inside the canonical frame and employee ERP pages in the existing frame;
- desktop and mobile navigation, keyboard traversal, focus visibility/restoration, backdrop dismissal, active announcement, and reduced-motion behavior;
- informational Home placeholders with no counts or approval/exception aggregation;
- fixed-key ERP fallback validation and kill-switch rollback.

There is no checked-in `scripts/browser-smoke.mjs` in this worktree. Use the repository’s local browser tooling or a manual local-browser run for the scenarios above, and record unavailable fixtures explicitly rather than treating them as automated coverage.

## Scope guardrails

Do not expand the allowlist or mark a capability complete if doing so requires a new permission system, client-side authorization computation, approval/exception aggregation, a legacy-route retirement, or an unresolved fallback action. Keep the existing ERP fallback until the later retirement criteria are met.
