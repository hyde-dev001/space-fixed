# Super Admin Phase 1 Privileged Identity and MFA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task in the existing `super-admin-phase-0-containment` worktree. Apply superpowers:test-driven-development before implementation changes, laravel-best-practices and security-review for backend work, and verification-before-completion before every completion claim. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Require every Admin and Super Admin to pass active-account and MFA checks, provide safe bootstrap/invitation/password/recovery flows, support targeted privileged-session invalidation, and prevent removal of the final active MFA-enrolled Super Admin.

**Architecture:** Keep the existing `super_admin` guard and Phase 0 fixed-capability/audit foundations. Add explicit password-verified, setup, MFA-challenge, and complete session stages; encrypted TOTP secrets; hashed single-use recovery codes and setup/reset tokens; and a small privileged-session registry keyed by Laravel session ID so security-version enforcement is immediate and database-backed sessions can be removed selectively. Focused controllers orchestrate setup and self-service security, while small concrete services own MFA, token, session, and administrator-lifecycle invariants.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, database sessions in production, Inertia 2, React 18, TypeScript 5.7, PHPUnit 11, Vitest 3, `pragmarx/google2fa` 9.x, `bacon/bacon-qr-code` 3.1.x, pnpm.

---

## Design Authority and Scope Guard

Authoritative design:

- `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`
- Phase 1, "Privileged Identity and MFA"
- Sections 8, 9, 13–16, 19–21, and 24 where they define capabilities, identity stages, session rules, audit, routes, invariants, and verification

Implemented prerequisite:

- Phase 0 tip: `83fbf7f45`
- Worktree/branch: `.worktrees/super-admin-phase-0-containment` / `super-admin-phase-0-containment`

This plan must not be executed against `fix/logistics-hardening-follow-up` before the Phase 0 branch is integrated. The file-level instructions are based on the post-Phase-0 worktree.

Phase 1 includes:

1. the complete fixed capability map for the approved two-role model;
2. active-account, setup-stage, MFA-complete, and recent-reauthentication enforcement;
3. one-time first-account bootstrap and administrator invitations;
4. TOTP enrollment/challenge, single-use recovery codes, and MFA reset;
5. privileged forgot/reset/change-password behavior;
6. targeted privileged-session tracking and invalidation;
7. transactional final-Super-Admin protection;
8. the minimum UI needed to operate these real security flows;
9. deployment and rollback guidance for the forced-MFA rollout.

Do not add in this phase:

- configurable RBAC, Spatie permission management, SSO, WebAuthn/passkeys, SMS/email OTP as MFA, trusted-device bypass, or a general IAM framework;
- a generic token platform, event framework, repository layer, command bus, or an interface with one implementation;
- administrator archival/deletion;
- shop/user archival, appeal/suspension redesign, billing consolidation, audit-history UI/backfill, or business-document renewal;
- client-controlled audit correlation IDs;
- a console MFA-reset bypass for a lost authenticator/recovery-code scenario;
- broad route/controller cleanup reserved for Phase 7.

## Confirmed Post-Phase-0 Baseline

- `SuperAdmin` uses the `super_admin` guard and deterministic capability constants, but only the Phase 0 subset exists.
- `super_admins.status` supports `active`, `suspended`, and `inactive`; there is no `pending_setup` state or MFA/security-version data.
- `SuperAdminAuthController` authenticates password-only, does not enforce status, is not rate-limited, and does not update last-login fields.
- `SuperAdminAuth` checks only guard authentication.
- `/admin` uses `super_admin.auth`, while legacy `/superAdmin` groups use `auth:super_admin`; neither checks active status or MFA.
- administrator creation accepts a Super Admin-chosen password and creates the target as active.
- administrator suspend/activate writes the legacy `AuditLog` directly and has no self-management or final-Super-Admin protection.
- production and `.env.example` use database sessions, but Laravel's shared `sessions.user_id` does not reliably identify the `super_admin` guard.
- Phase 0 provides `PrivilegedAudit` with server-generated request correlation and a console operation UUID.
- there is no TOTP or QR dependency in `composer.lock`.
- PHP 8.2 has `iconv`, `openssl`, and `xmlwriter`, which support the selected encrypted casts and SVG QR renderer.
- nine existing backend feature-test files authenticate the `super_admin` guard directly and will need a shared completed-session helper once MFA middleware becomes mandatory.

## Frozen Phase 1 Security Contracts

### Account and setup state

```text
fresh bootstrap/invitation
        ↓
status = pending_setup
+ unknown random password hash
+ no confirmed MFA
        ↓ single-use setup link
strong password chosen
+ TOTP secret enrolled and verified
+ recovery codes shown once and acknowledged
        ↓
status = active
+ mfa_confirmed_at set
```

Existing active administrators keep their current password but have no confirmed MFA after migration. Their next valid password login enters setup stage; no operational route is available until enrollment and recovery-code acknowledgement complete.

MFA setup-required is derived from missing `mfa_confirmed_at`, missing encrypted secret, or missing recovery-code hashes. An MFA reset does not rewrite business status; it clears the MFA condition, increments `security_version`, and invalidates privileged sessions.

### Session stages

Use server-owned session keys only:

```text
password verified
→ privileged_auth_stage = setup | mfa_challenge

MFA/recovery challenge succeeds
→ privileged_auth_stage = complete
→ privileged_security_version = current DB value
→ privileged session registry row created
```

Operational authorization requires all of:

```text
super_admin guard authenticated
+ fresh database row exists
+ status = active
+ MFA setup complete
+ session stage = complete
+ session security_version = database security_version
+ current Laravel session ID belongs to that administrator in privileged_sessions
```

Any mismatch logs the guard out, invalidates the local session, and returns a generic redirect/401. Status/security-version checks, not physical session deletion, are the immediate authority boundary.

### Targeted session invalidation

`privileged_sessions` maps `super_admin_id` to the real Laravel session ID after complete authentication. Security changes increment `super_admins.security_version` transactionally. After commit, `PrivilegedSessionService` deletes mapped physical database sessions when the database session driver is configured and always deletes stale registry rows.

Rules:

- password reset and MFA reset invalidate all privileged sessions;
- password change and recovery-code regeneration invalidate other sessions after recent reauthentication, then re-register the current session at the new security version;
- suspension, deactivation, or role/security changes remove authority on the next request even when physical cleanup fails;
- never use `sessions.user_id` for privileged-session targeting;
- session cleanup failure is reported and audited but cannot restore old authority.

### TOTP and recovery

- Generate a 32-character Base32 secret with `pragmarx/google2fa`.
- Encrypt the secret through an Eloquent `encrypted` cast and hide it from serialization.
- Generate an `otpauth://` URI server-side and render it to a base64 SVG with BaconQrCode; never call an external QR service.
- Accept six numeric digits with a ±1 30-second step window.
- Use `verifyKeyNewer()` and persist `mfa_last_used_timestep` under row lock so one TOTP timestep cannot be accepted twice.
- Generate eight high-entropy recovery codes. Store only individual `Hash::make()` values in a hidden JSON array.
- Consume one recovery code atomically under row lock; remove only the matching hash.
- Recovery codes may complete login recovery, but may not satisfy routine recent reauthentication.
- Never place TOTP secrets, codes, recovery plaintext, setup/reset tokens, QR provisioning URIs, or passwords in logs/audit metadata.

### Token and recovery-code display

Setup and password-reset URLs contain random 32-byte URL-safe tokens. Store only `hash('sha256', $rawToken)` with purpose, expiry, use time, subject, and creator. Lock the token row during consumption and mark it used in the same transaction as the protected mutation.

Plaintext recovery codes are returned only in the immediate Inertia response that generated them. They are never stored in the session. The browser receives a separate random acknowledgement token whose SHA-256 hash is stored through `PrivilegedSecurityTokenService` with purpose `recovery_ack` and a 15-minute expiry. Consuming that token acknowledges the current code set. If the page is lost before acknowledgement, restart enrollment/regeneration, replace the complete code set, and invalidate the prior acknowledgement token.

### Recent reauthentication

The centralized window is 15 minutes. Reauthentication requires the current password and a TOTP timestep newer than the last accepted step. Store `privileged_reauthenticated_at` and the matching `security_version` in the session. Recovery codes cannot satisfy it.

Phase 1 applies this middleware to:

- invitation/resend and administrator status, role, and MFA-reset mutations;
- the current administrator's password, MFA, and recovery-code mutations.

Later phases apply the same middleware to archive/restore and provider-backed financial interventions when those canonical workflows are implemented.

### Final active Super Admin

After any active MFA-enrolled Super Admin exists, every transaction that could remove one locks all `super_admins` rows in ascending ID order and rejects the result if no other row remains with:

```text
role = super_admin
status = active
mfa_confirmed_at IS NOT NULL
```

This covers suspension, deactivation, role demotion, and MFA reset. Management endpoints reject self-targeting. Own-security endpoints also reject an MFA reset that would remove the final active enrolled Super Admin.

## Acceptance Criteria

Phase 1 is complete only when:

1. both roles must be active and MFA-complete for every privileged operational route, including legacy `/superAdmin` and admin-notification API routes;
2. password-only, setup-stage, suspended, inactive, stale-security-version, and unregistered sessions cannot access operations;
3. login, setup, MFA, recovery, reset, and reauthentication attempts are centrally rate-limited and return generic failures;
4. existing active administrators are routed to enrollment after valid password verification and cannot bypass it;
5. fresh installation creates no administrator until the one-time interactive bootstrap command runs;
6. bootstrap and invitations create `pending_setup` accounts with unknown random hashes and expiring single-use setup links, never operator-selected passwords;
7. setup tokens and reset tokens are stored only as SHA-256 hashes and cannot be reused or used after expiry;
8. TOTP secrets are encrypted at rest, accepted codes cannot be replayed, and recovery codes are hashed and individually single-use;
9. recovery-code plaintext appears once and never enters session storage, audit, logs, or later props;
10. password reset/MFA reset invalidate all privileged sessions; password change/recovery regeneration invalidate other sessions while preserving a recently reauthenticated current session;
11. database status/security changes deny the next request even when physical session cleanup is delayed or forced to fail;
12. Admin and Super Admin retain the approved fixed capability boundary, including own-security versus platform-security administration;
13. no actor can manage their own account through administrator-management endpoints;
14. concurrent administrator changes cannot eliminate the final active MFA-enrolled Super Admin;
15. every committed identity/security mutation and its mandatory success audit are atomic; delivery occurs after commit;
16. no sensitive value is present in audit properties, exception messages, console output, mail logs, or serialized models;
17. the Phase 0 focused suite remains green through a shared MFA-complete test helper;
18. the forced-MFA deployment and rollback runbook preserves a recoverable path without restoring known credentials or disabling the new middleware.

## File Map

### Dependencies and configuration

- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `config/privileged_security.php`

### Schema and models

- Create: `database/migrations/2026_08_12_010001_expand_super_admin_identity_security.php`
- Create: `database/migrations/2026_08_12_010002_create_privileged_security_tokens_table.php`
- Create: `database/migrations/2026_08_12_010003_create_privileged_sessions_table.php`
- Modify: `app/Models/SuperAdmin.php`
- Create: `app/Models/PrivilegedSecurityToken.php`
- Create: `app/Models/PrivilegedSession.php`
- Modify: `database/factories/SuperAdminFactory.php`

### Security services and middleware

- Create: `app/Services/PrivilegedMfaService.php`
- Create: `app/Services/PrivilegedSecurityTokenService.php`
- Create: `app/Services/PrivilegedSessionService.php`
- Create: `app/Services/AdministratorIdentityService.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Create: `app/Http/Middleware/EnsurePrivilegedAccountIsActive.php`
- Create: `app/Http/Middleware/EnsurePrivilegedMfaComplete.php`
- Create: `app/Http/Middleware/EnsureRecentPrivilegedReauthentication.php`
- Create: `app/Http/Middleware/NoStorePrivilegedSecurityResponse.php`
- Modify: `app/Http/Middleware/SuperAdminAuth.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Providers/AppServiceProvider.php`

### Controllers, requests, command, mail

- Modify: `app/Http/Controllers/SuperAdminAuthController.php`
- Create: `app/Http/Controllers/PrivilegedSetupController.php`
- Create: `app/Http/Controllers/PrivilegedMfaController.php`
- Create: `app/Http/Controllers/PrivilegedPasswordResetController.php`
- Create: `app/Http/Controllers/PrivilegedSecurityController.php`
- Create: `app/Http/Controllers/PrivilegedReauthenticationController.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Create: `app/Http/Requests/Privileged/CompleteSetupPasswordRequest.php`
- Create: `app/Http/Requests/Privileged/VerifyMfaCodeRequest.php`
- Create: `app/Http/Requests/Privileged/ResetPrivilegedPasswordRequest.php`
- Create: `app/Http/Requests/Privileged/ChangePrivilegedPasswordRequest.php`
- Create: `app/Http/Requests/Privileged/ReauthenticatePrivilegedRequest.php`
- Create: `app/Http/Requests/Privileged/InviteAdministratorRequest.php`
- Create: `app/Console/Commands/BootstrapFirstSuperAdmin.php`
- Create: `app/Mail/PrivilegedSetupLinkMail.php`
- Create: `app/Mail/PrivilegedPasswordResetMail.php`
- Create: `resources/views/mail/privileged/setup-link.blade.php`
- Create: `resources/views/mail/privileged/password-reset.blade.php`
- Modify: `routes/web.php`

### Frontend

- Modify: `resources/js/Pages/superAdmin/Auth/SuperAdminLogin.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedSetup.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedMfaEnrollment.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedMfaChallenge.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedRecoveryCodes.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedForgotPassword.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedResetPassword.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedReauthenticate.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedAuthShell.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/RecoveryCodesPanel.tsx`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/CreateAdmin.tsx`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Settings/Profile.tsx`
- Create: `resources/js/Pages/superAdmin/Settings/Security.tsx`

### Tests and operations

- Create: `tests/Concerns/AuthenticatesPrivilegedUsers.php`
- Create: `tests/Unit/Security/MfaDependencyContractTest.php`
- Extend: `tests/Unit/Models/SuperAdminCapabilityTest.php`
- Create: `tests/Unit/Services/PrivilegedMfaServiceTest.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php`
- Create: `tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedAuthenticationFlowTest.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedRecentReauthenticationTest.php`
- Create: `tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php`
- Create: `resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx`
- Create: `resources/js/Pages/superAdmin/Settings/__tests__/Security.test.tsx`
- Extend: `resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx`
- Modify: `tests/Feature/AdminPremiumPlanManagementTest.php`
- Modify: `tests/Feature/BusinessScaling/BusinessScalingActorBoundaryRegressionTest.php`
- Modify: `tests/Feature/BusinessScaling/BusinessScalingNotificationTest.php`
- Modify: `tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php`
- Modify: `tests/Feature/Reports/ShopAndCustomerReportFlowTest.php`
- Modify: `tests/Feature/SuperAdmin/HardDeleteContainmentTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`
- Modify: `tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php`
- Create: `docs/runbooks/super-admin-phase-1-identity-mfa.md`

## Task 1: Lock Dependencies and Record the Phase 0 Baseline

**Files:**

- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `tests/Unit/Security/MfaDependencyContractTest.php`

- [ ] **Step 1: Run and record the post-Phase-0 baseline**

```powershell
php artisan test tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php tests/Feature/SuperAdmin/KnownCredentialContainmentTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php tests/Feature/SuperAdmin/HardDeleteContainmentTest.php
pnpm run test:frontend -- resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
```

Expected: PASS. If not, document failures separately and do not attribute them to Phase 1.

- [ ] **Step 2: Write the failing dependency contract test**

Call the package APIs directly and assert Google2FA can produce a 32-character secret and BaconQrCode's `SvgImageBackEnd` can render a non-empty SVG from an `otpauth://` value without external network access. This test validates the dependency/platform contract only; it does not introduce an application wrapper.

- [ ] **Step 3: Verify RED before dependency installation**

```powershell
php artisan test tests/Unit/Security/MfaDependencyContractTest.php
```

Expected: FAIL because the TOTP/QR package classes do not exist.

- [ ] **Step 4: Install only the two reviewed direct dependencies**

```powershell
composer require "pragmarx/google2fa:^9.0" "bacon/bacon-qr-code:^3.1"
composer show --direct
composer audit
```

Expected: both packages are direct requirements; audit reports no known advisory. Do not install Fortify, a Laravel Google2FA bridge, or a client QR package.

- [ ] **Step 5: Rerun the contract test and verify GREEN**

```powershell
php artisan test tests/Unit/Security/MfaDependencyContractTest.php
```

Expected: PASS with no network request.

- [ ] **Step 6: Commit the green dependency contract**

```powershell
git add -- composer.json composer.lock tests/Unit/Security/MfaDependencyContractTest.php
git commit -m "build: add privileged TOTP dependencies"
```

## Task 2: Add Privileged Identity, Token, and Session Schema

**Files:**

- Create: `database/migrations/2026_08_12_010001_expand_super_admin_identity_security.php`
- Create: `database/migrations/2026_08_12_010002_create_privileged_security_tokens_table.php`
- Create: `database/migrations/2026_08_12_010003_create_privileged_sessions_table.php`
- Modify: `app/Models/SuperAdmin.php`
- Create: `app/Models/PrivilegedSecurityToken.php`
- Create: `app/Models/PrivilegedSession.php`
- Modify: `database/factories/SuperAdminFactory.php`
- Create: `config/privileged_security.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php`

- [ ] **Step 1: Generate the three migrations with Artisan**

```powershell
php artisan make:migration expand_super_admin_identity_security
php artisan make:migration create_privileged_security_tokens_table
php artisan make:migration create_privileged_sessions_table
```

Use the generated files; if timestamps differ from the file map, update the plan checklist before the task commit.

- [ ] **Step 2: Write failing schema, cast, hidden-field, and factory tests**

Assert:

- `status` can store `pending_setup` as well as the existing three values;
- `mfa_secret`, `mfa_recovery_codes`, `mfa_confirmed_at`, `mfa_last_used_timestep`, `security_version`, `password_changed_at`, and nullable unique `bootstrap_marker` exist;
- raw database `mfa_secret` differs from the model value;
- password, remember token, TOTP secret, recovery hashes, and bootstrap marker never serialize;
- `security_version` defaults to `1` in schema and model attributes;
- token rows contain only SHA-256 token hashes, purpose, expiry/use times, subject, and optional creator;
- session registry rows use the actual session ID as primary key and index `super_admin_id`;
- factory states exist for `pendingSetup()`, `activeWithoutMfa()`, `mfaEnrolled()`, `suspended()`, and `inactive()`;
- the default factory is active and MFA-enrolled so ordinary model tests do not create structurally invalid privileged actors.

- [ ] **Step 3: Run schema tests and verify RED**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php
```

Expected: FAIL because fields/tables/casts/states do not exist.

- [ ] **Step 4: Implement the additive security schema**

Target shape:

```php
// super_admins additions
$table->text('mfa_secret')->nullable();
$table->json('mfa_recovery_codes')->nullable();
$table->timestamp('mfa_confirmed_at')->nullable()->index();
$table->unsignedBigInteger('mfa_last_used_timestep')->nullable();
$table->unsignedBigInteger('security_version')->default(1);
$table->timestamp('password_changed_at')->nullable();
$table->string('bootstrap_marker', 32)->nullable()->unique();
```

On MySQL, convert the deployed `status` enum to `VARCHAR(32)` before `pending_setup` is written. On SQLite, first inspect the generated table SQL; do not run unsupported `ALTER COLUMN` syntax when the existing Laravel `enum` is already represented as text. The `down()` method must refuse to restore the old enum while any `pending_setup` row exists.

Token table shape:

```php
$table->id();
$table->foreignId('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
$table->foreignId('created_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
$table->string('purpose', 32);
$table->char('token_hash', 64)->unique();
$table->timestamp('expires_at')->index();
$table->timestamp('used_at')->nullable();
$table->timestamps();
$table->index(['super_admin_id', 'purpose', 'used_at']);
```

Session registry shape:

```php
$table->string('session_id')->primary();
$table->foreignId('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
$table->unsignedBigInteger('security_version');
$table->timestamp('authenticated_at');
$table->timestamp('last_seen_at')->nullable();
$table->index(['super_admin_id', 'security_version']);
```

- [ ] **Step 5: Add model constants, casts, relations, hidden fields, and central config**

`config/privileged_security.php` fixes:

```php
return [
    'issuer' => config('app.name'),
    'setup_token_minutes' => 1440,
    'reset_token_minutes' => 60,
    'recovery_ack_minutes' => 15,
    'recent_reauthentication_minutes' => 15,
    'totp_window' => 1,
    'recovery_code_count' => 8,
];
```

Use model constants for status, role, and token purposes. Do not accept MFA, security-version, bootstrap-marker, or token-hash fields from HTTP validated arrays.

- [ ] **Step 6: Run migration/model tests and inspect reversibility**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php
php artisan migrate:status
```

Expected: the in-memory SQLite feature test passes and the configured development database is not mutated. Review each `down()` path directly. Database-specific MySQL migration/rollback rehearsal belongs to the Phase 1 staging preflight against a disposable backup-restored database, never an unconfirmed local database.

- [ ] **Step 7: Commit**

```powershell
git add -- database/migrations/2026_08_12_010001_expand_super_admin_identity_security.php database/migrations/2026_08_12_010002_create_privileged_security_tokens_table.php database/migrations/2026_08_12_010003_create_privileged_sessions_table.php app/Models/SuperAdmin.php app/Models/PrivilegedSecurityToken.php app/Models/PrivilegedSession.php database/factories/SuperAdminFactory.php config/privileged_security.php tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php
git commit -m "security: add privileged identity schema"
```

## Task 3: Implement MFA, Token, and Privileged-Session Primitives

**Files:**

- Create: `app/Services/PrivilegedMfaService.php`
- Create: `app/Services/PrivilegedSecurityTokenService.php`
- Create: `app/Services/PrivilegedSessionService.php`
- Create: `tests/Unit/Services/PrivilegedMfaServiceTest.php`
- Extend: `tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php`

- [ ] **Step 1: Expand failing MFA primitive tests**

Cover:

- 32-character Base32 secret generation;
- internal `otpauth://` URI with configured issuer and administrator email;
- base64 SVG QR output with no HTTP request;
- deterministic verification using `Carbon::setTestNow()` and an explicit 30-second timestep;
- ±1 window acceptance;
- `verifyKeyNewer()` returns the accepted newer timestep and rejects replay;
- eight recovery codes have sufficient entropy and normalized display grouping;
- stored values are password hashes and each code is consumed once;
- code-set regeneration invalidates every previous hash.

- [ ] **Step 2: Write failing token-service tests**

Prove:

- issue returns raw token once but stores only SHA-256;
- issuing a replacement invalidates prior unused tokens for that administrator and purpose;
- expired, used, wrong-purpose, and wrong-subject tokens fail generically;
- consume locks and marks the token used atomically;
- raw tokens never appear in model serialization or activity properties.

- [ ] **Step 3: Write failing session-service tests**

Prove:

- establish records the current Laravel session ID and security version;
- validate rejects wrong administrator, wrong version, and missing registry rows;
- invalidate-all increments authority separately from physical cleanup and removes all mapped rows;
- invalidate-others preserves exactly the current registry/session;
- with database sessions, only mapped privileged session IDs are deleted and ordinary user sessions remain;
- with a non-database test store or forced physical-cleanup exception, a security-version mismatch still denies the next request;
- `sessions.user_id` is never queried.

- [ ] **Step 4: Run the three focused test classes and verify RED**

```powershell
php artisan test tests/Unit/Services/PrivilegedMfaServiceTest.php tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php
```

- [ ] **Step 5: Implement the three concrete services**

Keep APIs narrow:

```php
PrivilegedMfaService
  generateSecret(): string
  provisioningUri(SuperAdmin $admin): string
  qrDataUri(string $uri): string
  consumeTotp(SuperAdmin $lockedAdmin, string $code, int $currentTimestep): int
  generateRecoveryCodes(): array
  hashRecoveryCodes(array $codes): array
  consumeRecoveryCode(SuperAdmin $lockedAdmin, string $code): bool

PrivilegedSecurityTokenService
  issue(SuperAdmin $admin, string $purpose, ?SuperAdmin $creator): array
  consume(string $rawToken, string $purpose, Closure $mutation): mixed

PrivilegedSessionService
  establish(Request $request, SuperAdmin $admin): void
  validate(Request $request, SuperAdmin $admin): bool
  invalidateAllAfterCommit(SuperAdmin $admin): void
  invalidateOthersAfterCommit(Request $request, SuperAdmin $admin): void
  forgetCurrent(Request $request): void
```

Use constructor injection. Do not add interfaces. TOTP/recovery consumption methods require an already locked administrator row and save within the caller's transaction so the accepted step/code removal and mandatory audit commit together. Physical session deletion is after commit.

- [ ] **Step 6: Run focused tests and dependency audit**

```powershell
php artisan test tests/Unit/Services/PrivilegedMfaServiceTest.php tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php
composer audit
```

Expected: PASS.

- [ ] **Step 7: Commit**

```powershell
git add -- app/Services/PrivilegedMfaService.php app/Services/PrivilegedSecurityTokenService.php app/Services/PrivilegedSessionService.php tests/Unit/Services/PrivilegedMfaServiceTest.php tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php
git commit -m "security: add privileged MFA primitives"
```

## Task 4: Complete Capabilities and Enforce Operational Session Boundaries

**Files:**

- Modify: `app/Models/SuperAdmin.php`
- Create: `app/Http/Middleware/EnsurePrivilegedAccountIsActive.php`
- Create: `app/Http/Middleware/EnsurePrivilegedMfaComplete.php`
- Create: `app/Http/Middleware/EnsureRecentPrivilegedReauthentication.php`
- Create: `app/Http/Middleware/NoStorePrivilegedSecurityResponse.php`
- Modify: `app/Http/Middleware/SuperAdminAuth.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Extend: `tests/Unit/Models/SuperAdminCapabilityTest.php`
- Create: `tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php`
- Create: `tests/Concerns/AuthenticatesPrivilegedUsers.php`
- Modify: the nine existing feature-test files listed in the File Map that directly authenticate the `super_admin` guard

- [ ] **Step 1: Write the complete fixed capability matrix test**

Add deterministic constants/mappings for:

| Capability | Admin | Super Admin |
|---|---:|---:|
| `view_monitoring` | Yes | Yes |
| `review_registrations` | Yes | Yes |
| `intervene_accounts` | Yes | Yes |
| `moderate_reports` | Yes | Yes |
| `view_appeals` | Yes | Yes |
| `resolve_appeals` | No | Yes |
| `manage_administrators` | No | Yes |
| `manage_plans` | No | Yes |
| `intervene_subscriptions` | No | Yes |
| `view_privileged_audit` | Yes | Yes |
| `manage_own_security` | Yes | Yes |
| `manage_platform_security` | No | Yes |

Do not introduce permission rows or a UI editor. Audit-history object scoping remains Phase 3/8 work.

- [ ] **Step 2: Write failing middleware behavior tests**

Cover HTML and JSON behavior for:

- unauthenticated;
- pending setup;
- active without MFA;
- suspended/inactive with a previously valid cookie;
- complete session with current security version and registry row;
- complete session with stale DB version;
- complete session missing its registry row;
- recent reauthentication inside/outside the 15-minute window and after a version change;
- no-store headers on setup, challenge, reset, reauth, and recovery-code pages.

An active account without MFA but without a password-verified setup stage is sent back to login, not directly to enrollment. Only a valid password login or consumed setup link may establish setup stage.

- [ ] **Step 3: Write failing route inventory tests**

Iterate the route collection and require every operational route under:

```text
/admin/*
/superAdmin/*
/api/admin/notifications/*
```

to declare authentication, active-account, and MFA-complete middleware, except an explicit allowlist of login/setup/MFA/reset/reauth routes with their stage-specific middleware. Also assert monitoring/report/flag/appeal/security routes carry their fixed capability.

- [ ] **Step 4: Run tests and verify RED**

```powershell
php artisan test tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php
```

- [ ] **Step 5: Implement aliases and route-group ordering**

Register:

```text
privileged.active
privileged.mfa
privileged.recent
privileged.no-store
```

Operational order is always:

```php
['super_admin.auth', 'privileged.active', 'privileged.mfa']
```

Change both legacy `/superAdmin` groups and the admin-notification API group to the same boundary. Do not rely on `auth:super_admin` alone. Keep public and partial-stage routes outside the operational group.

- [ ] **Step 6: Add the shared completed-session test helper and migrate existing tests**

`AuthenticatesPrivilegedUsers` must authenticate the guard, set complete-stage/version session keys, and create the real `privileged_sessions` row through `PrivilegedSessionService`. Replace direct `actingAs(..., 'super_admin')` in the nine inventoried feature-test files; do not disable the new middleware globally. Rerun `rg -l 'actingAs.*super_admin' tests -g '*.php'` before committing and add any newly discovered operational test to the explicit staging list.

- [ ] **Step 7: Run the new route tests and Phase 0 authorization/document tests**

```powershell
php artisan test tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
```

Expected: PASS; no operational privileged route lacks active/MFA middleware.

- [ ] **Step 8: Commit**

```powershell
git add -- app/Models/SuperAdmin.php app/Http/Middleware/EnsurePrivilegedAccountIsActive.php app/Http/Middleware/EnsurePrivilegedMfaComplete.php app/Http/Middleware/EnsureRecentPrivilegedReauthentication.php app/Http/Middleware/NoStorePrivilegedSecurityResponse.php app/Http/Middleware/SuperAdminAuth.php bootstrap/app.php routes/web.php tests/Concerns/AuthenticatesPrivilegedUsers.php tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/AdminPremiumPlanManagementTest.php tests/Feature/BusinessScaling/BusinessScalingActorBoundaryRegressionTest.php tests/Feature/BusinessScaling/BusinessScalingNotificationTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuperAdmin/HardDeleteContainmentTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php
git commit -m "security: enforce privileged session stages"
```

Stage only test files actually migrated in this task; preserve unrelated tests.

## Task 5: Implement Password Login, MFA Challenge, and Recovery Login

**Files:**

- Modify: `app/Http/Controllers/SuperAdminAuthController.php`
- Create: `app/Http/Controllers/PrivilegedMfaController.php`
- Create: `app/Http/Requests/Privileged/VerifyMfaCodeRequest.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedAuthenticationFlowTest.php`

- [ ] **Step 1: Write failing staged-login tests**

Prove:

- unknown email, wrong password, suspended, inactive, and pending-setup standard login return the same outward error;
- login throttles on normalized email plus IP without exposing account existence;
- valid password for active/no-MFA account regenerates session and enters setup stage only;
- valid password for active/MFA account regenerates session, ignores remember-me until MFA succeeds, and enters challenge stage;
- challenge-stage sessions cannot reach any operation;
- valid newer TOTP completes authentication, regenerates session again, records actual session ID/version, honors remember-me, and updates last login time/IP;
- replay, stale code, malformed code, and throttled challenge fail without completing the session;
- one valid recovery code can complete the challenge and is removed atomically;
- a used recovery code fails; another code remains usable;
- logout removes the current registry row, invalidates the session, and rotates CSRF token;
- login/MFA/recovery success and security-relevant failure audits contain no credential material.

- [ ] **Step 2: Run the test and verify RED**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedAuthenticationFlowTest.php
```

- [ ] **Step 3: Register named rate limiters**

In `AppServiceProvider::boot()` define narrow limiters for:

```text
privileged-login
privileged-mfa
privileged-setup
privileged-password-reset
privileged-reauth
```

Use normalized email plus IP before authentication and administrator/session ID plus IP after authentication. Apply route middleware; do not implement controller-local counters.

- [ ] **Step 4: Implement stage-aware login and challenge controllers**

Password login sequence:

```text
validate → generic account/status/password check
→ guard login without remember → session regenerate
→ setup required? stage=setup : stage=mfa_challenge
→ redirect only to matching stage route
```

Challenge success sequence:

```text
lock admin → recheck active/setup/security state
→ consume newer TOTP or one recovery hash
→ audit + update last-login state → commit
→ regenerate session → stage=complete
→ establish privileged_sessions row → redirect intended/dashboard
```

Do not hold a transaction while rendering, redirecting, or deleting physical sessions.

- [ ] **Step 5: Extend `PrivilegedAudit` with specialized security methods**

Add allowlisted methods for login success/failure, MFA success/failure, and recovery-code consumption. Permit nullable actor/subject only where an unknown login identifier cannot be mapped; never write raw attempted email, password, TOTP, recovery code, or session ID.

- [ ] **Step 6: Run focused and route tests**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedAuthenticationFlowTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```powershell
git add -- app/Http/Controllers/SuperAdminAuthController.php app/Http/Controllers/PrivilegedMfaController.php app/Http/Requests/Privileged/VerifyMfaCodeRequest.php app/Providers/AppServiceProvider.php app/Services/PrivilegedAudit.php routes/web.php tests/Feature/SuperAdmin/PrivilegedAuthenticationFlowTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
git commit -m "security: require MFA for privileged login"
```

## Task 6: Implement Bootstrap, Invitations, and Setup Completion

**Files:**

- Create: `app/Console/Commands/BootstrapFirstSuperAdmin.php`
- Create: `app/Http/Controllers/PrivilegedSetupController.php`
- Create: `app/Http/Requests/Privileged/CompleteSetupPasswordRequest.php`
- Create: `app/Http/Requests/Privileged/InviteAdministratorRequest.php`
- Create: `app/Mail/PrivilegedSetupLinkMail.php`
- Create: `resources/views/mail/privileged/setup-link.blade.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php`

- [ ] **Step 1: Write failing bootstrap command tests**

Prove:

- command is interactive and exposes no password/token option or argument;
- zero-account execution prompts for identity data and creates exactly one `super_admin`, `pending_setup` account with `bootstrap_marker=platform`, a random unknown password hash, and one hashed setup token;
- `bootstrap_marker` uniqueness prevents two different first accounts;
- command queues an after-commit setup mail and prints only safe account/correlation information;
- mandatory audit failure rolls back account/token success state, while after-commit queue handoff failure leaves a resumable pending account and returns failure;
- when the sole account is still `pending_setup` and no active account exists, an explicit interactive confirmation may replace the setup token and requeue mail without creating a second account;
- once any bootstrap has completed or any other account exists, the command refuses ordinary execution.

- [ ] **Step 2: Write failing invitation and setup-link tests**

Cover:

- only `manage_administrators` plus recent reauthentication can invite;
- invite accepts identity/contact/role but no password;
- account, setup token, and mandatory success audit commit atomically; queued mail is after commit;
- mail delivery failure does not activate/delete the pending account; resend replaces the old token;
- valid link renders no-store setup page; invalid/expired/used link is generic;
- password must satisfy the centralized 12-character mixed-case/number/symbol rule;
- setup token is consumed atomically with password update, `password_changed_at`, and success audit; only after commit does the controller log in the guard, regenerate the session, and establish setup stage;
- a consumed token cannot set the password twice;
- setup stage can reach enrollment/recovery acknowledgement routes only.

- [ ] **Step 3: Run tests and verify RED**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php
```

- [ ] **Step 4: Implement the command, queued Markdown mail, controller, and invitation endpoint**

`PrivilegedSetupLinkMail` implements `ShouldQueue` and calls `afterCommit()`. Use the shared token service for bootstrap, invitation, resend, and consumption. Do not put the raw token into audit or console output.

Change current administrator creation to invitation semantics. Keep the current route name for compatibility in Phase 1, but remove password fields from accepted validation and payload. The target begins `pending_setup`, never `active`.

- [ ] **Step 5: Implement enrollment and one-time recovery-code acknowledgement**

Setup sequence:

```text
password setup succeeds
→ encrypted unconfirmed TOTP secret generated
→ enrollment page shows internal QR + manual secret
→ newer TOTP verified under lock
→ complete recovery-code hash set persisted
→ plaintext codes returned only in immediate response
→ hashed one-time acknowledgement token consumed
→ mfa_confirmed_at set
→ pending_setup becomes active (or existing active remains active)
→ audit commits
→ session regenerated/established as complete
```

If acknowledgement is lost, restart the code-generation portion and replace all hashes. Never store plaintext codes in Laravel session payload.

Generating or replacing an unconfirmed secret resets `mfa_last_used_timestep`; the acknowledgement token is hashed, purpose-bound, short-lived, and replaced whenever the recovery-code set changes.

- [ ] **Step 6: Run focused tests, inspect command signature, and inspect queued mail**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php tests/Feature/SuperAdmin/PrivilegedAuthenticationFlowTest.php
php artisan help super-admin:bootstrap
php artisan route:list --path=admin/setup --except-vendor
```

Expected: PASS; no secret-bearing CLI argument exists.

- [ ] **Step 7: Commit**

```powershell
git add -- app/Console/Commands/BootstrapFirstSuperAdmin.php app/Http/Controllers/PrivilegedSetupController.php app/Http/Requests/Privileged/CompleteSetupPasswordRequest.php app/Http/Requests/Privileged/InviteAdministratorRequest.php app/Mail/PrivilegedSetupLinkMail.php resources/views/mail/privileged/setup-link.blade.php app/Http/Controllers/SuperAdminController.php app/Services/PrivilegedAudit.php routes/web.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php
git commit -m "security: add privileged bootstrap and invitations"
```

## Task 7: Implement Password Reset, Password Change, MFA Reset, and Recovery Regeneration

**Files:**

- Create: `app/Http/Controllers/PrivilegedPasswordResetController.php`
- Create: `app/Http/Controllers/PrivilegedSecurityController.php`
- Create: `app/Http/Requests/Privileged/ResetPrivilegedPasswordRequest.php`
- Create: `app/Http/Requests/Privileged/ChangePrivilegedPasswordRequest.php`
- Create: `app/Mail/PrivilegedPasswordResetMail.php`
- Create: `resources/views/mail/privileged/password-reset.blade.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `routes/web.php`
- Extend: `tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php`

- [ ] **Step 1: Write failing forgot/reset tests**

Prove:

- forgot endpoint always returns the same response for unknown, pending, suspended, inactive, and active emails;
- only active accounts receive queued reset mail;
- limiter applies by normalized email/IP;
- reset token is hashed, expires, is purpose-specific, and is one-time;
- reset applies strong password, rotates remember token, increments security version, writes success audit atomically, and invalidates all privileged sessions after commit;
- MFA enrollment remains intact after password reset;
- audit/session-cleanup failures cannot expose credentials or restore stale authority.

- [ ] **Step 2: Write failing own-security tests**

Cover:

- both roles can view their own security state/count but never secret or hashes;
- password change requires recent reauthentication and a new strong password/confirmation; do not ask for the current password a second time after the centralized password-plus-TOTP challenge;
- successful change increments version, invalidates other sessions, and re-establishes current session;
- recovery-code regeneration requires recent reauthentication, replaces all hashes, invalidates other sessions, and displays plaintext only once;
- regeneration requires the same short-lived hashed acknowledgement-token flow as initial enrollment; losing the page replaces the whole unacknowledged code set rather than retrieving plaintext;
- recovery-code plaintext is never written to session storage, local storage, audit, or logs.

- [ ] **Step 3: Run tests and verify RED**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php
```

- [ ] **Step 4: Implement reset and own-security controllers**

Use queued after-commit Markdown mail, the token/session/MFA services, explicit Form Requests, row locks, and `PrivilegedAudit`. Generic public responses must not reveal account status or existence. Own MFA reset is intentionally implemented in Task 8 so it shares the final-Super-Admin invariant with platform-initiated resets.

- [ ] **Step 5: Run focused authentication/security tests**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php tests/Feature/SuperAdmin/PrivilegedAuthenticationFlowTest.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add -- app/Http/Controllers/PrivilegedPasswordResetController.php app/Http/Controllers/PrivilegedSecurityController.php app/Http/Requests/Privileged/ResetPrivilegedPasswordRequest.php app/Http/Requests/Privileged/ChangePrivilegedPasswordRequest.php app/Mail/PrivilegedPasswordResetMail.php resources/views/mail/privileged/password-reset.blade.php app/Services/PrivilegedAudit.php routes/web.php tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php
git commit -m "security: add privileged account recovery"
```

## Task 8: Add Recent Reauthentication and Administrator Identity Invariants

**Files:**

- Create: `app/Http/Controllers/PrivilegedReauthenticationController.php`
- Create: `app/Http/Requests/Privileged/ReauthenticatePrivilegedRequest.php`
- Create: `app/Services/AdministratorIdentityService.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedRecentReauthenticationTest.php`
- Create: `tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php`

- [ ] **Step 1: Write failing reauthentication tests**

Prove:

- current password plus newer TOTP records time and current security version;
- password-only, TOTP-only, recovery code, replayed TOTP, and wrong credentials fail;
- attempts are throttled and audited without secret material;
- a 15-minute-old timestamp or security-version change expires the grant;
- recent middleware redirects HTML to reauth and returns a generic `423`/reauth-required response for JSON;
- after success, intended URLs are local/allowlisted and never permit an open redirect.

- [ ] **Step 2: Write failing administrator lifecycle and concurrency tests**

Cover:

- self-target suspend/deactivate/role/MFA-reset requests are denied;
- Admin receives `403` for every platform-security mutation;
- Super Admin still needs recent reauthentication;
- suspended target may activate; inactive target returns to `pending_setup` through a fresh setup link rather than direct activation;
- role updates are allowlisted to `admin|super_admin`;
- another administrator's MFA reset preserves business status but enters setup-required condition and invalidates all sessions;
- own MFA reset uses the same service, invalidates all old sessions, creates one fresh setup-only session after commit, and is rejected when it would remove the final active MFA-enrolled Super Admin;
- success mutation and audit commit together; physical session cleanup runs after commit;
- audit failure rolls back state/version changes;
- two concurrent suspend/demote/deactivate/MFA-reset operations cannot eliminate the final active enrolled Super Admin;
- before initial bootstrap completion, one `pending_setup` bootstrap account may legitimately exist without satisfying the invariant.

- [ ] **Step 3: Run tests and verify RED**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedRecentReauthenticationTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php
```

- [ ] **Step 4: Implement recent reauthentication**

Store only timestamp and security version in session. Clear reauth keys on logout, password reset, MFA reset, version mismatch, or stage downgrade.

- [ ] **Step 5: Implement one transactional administrator identity service**

Canonical mutation shape:

```text
begin transaction
→ lock all SuperAdmin rows ORDER BY id
→ resolve actor and target from locked set
→ reject self-management/capability/source-state violations
→ simulate resulting active+MFA Super Admin set
→ reject zero-result invariant
→ mutate target + increment security_version where required
→ mandatory PrivilegedAudit success event
→ commit
→ targeted physical session cleanup
→ queue setup/security mail after commit where applicable
```

Replace direct legacy `AuditLog` writes for these newly hardened administrator paths only. Phase 3 owns the wider legacy audit cutover.

- [ ] **Step 6: Apply recent middleware to every Phase 1 high-risk mutation**

Inventory and protect invitation/resend, administrator lifecycle/role/MFA reset, own password, own MFA reset, and recovery-code regeneration routes. Do not protect login, setup, challenge, ordinary profile view, or logout.

- [ ] **Step 7: Run focused tests and route inventory**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedRecentReauthenticationTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php
php artisan route:list --path=admin --except-vendor
```

Expected: PASS; each high-risk route includes `privileged.recent`.

- [ ] **Step 8: Commit**

```powershell
git add -- app/Http/Controllers/PrivilegedReauthenticationController.php app/Http/Requests/Privileged/ReauthenticatePrivilegedRequest.php app/Services/AdministratorIdentityService.php app/Http/Controllers/SuperAdminController.php app/Services/PrivilegedAudit.php routes/web.php tests/Feature/SuperAdmin/PrivilegedRecentReauthenticationTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php
git commit -m "security: protect privileged identity changes"
```

## Task 9: Build the Privileged Security UI and Real Administrator Controls

**Files:**

- Modify: `resources/js/Pages/superAdmin/Auth/SuperAdminLogin.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedAuthShell.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedSetup.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedMfaEnrollment.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedMfaChallenge.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedRecoveryCodes.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedForgotPassword.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedResetPassword.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/PrivilegedReauthenticate.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/RecoveryCodesPanel.tsx`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/CreateAdmin.tsx`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Settings/Profile.tsx`
- Create: `resources/js/Pages/superAdmin/Settings/Security.tsx`
- Create: `resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx`
- Create: `resources/js/Pages/superAdmin/Settings/__tests__/Security.test.tsx`
- Extend: `resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx`

- [ ] **Step 1: Write failing frontend behavior tests**

Assert real controls and accessibility:

- login includes forgot-password link and submits only email/password/remember;
- six-digit TOTP input has a label, numeric input mode, autocomplete `one-time-code`, error summary, and recovery-code alternative;
- enrollment displays QR, manual secret, verification input, and no operational navigation;
- recovery codes are copy/download/print friendly, individually readable, and require explicit acknowledgement;
- setup/reset forms enforce the displayed 12-character policy but treat server validation as authoritative;
- reauth clearly explains the 15-minute window and accepts password plus TOTP only;
- Create Admin becomes Invite Administrator and has no password field;
- management shows status, role, MFA/setup state and only backend-supported actions;
- security page shows MFA status and recovery-code count, never secret/hashes;
- loading and error handling prevent duplicate submissions;
- no UI presents bypass, disable-MFA-without-setup, hard-delete, or fake success controls.

- [ ] **Step 2: Run frontend tests and verify RED**

```powershell
pnpm run test:frontend -- resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx resources/js/Pages/superAdmin/Settings/__tests__/Security.test.tsx resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx
```

- [ ] **Step 3: Implement the shared standalone auth shell and stage pages**

Do not mount the operational `AppLayout` on setup/challenge/reset/recovery pages because its navigation/notification requests require a complete privileged session. Reuse existing Tailwind tokens and form/button patterns; no visual redesign or new component library.

- [ ] **Step 4: Convert administrator creation and management controls**

Remove password fields and client validation from `CreateAdmin.tsx`; submit identity/contact/role only and label the action as invitation. Add only the lifecycle, role, resend, and MFA-reset controls backed by Task 8 endpoints. A reauth-required response routes to the reauth page and returns to the management page for a deliberate retry; never auto-replay a mutation.

- [ ] **Step 5: Implement own-security page and profile link**

Security actions use registered routes and display one-time recovery codes only from the immediate response. Never persist them in localStorage/sessionStorage or console output.

- [ ] **Step 6: Run frontend tests and production build**

```powershell
pnpm run test:frontend -- resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx resources/js/Pages/superAdmin/Settings/__tests__/Security.test.tsx resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx
pnpm run build
```

Expected: PASS. Record build output and bundle warnings; no typecheck/lint claim is allowed because the repository has no committed scripts for them.

- [ ] **Step 7: Commit**

```powershell
git add -- resources/js/Pages/superAdmin/Auth/SuperAdminLogin.tsx resources/js/Pages/superAdmin/Auth/PrivilegedAuthShell.tsx resources/js/Pages/superAdmin/Auth/PrivilegedSetup.tsx resources/js/Pages/superAdmin/Auth/PrivilegedMfaEnrollment.tsx resources/js/Pages/superAdmin/Auth/PrivilegedMfaChallenge.tsx resources/js/Pages/superAdmin/Auth/PrivilegedRecoveryCodes.tsx resources/js/Pages/superAdmin/Auth/PrivilegedForgotPassword.tsx resources/js/Pages/superAdmin/Auth/PrivilegedResetPassword.tsx resources/js/Pages/superAdmin/Auth/PrivilegedReauthenticate.tsx resources/js/Pages/superAdmin/Auth/RecoveryCodesPanel.tsx resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx resources/js/Pages/superAdmin/AdminTeam/CreateAdmin.tsx resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx resources/js/Pages/superAdmin/Settings/Profile.tsx resources/js/Pages/superAdmin/Settings/Security.tsx resources/js/Pages/superAdmin/Settings/__tests__/Security.test.tsx resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx
git commit -m "feat: add privileged MFA security flows"
```

## Task 10: Write the Forced-MFA Deployment and Recovery Runbook

**Files:**

- Create: `docs/runbooks/super-admin-phase-1-identity-mfa.md`
- Modify only if implementation changed a durable rule: `docs/ai-learning-log.md`

- [ ] **Step 1: Document preflight and stop conditions**

Require:

- current database/storage backup and tested restore procedure;
- Phase 0 tip and focused tests confirmed;
- `APP_KEY` present and stable before encrypted TOTP secrets are written;
- HTTPS canonical admin URL;
- database session driver/table confirmed for physical cleanup;
- SMTP and queue worker verified with failed-job visibility;
- `iconv`, `openssl`, and `xmlwriter` extensions available;
- at least one recoverable current Super Admin password before rollout;
- maintenance window and named operator;
- stop on missing APP_KEY, failed mail/queue test, unsupported status migration, no recoverable account, or failing security tests.

- [ ] **Step 2: Document fresh-install bootstrap**

```text
migrate
→ run interactive super-admin:bootstrap
→ confirm one pending_setup account + queued setup mail
→ consume setup link over HTTPS
→ set password + enroll TOTP + save/acknowledge recovery codes
→ verify active MFA-complete account
→ verify bootstrap command now refuses
```

- [ ] **Step 3: Document existing-deployment forced enrollment**

```text
maintenance mode
→ deploy dependencies/code
→ migrate additive security schema
→ verify route middleware inventory
→ verify pre-Phase-1 cookies are denied because they have no complete stage/registry row
→ operator logs in with current password
→ forced MFA enrollment + recovery acknowledgement
→ verify complete registered session
→ verify a second recoverable Super Admin before high-risk changes
→ focused browser/security checks
→ exit maintenance
```

Existing active administrators remain active but cannot operate until they enroll. Do not backfill fake MFA confirmation or generate secrets/recovery codes offline.

- [ ] **Step 4: Document rollback security floor**

- re-enter maintenance mode;
- preserve any encrypted MFA data and activity history;
- do not restore known passwords, password-only operational access, hard deletes, or Phase 0 bypasses;
- if schema rollback encounters `pending_setup`, convert through an explicit operator-reviewed forward recovery rather than silently activating the account;
- cleared/invalidated sessions are never restored;
- if application rollback would disable active/MFA enforcement after any Phase 1 credential was issued, stop and roll forward with a corrective patch;
- document recovery when setup mail fails, a setup link expires, or an invitation remains pending;
- loss of the only authenticator and all recovery codes has no unapproved console bypass; follow backup/incident escalation.

- [ ] **Step 5: Verify every command and route named by the runbook**

```powershell
php artisan help super-admin:bootstrap
php artisan route:list --path=admin --except-vendor
php artisan queue:failed
```

- [ ] **Step 6: Commit**

```powershell
git add -- docs/runbooks/super-admin-phase-1-identity-mfa.md
# Only when this phase produced a genuinely durable repository lesson:
git add -- docs/ai-learning-log.md
git commit -m "docs: add privileged MFA rollout runbook"
```

Only stage the learning log if a genuinely durable repository lesson was added.

## Task 11: Integrated Phase 1 Security and Regression Verification

**Files:**

- All Phase 1 files above
- No production behavior changes unless a failing verification reveals a Phase 1 defect

- [ ] **Step 1: Run the full focused backend Phase 1 suite**

```powershell
php artisan test tests/Unit/Security/MfaDependencyContractTest.php tests/Unit/Models/SuperAdminCapabilityTest.php tests/Unit/Services/PrivilegedMfaServiceTest.php tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PrivilegedAuthenticationFlowTest.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php tests/Feature/SuperAdmin/PrivilegedPasswordRecoveryTest.php tests/Feature/SuperAdmin/PrivilegedRecentReauthenticationTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
```

Expected: PASS, zero failures.

- [ ] **Step 2: Run the Phase 0 and adjacent privileged regression suite**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/KnownCredentialContainmentTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php tests/Feature/SuperAdmin/HardDeleteContainmentTest.php tests/Feature/AdminPremiumPlanManagementTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php
```

Expected: PASS. Every privileged fixture uses the shared completed-session helper where operational access is intended.

- [ ] **Step 3: Run frontend security tests and production build**

```powershell
pnpm run test:frontend -- resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx resources/js/Pages/superAdmin/Settings/__tests__/Security.test.tsx resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
pnpm run build
```

- [ ] **Step 4: Run broader suites**

```powershell
composer test
pnpm run test:frontend
composer audit
git diff --check
```

Record exact pass/fail/skipped counts. Pre-existing unrelated failures require a separate evidence file; do not weaken Phase 1 assertions to accommodate them.

- [ ] **Step 5: Inspect route, schema, and secret boundaries**

```powershell
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
php artisan migrate:status
rg -n "mfa_secret|mfa_recovery_codes|setup_token|reset_token|recovery_code|current_password|password" app/Services/PrivilegedAudit.php app/Http/Controllers app/Mail resources/views/mail
rg -n "actingAs\([^\n]*super_admin" tests -g "*.php"
```

Expected:

- only explicit public/stage routes lack the complete operational middleware stack;
- no raw security material enters audit methods or logs;
- remaining direct `actingAs` calls are intentional authentication-stage tests, not operational bypasses;
- no client-provided field can set MFA, security-version, token-hash, session ownership, or bootstrap metadata.

- [ ] **Step 6: Perform targeted concurrency/failure-path verification**

Run the supported database integration profile for:

```text
two final-Super-Admin removal attempts
two uses of one setup/reset token
two uses of one recovery code
two accepts of one TOTP timestep
password/MFA reset racing an existing operational request
physical session cleanup failure after security-version commit
```

Exactly one valid terminal outcome may commit in each duplicate race, and stale authority must fail on its next request.

- [ ] **Step 7: Perform browser verification for both roles**

Use webapp-testing against the local application:

```text
existing Admin password → forced enrollment → recovery acknowledgement → operational access
existing Super Admin password → forced enrollment → operational access
normal login → TOTP and recovery-code alternatives
forgot/reset → all old sessions denied
password change → current survives, other session denied
Admin own security allowed; platform security denied
Super Admin invitation → setup → active MFA login
recent reauth → administrator mutation → expiry requires reauth again
suspended administrator cookie → next request denied
final active enrolled Super Admin removal → denied
```

Capture screenshots only as QA evidence. Confirm keyboard navigation, visible focus, labels, error summaries, mobile layout, and recovery-code print/copy usability.

- [ ] **Step 8: Complete the required sequential review stack**

Record:

1. **simplify/ponytail:** no Fortify, generic IAM, token framework, client QR package, or duplicate security service was introduced;
2. **standards review:** Laravel guards, Form Requests, encrypted casts, named rate limiters, queued after-commit mail, transactions, and existing UI conventions are followed;
3. **spec review:** every Phase 1 acceptance criterion maps to a passing test or explicit deployment check;
4. **TypeScript clean-code review:** no new `any`, unsafe assertion, secret persistence, or duplicated form-state logic;
5. **code splitting:** N/A unless measurement shows QR/security pages materially affect the operational bundle; do not split small forms speculatively;
6. **gauge improvements:** record before/after counts for password-only operational routes, unprotected privileged routes, known bootstrap credentials, and invalidatable privileged sessions;
7. **security review:** verify enumeration resistance, CSRF, throttling, encrypted/hashed material, token expiry/use, TOTP replay, session fixation/versioning, open redirects, self-management, final-Super-Admin concurrency, audit redaction, and no-store headers;
8. **reuse/dead-code:** remove only password-creation UI/code and legacy administrator audit writes made obsolete by this phase; preserve unrelated Phase 2–7 code;
9. **verification-before-completion:** no pass/completion claim without fresh command output.

The repository does not authorize an unrequested parallel subagent review. Perform Standards, Spec, and risk reviews sequentially unless the user explicitly approves the bounded read-only review gate.

- [ ] **Step 9: Commit verification-only fixes, if any**

Use a narrow message and stage only files changed to resolve verified Phase 1 findings. Do not create an empty commit.

## Phase 1 Completion Evidence

Attach to the handoff:

- commit IDs for Tasks 1–10 and any verification fix;
- exact focused/full test, build, audit, route, migration, and browser results;
- route inventory proving authentication + active + MFA middleware on every operational privileged route;
- database evidence that TOTP secrets are encrypted and recovery/setup/reset values are hashed;
- session-registry evidence for all/other invalidation and security-version fail-closed behavior;
- bootstrap/invitation mail queue evidence without raw token disclosure;
- concurrency evidence that the final active MFA-enrolled Super Admin survives competing changes;
- confirmation that both roles can manage their own security while only Super Admin can manage platform/other-admin security;
- confirmation that no Phase 2+ workflow redesign or administrator deletion was introduced;
- any skipped deployment/browser check with the exact blocker and owner.

Do not call Phase 1 complete based only on a successful MFA happy path. Completion requires negative-path, replay, lockout, session, concurrency, audit-redaction, route-inventory, and rollback evidence.
