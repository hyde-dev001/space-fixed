# Customer MFA and Security Activity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add optional customer TOTP MFA with mandatory verification on later customer logins, plus private Security Activity in the customer profile, without exposing Active Sessions.

**Architecture:** Reuse the existing encrypted `employee_totp_*` storage, `EmployeeMfaService`, `EmployeeMfaController` cryptographic operations, and `EmployeeSecurityService` audit helper. Add customer-only route/controller entry points and a customer-specific pending-login session namespace, then make the existing MFA profile component accept route names and an explicit `showSessions` flag so the employee UI remains unchanged and the customer UI has no session code path.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript 5.7, Vitest, PHPUnit, Ziggy, Axios, existing Google2FA/BaconQrCode dependencies.

---

## File map

- Modify `app/Models/User.php`: expose account-neutral TOTP state while preserving employee/customer account guards.
- Modify `app/Services/EmployeeSecurityService.php`: add a shared customer security-audit writer without changing existing employee audit records.
- Modify `app/Http/Controllers/EmployeeMfaController.php`: add customer-scoped setup, recovery, disable, challenge, and verification entry points backed by the existing MFA implementation.
- Modify `app/Http/Controllers/UserController.php`: stop customer login before normal authentication when customer MFA is enrolled, and complete customer MFA login only after factor verification.
- Modify `app/Http/Controllers/UserSide/CustomerProfileController.php`: include customer MFA/activity state and serve a customer-only paginated activity endpoint.
- Modify `routes/web.php`: register customer MFA/challenge/activity routes with customer middleware and throttles; do not register customer session routes.
- Modify `resources/js/components/UserProfile/EmployeeTotpSecurity.tsx`: support configurable endpoint names and conditional session rendering while preserving employee defaults.
- Modify `resources/js/Pages/ERP/EmployeeMfaChallenge.tsx`: accept optional verification/login routes so the same challenge view can serve customers.
- Modify `resources/js/Pages/UserSide/Profile/customerProfile.tsx`: render the shared MFA/security component with customer routes and `showSessions={false}`.
- Regenerate `resources/js/ziggy.js`: include the new named customer security/challenge routes.
- Create `tests/Feature/CustomerMfaSecurityTest.php`: cover enrollment, login challenge, recovery/disable, activity privacy, and employee isolation.
- Modify `resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx`: cover customer security rendering and absence of Active Sessions.
- Modify `resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx`: cover route overrides and retain the employee default contract.
- Modify `resources/js/__tests__/ziggySecurityRoutes.test.ts`: assert the customer route names are present.

### Task 1: Add failing backend tests for customer account security

**Files:**
- Create: `tests/Feature/CustomerMfaSecurityTest.php`
- Inspect: `tests/Feature/EmployeeMfaRestorationTest.php`, `tests/Feature/UserSide/CustomerPasswordUpdateTest.php`

- [x] **Step 1: Write failing tests for profile security state and activity privacy**

Create customer users with verified email and a known password. Assert `GET /customer-profile` includes `security.totp_enabled` and recent activity. Insert customer and employee audit records with distinct tags and assert the customer endpoint returns only the authenticated customer’s `customer_security` rows.

- [x] **Step 2: Write failing tests for enrollment and recovery lifecycle**

Exercise customer setup with the current password, verify the generated TOTP using the returned setup secret, assert encrypted secret/enabled timestamp/recovery hashes are stored, assert a recovery code is single-use, and assert disable clears the TOTP fields and invalidates the security version.

- [x] **Step 3: Write failing tests for customer MFA login**

Enroll a customer, post valid credentials to `user.login`, and assert a `202` JSON response with `requires_mfa`, no successful-login message, and `assertGuest('user')`. Verify an invalid code does not authenticate, then verify a valid TOTP authenticates and redirects to the existing customer landing route.

- [x] **Step 4: Write failing tests for account isolation and no sessions**

Assert an employee cannot use customer MFA/activity routes, a customer cannot use ERP MFA/activity routes, customer activity cannot read another customer’s rows, and the route collection contains no customer Active Sessions route.

- [x] **Step 5: Run the focused backend test and confirm failure**

Run:

```bash
php artisan test tests/Feature/CustomerMfaSecurityTest.php
```

Expected: FAIL because customer security routes, payload, and login challenge do not yet exist.

### Task 2: Generalize account TOTP state and security auditing

**Files:**
- Modify: `app/Models/User.php`
- Modify: `app/Services/EmployeeSecurityService.php`
- Test: `tests/Feature/CustomerMfaSecurityTest.php`

- [x] **Step 1: Add account-neutral TOTP state methods**

Add one shared `hasTotpEnabled()` predicate for the existing encrypted secret and enabled timestamp. Keep `hasEmployeeTotpEnabled()` restricted to `isEmployeeAccount()` and add a customer counterpart restricted to `isCustomerAccount()`; both delegate to the shared predicate so the storage rules do not diverge.

- [x] **Step 2: Add an explicit customer audit method**

Add `auditCustomer(User $target, string $action, string $description, string $severity = ...)` that rejects/non-logs non-customer targets, writes a nullable-shop-owner, null-employee record for the authenticated customer, and tags it `customer_security`. Keep `audit()` and its `employee_security` output unchanged for ERP callers.

- [x] **Step 3: Run the relevant backend tests**

Run:

```bash
php artisan test tests/Feature/CustomerMfaSecurityTest.php tests/Feature/EmployeeMfaRestorationTest.php
```

Expected: the new model/service assertions pass or proceed to the expected controller/route failures; existing employee tests remain green.

### Task 3: Add customer MFA setup, account challenge, and profile API behavior

**Files:**
- Modify: `app/Http/Controllers/EmployeeMfaController.php`
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `app/Http/Controllers/UserSide/CustomerProfileController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CustomerMfaSecurityTest.php`

- [x] **Step 1: Define customer-specific session keys and route entry points**

Add customer wrappers or scoped methods around the existing setup/verify/recovery/disable/challenge/verify-login operations. Use customer-specific setup and pending-login session keys, tie every pending record to the authenticated/customer user ID and `security_version`, and preserve the existing ten-minute setup expiration and five-attempt challenge limit.

- [x] **Step 2: Reuse the existing MFA cryptography and storage**

Use `EmployeeMfaService` for secret generation, provisioning URI/QR, TOTP verification, recovery generation/hashing, and one-time recovery consumption. Customer operations must use the same transaction and row-lock pattern as employee operations and must never accept a user ID or secret from the client.

- [x] **Step 3: Gate customer login before `Auth::guard('user')->login()`**

In `UserController@login`, after customer email/status checks and before normal login, detect `hasCustomerTotpEnabled()`. If enrolled, log out/regenerate the session, store the customer pending ID/security version/remember flag, and return the customer challenge redirect/`202` response. Do not update `last_login_at` or claim successful login until factor verification succeeds.

- [x] **Step 4: Complete customer challenge atomically**

Lock the pending customer row, revalidate active status/security version/customer account/enrollment, consume TOTP or a recovery code, update last-login fields, clear pending keys, regenerate the session, log in the customer, mark the security version, and audit successful login plus MFA verification. Use generic invalid-code responses and leave the guard unauthenticated on failure.

- [x] **Step 5: Add customer profile state and activity endpoint**

Add `security.totp_enabled` and five most recent customer-scoped activity rows to `CustomerProfileController@show`. Add a paginated JSON endpoint that filters by authenticated customer ID, `User::class` entity ID, and `customer_security` tag. Never return secret, recovery-code, session, or employee fields.

- [x] **Step 6: Register only customer MFA/activity routes**

Use `auth:user`, `customer.account`, and existing throttle middleware for setup, verify, recovery regeneration, disable, and activity. The login challenge GET/POST routes intentionally use throttling without `auth:user` because the guard is logged out while the customer is pending MFA; the controller validates the customer-specific pending session, account type, and security version instead. Use customer route names such as `customer.security.totp.setup`, `customer.security.totp.verify`, `customer.security.totp.recovery.regenerate`, `customer.security.totp.disable`, `customer.mfa.challenge`, `customer.mfa.challenge.verify`, and `customer.security.activity`. Do not add `customer.security.sessions` routes.

- [x] **Step 7: Run backend tests and fix failures**

Run:

```bash
php artisan test tests/Feature/CustomerMfaSecurityTest.php tests/Feature/EmployeeMfaRestorationTest.php tests/Feature/UserSide/CustomerPasswordUpdateTest.php
```

Expected: all listed tests pass.

### Task 4: Reuse the MFA UI without exposing customer sessions

**Files:**
- Modify: `resources/js/components/UserProfile/EmployeeTotpSecurity.tsx`
- Modify: `resources/js/Pages/ERP/EmployeeMfaChallenge.tsx`
- Modify: `resources/js/Pages/UserSide/Profile/customerProfile.tsx`
- Modify: `resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx`
- Modify: `resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx`
- Modify: `resources/js/__tests__/ziggySecurityRoutes.test.ts`
- Regenerate: `resources/js/ziggy.js`

- [x] **Step 1: Add route configuration with employee-safe defaults**

Make the shared component accept setup, verify, recovery, disable, and activity route names, defaulting to the current ERP names. Add `showSessions` defaulting to `true` for employees. Keep session state, requests, history, and logout controls behind that flag so customer rendering has no session request or Active Sessions markup.

- [x] **Step 2: Make the challenge page accept optional route names**

Keep the current employee route and login-link defaults. Allow the customer controller to pass the customer verification route and customer login-form route while using the same six-digit/recovery-code UX.

- [x] **Step 3: Mount the component once in the customer profile**

Pass the server-provided customer MFA/activity state, customer route names, and `showSessions={false}`. Mount the shared panel once inside the active responsive profile card (mobile/tablet or desktop) so it remains part of the customer Profile card without duplicating its state. Keep profile editing and password change separate.

- [x] **Step 4: Regenerate Ziggy and update frontend contracts**

Run the repository’s Ziggy generation command and assert the new customer route names exist. Do not add any customer session route names.

- [x] **Step 5: Add frontend regression assertions**

Assert the customer profile displays the security/MFA area, uses customer route names when setup is submitted, displays activity “View all,” and contains no “Active Sessions,” “Log Out Other Sessions,” ERP security route, or session request. Assert the existing employee component still uses ERP defaults and renders its session section.

- [x] **Step 6: Run focused frontend tests**

Run:

```bash
pnpm exec vitest run resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx resources/js/__tests__/ziggySecurityRoutes.test.ts
```

Expected: PASS.

### Task 5: Review security boundaries and simplify

**Files:**
- Review all files changed in Tasks 1–4.
- Modify only if a concrete test/review finding requires it.

- [x] **Step 1: Run diff hygiene and inspect the diff**

Run:

```bash
git diff --check
git diff --stat
git diff -- app/Models/User.php app/Services/EmployeeSecurityService.php app/Http/Controllers/EmployeeMfaController.php app/Http/Controllers/UserController.php app/Http/Controllers/UserSide/CustomerProfileController.php routes/web.php resources/js/components/UserProfile/EmployeeTotpSecurity.tsx resources/js/Pages/ERP/EmployeeMfaChallenge.tsx resources/js/Pages/UserSide/Profile/customerProfile.tsx
```

Confirm no Active Sessions endpoint, prop, request, or customer UI survived; no employee route lost its guard; and no MFA secret/recovery code enters an Inertia or JSON payload.

- [x] **Step 2: Apply the required sequential reviews**

Review against repository standards, the approved design, Laravel security practices, React/TypeScript boundaries, ponytail simplification, and the security-review checklist. Resolve concrete findings only; avoid unrelated refactors.

- [x] **Step 3: Run the full relevant quality gates**

Run:

```bash
php artisan test tests/Feature/CustomerMfaSecurityTest.php tests/Feature/EmployeeMfaRestorationTest.php tests/Feature/UserSide/CustomerPasswordUpdateTest.php
pnpm run test:frontend
pnpm run build
git diff --check
```

Expected: focused Laravel tests pass, frontend tests pass subject to any pre-existing repository failure being reported explicitly, the production build succeeds, and diff check is clean.

- [x] **Step 4: Update the implementation plan and learning log if needed**

Mark completed steps, record any durable repository lesson in `docs/ai-learning-log.md`, and do not document secrets or personal data.

- [ ] **Step 5: Commit the implementation**

After all tests/builds and review findings are resolved:

```bash
git add -- app/Models/User.php app/Services/EmployeeSecurityService.php app/Http/Controllers/EmployeeMfaController.php app/Http/Controllers/UserController.php app/Http/Controllers/UserSide/CustomerProfileController.php routes/web.php resources/js/components/UserProfile/EmployeeTotpSecurity.tsx resources/js/Pages/ERP/EmployeeMfaChallenge.tsx resources/js/Pages/UserSide/Profile/customerProfile.tsx resources/js/ziggy.js tests/Feature/CustomerMfaSecurityTest.php resources/js/Pages/UserSide/Profile/__tests__/customerProfile.security.test.tsx resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx resources/js/__tests__/ziggySecurityRoutes.test.ts
git commit -m "feat: add customer MFA and security activity"
```
