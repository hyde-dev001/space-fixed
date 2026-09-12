# Shop Owner TOTP MFA Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Replace Shop Owner email-delivered login OTP with authenticator-app TOTP, while preserving the existing pending-login boundary, adding secure enrollment/recovery controls, and leaving employee, customer, and Super Admin MFA behavior unchanged.

**Current verification status (2026-09-10):** The Shop Owner TOTP implementation, focused Laravel/frontend tests, route checks, and production build pass. The repository-wide suite still stops on unrelated existing Business Scaling route-contract failures (`ErpRouteCatalogTest` navigation order and `ShopModuleCatalogTest` missing `inventory.items.replenishment-settings.update`); these are retained as explicit handoff issues.

**Architecture:** Add encrypted TOTP state to ShopOwner and reuse EmployeeMfaService for secret generation, provisioning, verification, replay protection, and recovery-code hashing/consumption. Keep ShopOwnerAuthController responsible for the password-to-pending-login handoff and add a focused ShopOwnerMfaController for authenticated security mutations. Reuse EmployeeTotpSecurity and EmployeeMfaChallenge with Shop Owner route overrides; use one small pending-enrollment page only for legacy email-OTP accounts that cannot authenticate until they enroll TOTP.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent transactions and row locks, PragmaRX Google2FA already installed, Inertia 2, React 18, TypeScript 5.7, Vitest, Ziggy, and the existing SoleSpace security components.

---

## Context and constraints

The approved design is docs/superpowers/specs/2026-09-10-shop-owner-totp-design.md. The current branch contains that design checkpoint as commit b1d49c082 and the implementation is currently uncommitted in the target worktree.

The implementation must:

- remove email OTP generation, delivery, and resend from Shop Owner login;
- never authenticate the shop_owner guard before a required TOTP or recovery-code check succeeds;
- preserve the existing registration email-verification OTP routes;
- preserve the legacy two_factor_email_enabled column only as a migration signal for existing accounts;
- never fabricate a TOTP secret from legacy email-OTP data;
- use the existing config(privileged_security.*) TOTP window and recovery-code count;
- keep all TOTP/recovery state hidden and encrypted;
- avoid Shop Owner Active Sessions and avoid changes to employee/customer/Super Admin MFA semantics;
- use DB row locks for stateful TOTP and recovery-code consumption;
- keep the change focused; no new MFA package, WebSocket, queue, or generic account framework.

## File map

Create:

- database/migrations/2026_09_10_000001_add_shop_owner_totp_security.php — nullable encrypted secret, enabled timestamp, encrypted recovery-code array, and replay timestep columns.
- app/Http/Controllers/ShopOwner/ShopOwnerMfaController.php — authenticated Shop Owner setup, verification, recovery-code regeneration, disable, and legacy pending-enrollment completion.
- resources/js/Pages/UserSide/Auth/ShopOwnerTotpEnrollment.tsx — one-time TOTP enrollment UI for accounts migrating from legacy email OTP before login completes.
- tests/Unit/Services/EmployeeMfaServiceTest.php — account-neutral shared MFA primitive coverage.
- tests/Feature/Auth/ShopOwnerTotpSecurityTest.php — authenticated Shop Owner security endpoint coverage.
- resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerTotpEnrollment.test.tsx — pending legacy enrollment UI coverage.

Modify:

- app/Models/ShopOwner.php — hidden/cast TOTP fields and hasTotpEnabled helper.
- app/Services/EmployeeMfaService.php — smallest account-neutral provisioning, TOTP-state, and recovery-hash helpers; keep existing User methods as compatibility wrappers.
- app/Http/Controllers/ShopOwnerAuthController.php — replace email OTP login state with TOTP pending-login state and legacy enrollment handoff.
- routes/web.php — add authenticated Shop Owner TOTP routes, add the pending-enrollment verification route, and remove only the Shop Owner login resend route.
- config/shop_modules.php — catalog the new named Shop Owner security routes and remove the deleted resend entry.
- app/Http/Controllers/ShopOwner/ShopSettingsController.php — expose TOTP enabled state and stop accepting/exposing the email-OTP setting.
- resources/js/components/UserProfile/EmployeeTotpSecurity.tsx — add an opt-out for activity rendering while preserving current employee/customer defaults.
- resources/js/Pages/ShopOwner/Settings/shopSetting.tsx — replace the email-OTP toggle with the shared authenticator security card and route overrides.
- resources/js/Pages/UserSide/Auth/ShopOwnerTwoFactor.tsx — remove the email-code UI by making the old entry point a TOTP challenge compatibility wrapper or remove it only after all references are updated.
- resources/js/__tests__/ziggySecurityRoutes.test.ts — assert Shop Owner TOTP route URLs and the absence of the old resend route.
- resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx — verify Shop Owner-style activity suppression does not affect setup behavior.
- resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx — use the TOTP payload and assert no email-OTP settings UI.
- resources/js/Pages/ShopOwner/Settings/__tests__/ApprovalWorkflowSettings.test.tsx — update the settings fixture payload.
- tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php — replace email-code fixtures with real TOTP/recovery behavior.
- tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php — update the existing Shop Owner two-factor characterization tests.
- tests/Unit/BusinessScaling/ShopModuleCatalogTest.php — remove the deleted resend route and cover the new catalog entries.
- resources/js/ziggy.js — regenerate the committed route manifest after route changes.

### Task 1: Add Shop Owner TOTP storage and shared MFA primitives

**Files:**

- Create: database/migrations/2026_09_10_000001_add_shop_owner_totp_security.php
- Modify: app/Models/ShopOwner.php
- Modify: app/Services/EmployeeMfaService.php
- Test: tests/Unit/Services/EmployeeMfaServiceTest.php

- [ ] **Step 1: Write failing unit tests for account-neutral TOTP operations**

Create focused tests that use a real Google2FA secret and current timestep. Cover:

- provisioningUriForEmail returns a URI containing the configured issuer, the supplied email, and the supplied secret;
- verifyTotpForSecret accepts a current valid six-digit code and rejects malformed input;
- consumeTotpState returns the accepted timestep for a new code, rejects the same timestep again, and rejects a code older than the stored timestep;
- consumeRecoveryCodeFromHashes removes exactly one matching normalized recovery hash and returns false for invalid, missing, or already-consumed codes;
- existing User-oriented EmployeeMfaService methods still behave as before through their wrappers.

Run:

    php artisan test tests/Unit/Services/EmployeeMfaServiceTest.php

Expected result before implementation: FAIL because the new account-neutral methods and ShopOwner storage do not exist.

- [ ] **Step 2: Add the migration and model state**

Create the migration with nullable columns:

- shop_owner_totp_secret as text;
- shop_owner_totp_enabled_at as nullable datetime;
- shop_owner_totp_recovery_codes as text;
- shop_owner_totp_last_used_timestep as nullable unsigned big integer.

Use the existing employee security migration style and provide a reversible down method. Do not alter or drop two_factor_email_enabled.

Update ShopOwner:

- add the four TOTP attributes to hidden;
- cast the secret as encrypted, the enabled timestamp as datetime, recovery codes as encrypted:array, and last-used timestep as integer;
- add hasTotpEnabled(): bool, returning true only when the enabled timestamp is present and the decrypted secret is a non-empty string;
- do not add TOTP attributes to fillable; security state must use forceFill in controlled server-side code.

- [ ] **Step 3: Extend EmployeeMfaService without duplicating cryptography**

Add only the account-neutral primitives needed by ShopOwner:

- provisioningUriForEmail(string email, string secret): string;
- verifyTotpForSecret(string secret, string code): bool;
- consumeTotpState(string secret, ?int oldTimestep, string code, int currentTimestep): int|false;
- consumeRecoveryCodeFromHashes(array storedCodes, string code): array|false.

Keep the existing provisioningUri(User, secret), consumeTotp(User, code, timestep), consumeRecoveryCode(User, code), and consumeSecondFactor(User, code, timestep) methods as thin wrappers around the shared primitives so employee/customer behavior and callers remain stable. Keep normalization, six-digit validation, configured timestep window, and Hash::check logic in this one service.

- [ ] **Step 4: Run the unit tests and migration smoke check**

Run:

    php artisan test tests/Unit/Services/EmployeeMfaServiceTest.php
    php artisan migrate:status

Expected result: PASS, with the new migration listed and no changes to existing User MFA tests.

- [ ] **Step 5: Commit the storage and primitive checkpoint**

    git add -- database/migrations/2026_09_10_000001_add_shop_owner_totp_security.php app/Models/ShopOwner.php app/Services/EmployeeMfaService.php tests/Unit/Services/EmployeeMfaServiceTest.php
    git commit -m "feat: add shop owner totp storage primitives"

### Task 2: Add authenticated Shop Owner TOTP security endpoints

**Files:**

- Create: app/Http/Controllers/ShopOwner/ShopOwnerMfaController.php
- Modify: routes/web.php
- Modify: config/shop_modules.php
- Test: tests/Feature/Auth/ShopOwnerTotpSecurityTest.php
- Test: tests/Unit/BusinessScaling/ShopModuleCatalogTest.php

- [ ] **Step 1: Write failing endpoint tests**

Use ShopOwner factories and the shop_owner guard. Cover:

- unauthenticated setup, verify, recovery regeneration, and disable return 401/redirect according to existing endpoint conventions;
- setup rejects a wrong current password and an already-enabled owner;
- setup with the correct current password returns qr_code, manual_key, and expires_at, stores only an encrypted pending secret in the session, binds it to the owner id, and expires it;
- verify setup rejects a wrong/expired code and, on a real current code, persists the encrypted secret, enabled timestamp, hashed recovery codes, and null last-used timestep;
- serialized ShopOwner output does not expose the secret or recovery hashes;
- recovery regeneration requires current password plus a valid current TOTP, replaces all old hashes, and returns the plaintext codes only in that response;
- disable requires current password plus valid current TOTP and clears all TOTP fields;
- a different authenticated Shop Owner cannot use another owner’s setup session or mutate the other owner’s record.

Add route-contract assertions for:

- shop-owner.security.totp.setup;
- shop-owner.security.totp.verify;
- shop-owner.security.totp.recovery.regenerate;
- shop-owner.security.totp.disable.

Run:

    php artisan test tests/Feature/Auth/ShopOwnerTotpSecurityTest.php tests/Unit/BusinessScaling/ShopModuleCatalogTest.php

Expected result before implementation: FAIL with missing routes/controller/catalog entries.

- [ ] **Step 2: Add authenticated, throttled routes**

Inside the existing auth:shop_owner and shop-owner route group, add a security/totp subgroup with route names shop-owner.security.totp.* and the existing throttle:10,1 convention. Keep the settings page route unchanged.

Do not put these routes behind user/employee middleware. Do not add Active Sessions routes.

- [ ] **Step 3: Implement ShopOwnerMfaController**

Implement four authenticated mutations:

1. setup:
   - resolve the current shop_owner guard account;
   - validate current_password as required string;
   - verify the password server-side;
   - reject if hasTotpEnabled is true;
   - generate a secret with EmployeeMfaService;
   - encrypt the secret in a session payload containing owner id and a ten-minute expiry;
   - return the shared QR data URI, manual key, and expiry.

2. verifySetup:
   - validate code as six digits;
   - read and validate the owner-bound, expiring pending setup session;
   - decrypt the secret and discard invalid ciphertext/session state;
   - inside a transaction lock the ShopOwner row, re-check owner identity and TOTP-disabled state, verify the enrollment code with EmployeeMfaService, generate/hash recovery codes, and persist all four fields;
   - clear the pending setup session and return the plaintext recovery codes once.

3. regenerateRecoveryCodes:
   - validate current_password and a string code;
   - inside a transaction lock the row, re-check TOTP enabled state and password, consume a current TOTP through EmployeeMfaService, generate/hash replacement recovery codes, and return the plaintext list once.

4. disable:
   - validate current_password and a six-digit code;
   - inside a transaction lock the row, re-check TOTP enabled state, password, and current TOTP;
   - clear secret, enabled timestamp, recovery hashes, and last-used timestep with forceFill;
   - return a normal JSON success response.

Use the existing JSON error/status conventions from EmployeeMfaController. Never log secrets, codes, QR data, or recovery hashes. Record security actions with the existing activity/audit mechanism if the Shop Owner audit path supports it, without exposing a new Shop Owner activity UI in this task.

- [ ] **Step 4: Add route catalog entries**

Add the four authenticated security routes to config/shop_modules.php using the exact existing route-entry schema. Use shop_owner audience and actor_guard, core classification, update action, sensitive risk tier, allowed owner access, no module dependency, and the existing self_service/actor_persistence conventions for Shop Owner security mutations.

Remove no unrelated catalog routes.

- [ ] **Step 5: Run endpoint and catalog tests**

Run:

    php artisan test tests/Feature/Auth/ShopOwnerTotpSecurityTest.php tests/Unit/BusinessScaling/ShopModuleCatalogTest.php

Expected result: PASS. Confirm the transaction tests show no partial TOTP state after failed verification.

- [ ] **Step 6: Commit the authenticated security checkpoint**

    git add -- app/Http/Controllers/ShopOwner/ShopOwnerMfaController.php routes/web.php config/shop_modules.php tests/Feature/Auth/ShopOwnerTotpSecurityTest.php tests/Unit/BusinessScaling/ShopModuleCatalogTest.php
    git commit -m "feat: add shop owner totp security endpoints"

### Task 3: Replace Shop Owner email-OTP login with TOTP and legacy enrollment

**Files:**

- Modify: app/Http/Controllers/ShopOwnerAuthController.php
- Modify: routes/web.php
- Modify: config/shop_modules.php
- Modify: tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php
- Modify: tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php
- Modify: app/Http/Controllers/ShopOwner/ShopOwnerMfaController.php

- [ ] **Step 1: Rewrite login tests before changing the controller**

Update the existing Shop Owner two-factor tests to create a real TOTP-enabled owner by force-filling an encrypted secret, enabled timestamp, recovery hashes, and null replay timestep. Cover:

- password verification returns the two-factor challenge redirect/202 response while shop_owner is still unauthenticated;
- the pending session contains only owner id, remember choice, pending timestamp, and attempt state; it contains no otp_hash, email-code expiry entry, or email destination;
- Mail::fake receives no login OTP email;
- the challenge renders ERP/EmployeeMfaChallenge with Shop Owner verify/login route overrides;
- a valid current TOTP logs the owner in and clears pending state;
- a valid recovery code logs the owner in and removes that code;
- the same TOTP timestep cannot be reused;
- invalid code attempts are counted, the pending context expires, and the maximum attempt limit prevents further verification;
- a different owner cannot use the pending session;
- the old resend endpoint is no longer a route and no resend behavior is reachable.

Run:

    php artisan test tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php

Expected result before implementation: FAIL because the tests no longer match the email-OTP controller state.

- [ ] **Step 2: Replace the password-to-second-factor branch**

In ShopOwnerAuthController:

- add explicit TOTP pending-login TTL and attempt constants;
- branch on ShopOwner::hasTotpEnabled();
- for enabled owners, store only owner id, remember flag, and a timestamp in the existing pending-login session namespace;
- for legacy owners with two_factor_email_enabled true and no TOTP secret, store the same pending context but do not send email;
- leave password-only accounts unchanged;
- preserve the approved-account/status checks and return 202 JSON with requires_two_factor without logging in the guard.

Do not use two_factor_email_enabled as a condition to issue email codes. Do not accept a submitted email address, OTP hash, or client-provided owner id.

- [ ] **Step 3: Implement TOTP challenge verification**

Change the existing verification route semantics from email OTP to a code field that accepts either a six-digit TOTP or a normalized recovery code. On every request:

- resolve and validate the owner-bound pending session and ten-minute expiry;
- enforce the existing five-attempt ceiling and clear the pending context when exhausted;
- lock the ShopOwner row inside a transaction;
- re-check approved/active account state and hasTotpEnabled;
- try EmployeeMfaService consumeTotpState with the stored last timestep; if that fails, try consumeRecoveryCodeFromHashes;
- persist the accepted TOTP timestep or updated recovery-code hashes while the row remains locked;
- increment attempts only after a failed transaction result;
- on success clear pending state, regenerate the session, authenticate shop_owner, and update last_login_at/last_login_ip.

The owner, active account state, and current second-factor configuration must be read from the locked database row, not from browser props or session payloads.

- [ ] **Step 4: Add the legacy enrollment boundary**

For an owner whose old two_factor_email_enabled flag is true but who has no TOTP secret:

- redirect the pending challenge to a dedicated pending-enrollment page;
- generate one encrypted, owner-bound, ten-minute pending setup secret;
- return only the QR/manual setup data needed by that page;
- accept only a six-digit enrollment code through the pending session;
- inside a locked transaction verify the enrollment code, persist secret/enabled timestamp/recovery hashes, reset the last-used timestep, and clear the legacy flag as an internal migration completion marker;
- authenticate only after the enrollment code succeeds;
- return the plaintext recovery codes once so the page can display them before redirecting to the dashboard;
- leave the legacy flag untouched when enrollment is abandoned or fails.

This is the only transitional use of two_factor_email_enabled. It must never send an email code. A crafted hired/password/security payload must not affect this flow.

- [ ] **Step 5: Remove only the email login flow**

Delete or make unreachable the Shop Owner login helpers that generate, hash, cache, read, store, resend, or clear email OTP entries. Keep registration email OTP helpers and routes intact. Remove the shop-owner.two-factor.resend route and its catalog entry. Keep shop-owner.two-factor.challenge and shop-owner.two-factor.verify names so existing login entry points remain stable.

- [ ] **Step 6: Run the authentication regression tests**

Run:

    php artisan test tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php tests/Feature/Auth/ShopOwnerTotpSecurityTest.php

Expected result: PASS, including no guard authentication before TOTP/enrollment success and no login email delivery.

- [ ] **Step 7: Commit the login checkpoint**

    git add -- app/Http/Controllers/ShopOwnerAuthController.php app/Http/Controllers/ShopOwner/ShopOwnerMfaController.php routes/web.php config/shop_modules.php tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php
    git commit -m "feat: replace shop owner email otp with totp login"

### Task 4: Replace the Shop Owner settings UI and legacy enrollment UI

**Files:**

- Modify: app/Http/Controllers/ShopOwner/ShopSettingsController.php
- Modify: resources/js/components/UserProfile/EmployeeTotpSecurity.tsx
- Modify: resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx
- Modify: resources/js/Pages/ShopOwner/Settings/shopSetting.tsx
- Modify: resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx
- Modify: resources/js/Pages/ShopOwner/Settings/__tests__/ApprovalWorkflowSettings.test.tsx
- Modify: resources/js/Pages/UserSide/Auth/ShopOwnerTwoFactor.tsx
- Create: resources/js/Pages/UserSide/Auth/ShopOwnerTotpEnrollment.tsx
- Test: resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerTotpEnrollment.test.tsx

- [ ] **Step 1: Add failing frontend assertions**

Update the settings fixtures to use totp_enabled instead of two_factor_email_enabled. Add assertions that:

- Shop Owner settings renders authenticator-app wording and the shared security card;
- the string Email OTP Two-Factor Login and resend controls are absent;
- Shop Owner settings passes Shop Owner security route overrides;
- Shop Owner settings does not render Active Sessions;
- Employee/customer behavior still renders Recent Security Activity by default;
- EmployeeTotpSecurity with showActivity false renders no activity card while setup still works.

Add enrollment-page tests for:

- QR/manual-key display;
- six-digit code submission to the pending-enrollment route;
- one-time recovery-code display after success;
- no email/resend controls;
- redirect/finalize behavior only after the enrollment code succeeds.

Run:

    pnpm exec vitest run resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx resources/js/Pages/ShopOwner/Settings/__tests__/ApprovalWorkflowSettings.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerTotpEnrollment.test.tsx

Expected result before implementation: FAIL because the settings page still owns an email-OTP toggle and the enrollment page does not exist.

- [ ] **Step 2: Change the Shop Settings payload and remove the email toggle**

In ShopSettingsController:

- return shop_settings.totp_enabled from ShopOwner::hasTotpEnabled();
- remove two_factor_email_enabled from the Inertia payload;
- remove two_factor_email_enabled from update validation and shopOwner updates;
- leave unrelated settings validation and persistence unchanged.

In shopSetting.tsx:

- remove email-OTP state, toggle handler, success/error state, effect, and markup;
- render EmployeeTotpSecurity with enabled from totp_enabled, showSessions false, showActivity false, and Shop Owner setup/verify/recovery/disable route overrides;
- replace the account-feature label with authenticator-app TOTP state;
- keep the existing settings layout and neutral SoleSpace visual system.

Do not add a Shop Owner activity or sessions endpoint as part of this task.

- [ ] **Step 3: Add the legacy pending-enrollment page**

Create ShopOwnerTotpEnrollment.tsx using the existing neutral auth-card patterns and the existing QR/manual-key language. It must:

- receive only the setup response and route props from the server;
- submit the six-digit code through the existing frontend HTTP pattern;
- show recovery codes exactly once from the successful response;
- provide a final navigation action after successful enrollment;
- display server validation errors;
- contain no email-code, resend, Active Sessions, or arbitrary destination controls.

Replace the old ShopOwnerTwoFactor email-code UI with a TOTP challenge wrapper or update all references and remove it. Keep AppShellLoader and route entry-point contracts passing.

- [ ] **Step 4: Add the activity-rendering opt-out without changing defaults**

Add showActivity = true to EmployeeTotpSecurity props and wrap only the Recent Security Activity card. Keep the default true so employee and customer profiles retain their current behavior. Shop Owner passes false; showSessions remains false.

- [ ] **Step 5: Run focused frontend tests**

Run:

    pnpm exec vitest run resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx resources/js/Pages/ShopOwner/Settings/__tests__/ApprovalWorkflowSettings.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerTotpEnrollment.test.tsx

Expected result: PASS with no email-OTP text/control in the Shop Owner settings or pending login surfaces.

- [ ] **Step 6: Commit the UI checkpoint**

    git add -- app/Http/Controllers/ShopOwner/ShopSettingsController.php resources/js/components/UserProfile/EmployeeTotpSecurity.tsx resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx resources/js/Pages/ShopOwner/Settings/shopSetting.tsx resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx resources/js/Pages/ShopOwner/Settings/__tests__/ApprovalWorkflowSettings.test.tsx resources/js/Pages/UserSide/Auth/ShopOwnerTwoFactor.tsx resources/js/Pages/UserSide/Auth/ShopOwnerTotpEnrollment.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerTotpEnrollment.test.tsx
    git commit -m "feat: add shop owner authenticator security UI"

### Task 5: Regenerate route contracts and complete route-level regression coverage

**Files:**

- Modify: resources/js/__tests__/ziggySecurityRoutes.test.ts
- Modify: resources/js/ziggy.js
- Modify: tests/Unit/BusinessScaling/ShopModuleCatalogTest.php
- Modify: routes/web.php
- Modify: config/shop_modules.php

- [ ] **Step 1: Add route manifest assertions**

Assert that Ziggy contains:

- shop-owner.security.totp.setup at shop-owner/security/totp/setup;
- shop-owner.security.totp.verify at shop-owner/security/totp/verify;
- shop-owner.security.totp.recovery.regenerate at shop-owner/security/totp/recovery-codes/regenerate;
- shop-owner.security.totp.disable at shop-owner/security/totp/disable;
- shop-owner.two-factor.challenge and shop-owner.two-factor.verify.

Assert that shop-owner.two-factor.resend is absent. Keep customer session routes absent and keep all employee/customer TOTP route assertions unchanged.

- [ ] **Step 2: Regenerate the committed Ziggy manifest**

Run:

    php artisan ziggy:generate resources/js/ziggy.js

Inspect the diff to confirm only intended route additions/removals are present. Do not hand-edit the generated one-line manifest.

- [ ] **Step 3: Run route contract tests**

Run:

    php artisan test tests/Unit/BusinessScaling/ShopModuleCatalogTest.php
    pnpm exec vitest run resources/js/__tests__/ziggySecurityRoutes.test.ts

Expected result: PASS, with no missing catalog entry and no stale resend route.

- [ ] **Step 4: Commit the route-contract checkpoint**

    git add -- resources/js/__tests__/ziggySecurityRoutes.test.ts resources/js/ziggy.js tests/Unit/BusinessScaling/ShopModuleCatalogTest.php routes/web.php config/shop_modules.php
    git commit -m "test: update shop owner totp route contracts"

### Task 6: Review, simplify, and verify the complete migration

**Files:** changed files from Tasks 1–5; no new feature files.

- [ ] **Step 1: Run the focused Laravel suite**

    php artisan test tests/Unit/Services/EmployeeMfaServiceTest.php tests/Feature/Auth/ShopOwnerTotpSecurityTest.php tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Feature/ShopOwner/PhaseOneStateCharacterizationTest.php tests/Unit/BusinessScaling/ShopModuleCatalogTest.php

Expected result: PASS.

- [ ] **Step 2: Run the focused frontend suite**

    pnpm exec vitest run resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx resources/js/Pages/ShopOwner/Settings/__tests__/ApprovalWorkflowSettings.test.tsx resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerTotpEnrollment.test.tsx resources/js/__tests__/ziggySecurityRoutes.test.ts

Expected result: PASS. If the repository-wide frontend suite still fails because of the known missing @testing-library/dom dependency, record that exact unrelated failure instead of claiming the full suite passed.

- [ ] **Step 3: Run the required simplification and risk reviews**

Apply @ponytail to the diff:

- remove duplicated crypto or unused email-OTP helpers;
- confirm no new abstraction has only one caller unless it protects a trust boundary;
- keep explicit validation, row locks, expiry, replay checks, and authorization.

Apply @laravel-best-practices and @security-review:

- verify all Shop Owner mutations use auth:shop_owner and throttle middleware;
- verify tenant/account binding is derived server-side;
- verify encrypted attributes are not serialized;
- verify no secret/code/recovery hash is logged;
- verify TOTP and recovery-code writes happen under lock;
- verify no guard login occurs before second-factor success.

Apply @vercel-react-best-practices:

- confirm the shared component remains focused;
- confirm Shop Owner route overrides are stable and no employee endpoint is accidentally used;
- confirm the pending enrollment component does not introduce polling, duplicate requests, or session actions.

- [ ] **Step 4: Scan for stale email-OTP references**

Run:

    rg -n "issueLoginTwoFactorOtp|resendLoginTwoFactorOtp|otp_hash|shop_owner_2fa_entry|two_factor_email_enabled|ShopOwnerTwoFactor|two-factor/resend" app routes config resources/js tests

Expected result: registration email-OTP references may remain; active Shop Owner login/settings code must not generate/send/resend email login codes. The legacy flag may remain only for migration detection/one-time completion and compatibility fixtures.

- [ ] **Step 5: Run quality gates**

    git diff --check
    pnpm run build
    composer test

The build must regenerate public/build. Inspect the generated diff and include it with the implementation changes. If composer test or the full frontend suite fails for an unrelated pre-existing environment issue, report the exact command and failure while retaining the focused passing evidence.

- [ ] **Step 6: Perform the browser-visible smoke check**

Using the existing local app:

- enable TOTP from Shop Owner Settings and confirm QR/manual key, verification, recovery codes, and disabled Active Sessions/activity surfaces;
- log out and confirm password submission reaches a TOTP challenge without guard authentication;
- verify with a current authenticator code and confirm dashboard access;
- use a recovery code once and confirm a second use fails;
- confirm a legacy-flagged owner sees enrollment, not an email-code/resend page;
- confirm employee and customer profiles still use their existing TOTP/security behavior.

- [ ] **Step 7: Record final evidence and stop**

Report changed files, focused test results, build result, diff hygiene, any known full-suite environment failures, and whether any legacy email-OTP accounts remain to be migrated by their next login. Do not claim the branch is ready to merge until fresh verification evidence exists.

## Review checkpoints

After each task, inspect the diff and run the task’s focused command before continuing. Do not run subagents or parallel writers unless the user explicitly approves parallel review; perform standards, spec, simplification, TypeScript, security, and verification reviews sequentially in this worktree.

Implementation must use @superpowers:executing-plans after approval, with @superpowers:test-driven-development for each behavior change and @superpowers:verification-before-completion before any completion/merge claim.
