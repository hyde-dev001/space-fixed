# Cross-Role Session and Registration Approval Reliability Design

## Status

Approved for implementation on 2026-08-14.

## Problem

Valid customer, employee, and shop-owner sessions can appear to log out during ordinary navigation or actions. The reported reproductions include:

- a newly registered customer using checkout location or the address map;
- a seeded staff account opening **Add New Product** and then navigating through the ERP sidebar;
- a shop owner navigating or performing actions in the owner ERP workspace; and
- a Super Admin attempting to approve shop-owner registration 7, which remains pending after a production `500` response with correlation ID `e87e8e8e-4e82-4179-a180-0f4aa1c0b340`.

The logout symptom has two confirmed code-level causes.

First, the application supports several Laravel session guards in one browser session. Logging into a customer, employee, or shop-owner account does not remove the other guard sessions. The globally applied `CheckEmployeeSuspension` middleware inspects both `shop_owner` and `user` on every web and API request. When either guard references a pending, suspended, archived, deleted, duplicate, or otherwise unavailable account, the middleware logs out that guard and invalidates the entire Laravel session. Session invalidation also removes an unrelated valid guard.

This boundary is exposed by normal application behavior. Opening **Add New Product** immediately calls a protected showroom-entitlement endpoint. Shop-owner sidebar links also prefetch on hover. Either request can run the global middleware before the next visible navigation, making the subsequent click appear to have caused the logout.

Second, customer registration and one unverified-login path authenticate through the `web` guard, while customer addresses, checkout, badges, orders, and other protected customer endpoints require `auth:user`. The customer badge poll redirects to `/user/login` on any `401`, so a guard mismatch or transient unauthenticated response is presented as an active logout.

The registration approval transaction succeeds in the current local workflow tests, while production returned an unhandled HTML `500`. The current approval controller is expected to normalize service failures. This combination indicates that production must also be checked for unapplied migrations, stale Laravel caches, or stale long-lived PHP workers. The implementation must add an end-to-end registration-to-approval regression and make the required deployment synchronization explicit rather than weakening approval authorization or transaction rules.

## Outcome

A valid actor must remain authenticated while navigating and performing actions in the customer, employee ERP, or owner ERP workspace, even when the same browser session contains an unrelated stale or unavailable guard.

The following flows must work:

1. A newly registered and verified customer can access protected customer APIs, choose a saved or current location, navigate the map, and place an order without being redirected by background polling.
2. An active staff account for an approved shop can open **Add New Product**, complete or dismiss product work, and navigate to another authorized ERP page without losing its `user` guard.
3. An approved shop owner can use owner ERP sidebar prefetch, page navigation, and owner-scoped API actions without losing the `shop_owner` guard.
4. An unavailable account is still logged out and denied from operational routes. A stale unavailable guard cannot grant access and cannot invalidate another valid guard.
5. A correctly deployed application with all migrations applied can approve a complete pending shop-owner registration atomically.

## Chosen approach

Use guard-isolated lifecycle enforcement and make `user` the canonical customer guard.

### Guard-isolated lifecycle enforcement

`CheckEmployeeSuspension` will continue to validate authenticated account lifecycle state globally. It will no longer invalidate the complete session when one guard fails.

For each supported non-privileged guard:

1. Reload the authenticated account, including archived records.
2. Apply the existing lifecycle checks for account, parent shop, and linked employee status.
3. If the guard is unavailable, call `logout()` only on that guard and record its safe denial response.
4. Preserve all unrelated session data and authenticated guards. Rotate the session identifier after removing an invalid guard without flushing the session.
5. Continue evaluating the other supported guard.
6. If at least one valid guard remains, continue the request. Existing route authentication, ERP audience resolution, permissions, module enforcement, tenant isolation, and policies select or reject the actor required by that route.
7. If no valid guard remains and an unavailable guard was removed, return the existing generic lifecycle denial for that account type.

The owner ERP route-family bypass in the lifecycle middleware is no longer needed once guard isolation is safe. Owner ERP pages and APIs must receive consistent lifecycle enforcement.

Narrow onboarding and recovery exceptions remain explicit. A pending owner may use only the pending-approval and required setup routes. A rejected owner may use only the existing signed resubmission and private-document routes. These exceptions do not grant access to operational shop-owner or ERP modules.

Employee lookup will be tenant-scoped by both normalized email and `shop_owner_id`. A duplicate email in another shop must not disable the current shop's employee. Multiple matching employee records inside the same shop remain a fail-closed unavailable-account condition.

This approach does not reinterpret one role as another. `EnsureErpAudience`, route guard middleware, module gates, permissions, tenant isolation, and actor-context resolution remain authoritative.

### Canonical customer authentication

Customer registration, customer login, and customer email-verification continuation will establish the `user` guard. Shop-owner registration and verification remain on `shop_owner`. Legacy `web` routes that are unrelated to the customer storefront are not globally removed in this change.

Customer-protected endpoints remain on `auth:user`; the fix aligns authentication with that existing contract instead of broadening those endpoints to accept another guard.

### Passive background polling

The customer badge-count hook will treat `401` as an unavailable background data source for the current mount. It will clear or retain safe zero counts and stop that polling loop without initiating navigation. Protected page requests and explicit customer actions remain responsible for redirecting an unauthenticated customer through the normal Laravel/Inertia authentication flow.

The hook will not write authentication state or credentials to browser storage.

### ERP navigation and product actions

Sidebar prefetch and the product-entitlement request are request triggers, not authorization authorities. They will remain enabled unless a focused frontend regression demonstrates a separate URL-generation defect.

Regression coverage will verify that:

- employee sidebar links target employee ERP routes;
- owner ERP links target owner ERP routes supplied by the server catalog;
- opening the product modal can call the appropriate employee or owner entitlement endpoint; and
- those requests preserve the valid route actor when another guard is unavailable.

### Registration approval reliability

The existing locked transaction remains the approval authority. Password-reset token creation, document decisions, module initialization, audit recording, account-state update, and after-commit mail behavior remain atomic.

The implementation will add a feature test that creates a registration through the current registration/lifecycle-document flow and approves that same record through the privileged approval endpoint. The test will prove that the owner becomes approved, required document review fields are written, eligible module rows exist, and the approval audit is present.

Unexpected approval failures for Inertia requests must remain credential-safe, include an `X-Correlation-ID`, and avoid raw exception details. No production exception will be guessed around with a partial commit or skipped invariant.

Deployment instructions will require, in order:

1. deploy the matching backend and frontend build;
2. run `php artisan migrate --force`;
3. clear and rebuild the normal Laravel caches;
4. restart long-lived PHP workers or PHP-FPM/OPcache as supported by the host; and
5. retry the pending approval while recording any new correlation ID.

If the end-to-end test passes but production still returns `500`, the new correlation ID and server log are required to identify environmental drift before another code change.

## Security invariants

- Suspended, archived, deleted, pending, rejected, or otherwise unavailable accounts remain unable to use operational protected routes. Pending and rejected owners retain only their explicitly authorized onboarding or recovery routes.
- Removing an invalid guard never authenticates or promotes another guard.
- Route-specific authentication and `EnsureErpAudience` remain fail closed when the required guard is absent.
- A valid employee remains bound to its own `shop_owner_id`; employee status is never resolved from another tenant by email alone.
- Session identifier rotation after guard removal preserves valid guard state but prevents continued use of the previous session identifier.
- Explicit logout behavior, CSRF protection, session cookie security, permission checks, module checks, and tenant isolation remain unchanged unless a regression test proves a required compatibility adjustment.
- Background `401` handling does not hide authorization failures from explicit protected actions.
- Registration approval cannot bypass document completeness, account status, audit success, or transaction rollback.
- Passwords, session identifiers, cookies, CSRF values, and approval document contents are never logged or added to correlation metadata.

## Error handling

- An unavailable guard receives the existing generic `account_suspended` or `account_unavailable` response when no valid application guard can continue the request.
- When an unrelated valid guard remains, the stale guard is removed and downstream route middleware decides access for the requested route.
- A customer badge `401` stops background polling without redirecting.
- Explicit protected requests continue to receive Laravel/Inertia authentication responses.
- Approval validation errors remain field-specific and non-destructive.
- Unexpected approval failures return a safe error with a correlation ID and leave the owner, documents, modules, and audit state unchanged.

## Alternatives rejected

### Force one account type per browser session

Every login could explicitly log out all other guards. This is smaller, but the application already contains deliberate dual-guard actor-boundary behavior and users may legitimately switch between owner and employee workspaces in one browser. It also treats session coexistence as the bug instead of fixing unsafe global invalidation.

### Separate cookies or subdomains per role

Dedicated role sessions provide strong isolation but require broader cookie, domain, CSRF, deployment, and frontend changes. Guard-isolated enforcement solves the demonstrated failure without changing the hosting topology.

### Remove sidebar prefetch or product entitlement requests

This would delay the failure rather than remove it. Another protected request would still trigger complete session invalidation.

### Weaken lifecycle or approval authorization

Allowing pending owners, skipping employee status checks, broadening customer endpoints to multiple guards, or bypassing approval invariants would hide symptoms by creating authorization gaps. The chosen approach preserves those controls.

## Test strategy

Backend feature coverage will prove:

- an unavailable `shop_owner` guard is removed while a valid `user` guard and its session remain usable;
- an unavailable `user` guard is removed while a valid `shop_owner` guard and its session remain usable;
- a single unavailable guard is still logged out and denied;
- a suspended employee or suspended parent shop remains denied;
- an employee record with the same email in another shop does not invalidate the current employee;
- duplicate matching employee records in the same shop remain denied;
- staff product entitlement and subsequent ERP navigation preserve the staff session;
- owner product entitlement, owner API actions, and subsequent ERP navigation preserve the owner session;
- customer registration and verification establish `auth:user` and can access addresses, badge counts, and checkout endpoints;
- the complete shop-owner registration flow can be approved atomically by an authorized privileged actor; and
- forced audit or transaction failure rolls approval state back.

Frontend coverage will prove:

- badge polling does not call `router.visit` after a `401`;
- polling stops or remains quiescent after the unauthenticated response;
- employee and owner product pages call their correct entitlement endpoints; and
- existing sidebar route and owner-catalog tests continue to pass.

Focused verification will include the relevant Laravel feature tests, frontend tests, production frontend build, and `git diff --check`.

## Deployment and recovery

Deploy the backend commit and matching generated frontend assets together. Apply all migrations before accepting traffic where possible, refresh Laravel caches, and restart long-lived PHP processes so route, middleware, and controller code are synchronized.

After deployment:

1. Sign out or use a private window for the first smoke test to establish a clean baseline.
2. Verify customer registration, address/location selection, and checkout navigation.
3. Verify staff **Add New Product** followed by another authorized sidebar page.
4. Verify owner ERP page navigation and one owner-scoped read action.
5. Retry registration 7 approval and confirm its status changes from pending.
6. If approval fails, capture the new `X-Correlation-ID` and inspect the matching server log before retrying or changing data.

No destructive data reset, authorization bypass, new dependency, or schema rollback is part of this design.
