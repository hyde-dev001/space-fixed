# Super Admin Phase 0 Immediate Containment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the currently reachable privileged-authorization, known-credential, public-sensitive-document, and irreversible account-deletion paths without implementing Phase 1 MFA or later lifecycle redesigns.

**Architecture:** Add the critical subset of the final fixed capability model to the existing `super_admin` guard, and send all newly hardened privileged events directly to Spatie `activity_log`. Introduce mixed-disk metadata so new sensitive uploads can become private immediately while a resumable command copies, verifies, switches, and removes legacy public files. Remove routine hard-delete routes and controls; preserve suspension/activation until Phase 2 adds archive/restore.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, Laravel filesystem, Spatie Activitylog 4, PHPUnit 11, Inertia 2, React 18, TypeScript 5.7, Vitest 3, pnpm.

---

## Design Authority and Scope Guard

Implement against:

- `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`
- Phase 0 findings: SA-001, SA-003, SA-005, and SA-006
- Approved sequence refinement: Phase 0 introduces the minimal final capability and privileged-audit paths; Phases 1 and 3 extend them rather than replacing them.

Phase 0 includes only:

1. the critical subset of fixed capabilities;
2. backend enforcement on currently exposed privileged routes;
3. removal of the known migration-seeded account on fresh installation and safe interactive remediation for deployed accounts;
4. private storage and authorized/audited delivery for shop registration documents and customer Valid IDs;
5. copy-first migration and rollback support for existing sensitive files;
6. removal of administrator, shop, and user hard-delete routes, methods, and visible controls;
7. canonical registration mutations plus safe GET-only compatibility.

Do not add in this phase:

- MFA, TOTP, recovery codes, setup sessions, invitations, or the final bootstrap link flow;
- `pending_setup` or other Phase 1 identity-state columns;
- soft deletion, archive/restore, suspension identity, or appeal state-machine changes;
- full audit migration/backfill or audit-history UI cutover;
- billing fixes, pagination, broad controller splitting, or duplicate-route cleanup outside the secured paths;
- document expiration, renewal, DTI/SEC splitting, or immutable version metadata.

Every task must leave its changed path testable. Never deploy an intermediate commit independently unless its task acceptance checks pass.

## Current Repository Evidence

- `database/migrations/2026_01_15_110000_create_super_admins_table.php` creates an active `admin@thesis.com` account with a known password.
- `app/Http/Middleware/SuperAdminAuth.php` checks authentication only.
- `app/Http/Middleware/CheckSuperAdminRole.php` contains a direct role comparison and is not consistently applied.
- `routes/web.php` protects the main `/admin` group only with `super_admin.auth`; regular `admin` actors can reach administrator, plan, subscription, and appeal-decision endpoints.
- Duplicate `/superAdmin` registration mutation routes exist in two route groups.
- `ShopRegistrationController`, `ShopOwnerAuthController`, and `UserController` write sensitive files to the public disk.
- `ShopOwnerRegistrationViewController`, `SuperAdminController`, `SuperAdminUserManagementController`, and `ShopOwnerAuthController` expose raw paths or `/storage/*` URLs.
- `SubmitShopOwnerUpgradeRequest` assumes reused `ShopDocument` records live on the public disk.
- `SuperAdminController` implements hard deletion for administrators, shops, and users; the matching routes are named `admin.admins.delete`, `admin.shops.delete`, and `admin.users.delete`.
- `AdminManagement.tsx` and `RegisteredShops.tsx` display permanent-delete controls.
- `activity_log` and private `local` storage already exist. `MoveHandoffProofsToPrivate` and `MigrateFinanceReceiptsToPrivateStorage` provide nearby resumable migration patterns.

## Acceptance Criteria

Phase 0 is complete only when all of the following are true:

1. An authenticated regular `admin` receives `403` from administrator-management actions, plan-management actions, subscription-intervention actions, and appeal decision endpoints.
2. Both privileged roles retain the approved Phase 0 operational capabilities for registration review and account intervention.
3. Critical authorization is represented by deterministic code capabilities, not Spatie permission rows or a configurable permission UI.
4. Fresh migrations create no privileged account or known privileged password.
5. The deployed seeded account can be rotated interactively without command-line password arguments, logged credentials, account deletion, or loss of the only recoverable Super Admin; the maintenance operation invalidates every database-backed session because the current session rows cannot reliably identify the `super_admin` guard.
6. New shop documents and customer Valid IDs are stored on the private `local` disk, and their disk fields are server-owned metadata that request payloads cannot select or override.
7. Existing public sensitive files can be dry-run, migrated in chunks, checksum-verified, reconciled, rerun, and safely restored for application rollback.
8. No privileged/shop-owner payload exposes a private disk path or `/storage/*` URL for these documents.
9. Document delivery enforces authentication or signed-link authority, object relationship, capability/scope, storage existence, and mandatory access audit before response creation; an authenticated actor requesting an out-of-scope object receives `404`.
10. Audit failure prevents document bytes from being returned.
11. Sensitive responses use `private, no-store`, `nosniff`, sanitized filenames, safe MIME handling, and attachment disposition for unknown/risky types.
12. Routine hard-delete routes, controller methods, and visible controls for administrators, shops, and users are absent.
13. Existing records remain intact when old DELETE URLs are requested.
14. Canonical registration mutations exist once under `/admin`; duplicate `/superAdmin` mutations are removed in the first authorization task, the legacy registration GET redirects safely, and legacy mutation URLs do not mutate.
15. Every new privileged audit record includes normalized actor, event, target, source, IP/context, and correlation metadata without secrets or unrestricted request data.
16. No Phase 1+ feature is introduced.

## File Map

### Create

- `database/factories/SuperAdminFactory.php` — reusable privileged-role test fixtures.
- `app/Http/Middleware/EnsurePrivilegedCapability.php` — final parameterized fixed-capability enforcement middleware.
- `app/Services/PrivilegedAudit.php` — minimal final writer for new hardened privileged events.
- `app/Http/Controllers/PrivateSensitiveDocumentController.php` — scoped delivery of shop documents and customer Valid IDs.
- `database/migrations/2026_08_12_000001_add_storage_disks_to_sensitive_documents.php` — mixed-state disk metadata only; no file movement.
- `app/Console/Commands/RotateCompromisedSuperAdminCredential.php` — hidden-prompt credential rotation and session invalidation.
- `app/Console/Commands/MigrateSensitiveDocumentsToPrivateStorage.php` — copy/verify/switch/remove migration and restore support.
- `tests/Unit/Models/SuperAdminCapabilityTest.php` — exact two-role capability matrix.
- `tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php` — route-level positive and negative authorization.
- `tests/Feature/SuperAdmin/PrivilegedAuditTest.php` — normalized event and metadata boundaries.
- `tests/Feature/SuperAdmin/KnownCredentialContainmentTest.php` — fresh-install source invariant and rotation command behavior.
- `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php` — IDOR, scope, audit, headers, and payload tests.
- `tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php` — dry-run, copy, conflict, resume, and restore tests.
- `tests/Feature/SuperAdmin/HardDeleteContainmentTest.php` — route/method removal and record-retention tests.
- `resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx` — permanent-delete controls remain absent.
- `docs/runbooks/super-admin-phase-0-containment.md` — deployment, verification, incident stop conditions, and rollback floor.

### Modify

- `app/Models/SuperAdmin.php` — fixed capability constants/map and factory support.
- `bootstrap/app.php` — register `privileged.capability` middleware alias.
- `routes/web.php` — capability middleware, canonical registration routes, private document routes, GET-only compatibility, and hard-delete removal.
- `database/migrations/2026_01_15_110000_create_super_admins_table.php` — remove privileged-account seeding from fresh migrations.
- `app/Models/ShopDocument.php` — disk metadata and raw-path serialization protection.
- `app/Models/User.php` — Valid ID disk metadata and raw-path serialization protection.
- `app/Http/Controllers/ShopRegistrationController.php` — private storage for new shop documents.
- `app/Http/Controllers/ShopOwnerAuthController.php` — private storage-aware registration/resubmission and route URLs instead of raw paths.
- `app/Http/Controllers/UserController.php` — private storage for new customer Valid IDs.
- `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php` — canonical private document URLs.
- `app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php` — private Valid ID URLs.
- `app/Http/Controllers/SuperAdminController.php` — private shop-detail URLs and hard-delete method removal.
- `app/Actions/ShopOwner/SubmitShopOwnerUpgradeRequest.php` — reuse source files from each `ShopDocument` record's authoritative disk.
- `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx` — remove administrator permanent-delete handler, icon, and control.
- `resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx` — remove shop permanent-delete handler, icon, and control.
- `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx` — consume server-provided private Valid ID URL; remove public-path URL construction.

Do not edit `.env`, generated dependencies, or unrelated dirty files.

## Task 1: Add the Critical Final Capability Subset

**Files:**

- Create: `database/factories/SuperAdminFactory.php`
- Create: `app/Http/Middleware/EnsurePrivilegedCapability.php`
- Create: `tests/Unit/Models/SuperAdminCapabilityTest.php`
- Create: `tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php`
- Modify: `app/Models/SuperAdmin.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write the failing model matrix test**

Use table-driven assertions for these exact Phase 0 capabilities:

```php
#[DataProvider('capabilityMatrix')]
public function test_role_capability_matrix(string $role, string $capability, bool $expected): void
{
    $admin = SuperAdmin::factory()->make(['role' => $role]);

    $this->assertSame($expected, $admin->hasCapability($capability));
}

public static function capabilityMatrix(): array
{
    return [
        ['admin', SuperAdmin::CAP_REVIEW_REGISTRATIONS, true],
        ['admin', SuperAdmin::CAP_INTERVENE_ACCOUNTS, true],
        ['admin', SuperAdmin::CAP_MANAGE_ADMINISTRATORS, false],
        ['admin', SuperAdmin::CAP_RESOLVE_APPEALS, false],
        ['admin', SuperAdmin::CAP_MANAGE_PLANS, false],
        ['admin', SuperAdmin::CAP_INTERVENE_SUBSCRIPTIONS, false],
        ['super_admin', SuperAdmin::CAP_REVIEW_REGISTRATIONS, true],
        ['super_admin', SuperAdmin::CAP_INTERVENE_ACCOUNTS, true],
        ['super_admin', SuperAdmin::CAP_MANAGE_ADMINISTRATORS, true],
        ['super_admin', SuperAdmin::CAP_RESOLVE_APPEALS, true],
        ['super_admin', SuperAdmin::CAP_MANAGE_PLANS, true],
        ['super_admin', SuperAdmin::CAP_INTERVENE_SUBSCRIPTIONS, true],
    ];
}
```

- [ ] **Step 2: Run the model test and verify it fails**

Run:

```powershell
php artisan test tests/Unit/Models/SuperAdminCapabilityTest.php
```

Expected: FAIL because `SuperAdminFactory` and `hasCapability()` do not exist.

- [ ] **Step 3: Add the minimum factory and deterministic model map**

Use explicit capability names and explicit role lists—no wildcard, database permission lookup, or Spatie role synchronization:

```php
public const CAP_REVIEW_REGISTRATIONS = 'review_registrations';
public const CAP_INTERVENE_ACCOUNTS = 'intervene_accounts';
public const CAP_MANAGE_ADMINISTRATORS = 'manage_administrators';
public const CAP_RESOLVE_APPEALS = 'resolve_appeals';
public const CAP_MANAGE_PLANS = 'manage_plans';
public const CAP_INTERVENE_SUBSCRIPTIONS = 'intervene_subscriptions';

private const CAPABILITIES_BY_ROLE = [
    'admin' => [
        self::CAP_REVIEW_REGISTRATIONS,
        self::CAP_INTERVENE_ACCOUNTS,
    ],
    'super_admin' => [
        self::CAP_REVIEW_REGISTRATIONS,
        self::CAP_INTERVENE_ACCOUNTS,
        self::CAP_MANAGE_ADMINISTRATORS,
        self::CAP_RESOLVE_APPEALS,
        self::CAP_MANAGE_PLANS,
        self::CAP_INTERVENE_SUBSCRIPTIONS,
    ],
];

public function hasCapability(string $capability): bool
{
    return in_array($capability, self::CAPABILITIES_BY_ROLE[$this->role] ?? [], true);
}
```

The factory supplies `admin()` and `superAdmin()` states and always hashes through the model cast. Do not add a permission-management dependency or UI.

- [ ] **Step 4: Run the model test and verify it passes**

Run the command from Step 2.

Expected: PASS with all matrix rows.

- [ ] **Step 5: Write failing route authorization tests**

Test representative requests and route middleware inventory:

- unauthenticated request redirects to `admin.login`;
- regular Admin gets `403` for administrator management, plan mutation, subscription intervention, and appeal decision;
- regular Admin can load the canonical registration queue and reach account-intervention endpoints with valid fixtures;
- Super Admin is not denied by any of the six capability middleware entries;
- every restricted named route declares the expected `privileged.capability:<name>` middleware;
- the route collection contains exactly one canonical registration approval route and one canonical rejection route;
- both legacy `/superAdmin` registration mutation URLs return `404`/`405` and leave registration state unchanged.

For mutation tests, assert both the response and unchanged database state when denied. Do not treat a hidden frontend control as authorization evidence.

- [ ] **Step 6: Run the feature test and verify it fails**

Run:

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php
```

Expected: FAIL because regular Admin currently reaches restricted routes and the middleware alias does not exist.

- [ ] **Step 7: Implement one parameterized middleware and apply it route-by-route**

Core middleware behavior:

```php
public function handle(Request $request, Closure $next, string $capability): Response
{
    $admin = $request->user('super_admin');

    abort_unless($admin instanceof SuperAdmin, 401);
    abort_unless($admin->hasCapability($capability), 403);

    return $next($request);
}
```

Register only `privileged.capability` in `bootstrap/app.php`. Keep `super_admin.auth` first in the route group.

Apply the matrix:

| Route area | Capability |
|---|---|
| Administrator list/create/suspend/activate | `manage_administrators` |
| Canonical registration list/approve/reject and registration document access | `review_registrations` |
| Business upgrade request list/review/document access | `review_registrations` |
| Registered shops, users, and suspend/reactivate actions | `intervene_accounts` |
| Premium-plan mutations and subscription-management page if it exposes plan controls | `manage_plans` |
| Subscription cancel/upgrade/downgrade endpoints | `intervene_subscriptions` |
| Appeal approval/rejection | `resolve_appeals` |

Add the canonical registration list/approve/reject routes under the protected `/admin` group in this task. Remove both duplicate `/superAdmin` approval/rejection declarations immediately; no legacy mutation bypass may remain between tasks. Keep at most one safe legacy registration GET temporarily, protected by the existing privileged authentication boundary. Task 8 will reduce that GET compatibility path to one redirect and perform only non-security cleanup.

The appeal queue GET remains available without `resolve_appeals`; only decisions require it. Platform monitoring remains available to both roles.

- [ ] **Step 8: Run authorization tests and inspect the route inventory**

Run:

```powershell
php artisan test tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin/shop-owner-registration --except-vendor
```

Expected: tests PASS; route output shows the capability middleware on each restricted route, exactly one canonical approval/rejection pair, and no legacy registration mutation.

- [ ] **Step 9: Commit**

```powershell
git add -- database/factories/SuperAdminFactory.php app/Models/SuperAdmin.php app/Http/Middleware/EnsurePrivilegedCapability.php bootstrap/app.php routes/web.php tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php
git commit -m "security: enforce critical privileged capabilities"
```

## Task 2: Introduce the Final Privileged Audit Write Path

**Files:**

- Create: `app/Services/PrivilegedAudit.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedAuditTest.php`

- [ ] **Step 1: Write failing audit-contract tests**

Verify that:

- the log name is `privileged`;
- the causer is the `SuperAdmin` model;
- actor type, guard, ID, role, event, target type, and target ID are present;
- every record contains a UUID correlation ID and an explicit `source`;
- HTTP events record `source=http` and the request IP, reuse a valid inbound `X-Correlation-ID` UUID, and generate a UUID when that header is absent or invalid;
- console events record `source=console`, use an operation UUID generated before mutation, and do not invent an HTTP request or IP address;
- event-specific IDs are allowed;
- passwords, TOTP values, recovery codes, tokens, raw filenames, paths, document bytes, and unrestricted request data are never accepted into properties.

Use specialized public methods for Phase 0 rather than a caller-supplied arbitrary property bag:

```php
$audit->documentAccessInitiated($request, $admin, $document, $shopOwner, $mime, $disposition);
$audit->customerValidIdAccessInitiated($request, $admin, $user, $mime, $disposition);
$audit->credentialRotatedByConsole($admin, $operationId);
```

- [ ] **Step 2: Run the audit test and verify it fails**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivilegedAuditTest.php
```

Expected: FAIL because `PrivilegedAudit` does not exist.

- [ ] **Step 3: Implement the smallest reusable service**

Each method composes a normalized event through a private writer:

```php
private function write(
    string $event,
    ?SuperAdmin $actor,
    Model $subject,
    string $source,
    string $correlationId,
    ?string $ipAddress,
    array $properties,
): void {
    $logger = activity('privileged')->performedOn($subject);

    if ($actor !== null) {
        $logger->causedBy($actor);
    }

    $logger->withProperties($properties)->log($event);
}
```

The private writer owns the fixed base schema so caller-supplied event metadata cannot override actor, event, target, source, correlation, or IP/context fields. HTTP methods validate an inbound correlation header as a UUID before reuse and otherwise generate one with `Str::uuid()`. Console callers generate one operation UUID before mutation, pass it to every audit write for that operation, and may print only that safe identifier for support correlation.

Keep the service concrete and injectable; do not add an interface with one implementation or a new global request-correlation subsystem in Phase 0. Do not catch storage/database exceptions inside the service. Callers that require mandatory audit must fail closed when this method throws.

- [ ] **Step 4: Run the audit test and verify it passes**

Run the command from Step 2.

Expected: PASS and exact `activity_log` assertions succeed.

- [ ] **Step 5: Commit**

```powershell
git add -- app/Services/PrivilegedAudit.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php
git commit -m "security: add privileged audit writer"
```

## Task 3: Remove and Remediate the Known Privileged Credential

**Files:**

- Create: `app/Console/Commands/RotateCompromisedSuperAdminCredential.php`
- Create: `tests/Feature/SuperAdmin/KnownCredentialContainmentTest.php`
- Modify: `database/migrations/2026_01_15_110000_create_super_admins_table.php`
- Use: `app/Services/PrivilegedAudit.php`

- [ ] **Step 1: Write failing fresh-install and command tests**

Tests must prove:

1. a fresh migrated database contains zero `super_admins`, and the historical schema migration contains no privileged account insert and imports neither `Hash` nor a known password;
2. the command accepts only a non-secret email argument; it has no password option;
3. current and replacement passwords are requested through hidden interactive prompts;
4. an incorrect current password makes no change and emits no success audit;
5. a valid current password rotates the hash, keeps the account ID/role/status, replaces the remember token, clears every row from the configured database session table, and writes `super_admin_credential_rotated` without credential data;
6. the command does not target `sessions.user_id`, because the current `DatabaseSessionHandler` obtains that value from the default `web` guard and therefore cannot reliably distinguish Super Admin sessions;
7. the console output and audit row share the same generated operation UUID and record `source=console` without a fabricated IP address;
8. audit failure rolls back the password/session mutation;
9. the only active Super Admin is rotated in place, never disabled or deleted.

Use a synthetic test password, not the historical production value:

```php
$this->artisan('super-admin:rotate-compromised-credential', ['email' => $admin->email])
    ->expectsQuestion('Current password', 'Legacy-Test-Pass1')
    ->expectsQuestion('New password', 'Replacement-Test-Pass2')
    ->expectsQuestion('Confirm new password', 'Replacement-Test-Pass2')
    ->assertSuccessful();
```

- [ ] **Step 2: Run the credential test and verify it fails**

```powershell
php artisan test tests/Feature/SuperAdmin/KnownCredentialContainmentTest.php
```

Expected: FAIL because the migration still inserts the account and the command does not exist.

- [ ] **Step 3: Remove data seeding from the historical schema migration**

Keep only table creation/drop logic. Remove the `Hash` import, `DB::table(...)->insert(...)`, known email/password data, and comments instructing operators to change defaults.

This edit affects fresh installations. It does not remediate already-migrated databases; the command does that.

**Containment invariant:** Never disable the only recoverable Super Admin before a secure replacement/setup path exists. Phase 0 therefore rotates the existing account in place; Phase 1 later replaces this emergency path with the approved setup-link and MFA bootstrap lifecycle.

- [ ] **Step 4: Implement interactive in-place rotation**

Command contract:

```text
super-admin:rotate-compromised-credential {email : Existing Super Admin email}
```

Rules:

- refuse non-interactive execution if hidden questions cannot be answered;
- locate exactly one existing `SuperAdmin` by normalized email;
- verify the hidden current password with `Hash::check`;
- validate the replacement with Laravel `Password::min(12)->mixedCase()->numbers()->symbols()` and confirmation;
- reject reuse of the current password;
- wrap password, remember-token, database-session invalidation, and mandatory audit in one local transaction;
- never print, log, audit, or pass passwords through command arguments/environment variables;
- generate one UUID before mutation, pass it to the audit service, and report only account ID/email, that safe correlation ID, and generic success/failure;
- preserve status and role.

Before prompting for credentials, require the configured session driver to be `database` and verify that its configured table exists and is writable; otherwise stop before mutation. The current Laravel database session handler derives `user_id` from the default `web` guard, so `sessions.user_id` is not a trustworthy Super Admin identifier. Never issue a targeted `where user_id = $admin->id()` deletion.

While the application is in maintenance mode, delete every row from the configured database session table inside the same local transaction as credential rotation and mandatory audit, then assert the table is empty before success. This deliberately logs out all database-backed users. If the deployment uses a non-database session store, stop and require a separately verified store-specific invalidation procedure; do not claim sessions were invalidated.

- [ ] **Step 5: Run the credential tests and inspect the command signature**

```powershell
php artisan test tests/Feature/SuperAdmin/KnownCredentialContainmentTest.php
php artisan help super-admin:rotate-compromised-credential
```

Expected: tests PASS; help output exposes an email argument but no secret option.

- [ ] **Step 6: Commit**

```powershell
git add -- database/migrations/2026_01_15_110000_create_super_admins_table.php app/Console/Commands/RotateCompromisedSuperAdminCredential.php tests/Feature/SuperAdmin/KnownCredentialContainmentTest.php
git commit -m "security: remove known super admin credential"
```

## Task 4: Add Mixed-State Sensitive-Document Disk Metadata

**Files:**

- Create: `database/migrations/2026_08_12_000001_add_storage_disks_to_sensitive_documents.php`
- Modify: `app/Models/ShopDocument.php`
- Modify: `app/Models/User.php`
- Create: `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`

- [ ] **Step 1: Write failing schema/model tests**

Assert:

- `shop_documents.disk` exists, is non-null, and defaults to `public` for legacy/new rows until writers switch;
- `users.valid_id_disk` exists, is non-null, and defaults to `public`;
- both values persist correctly when assigned explicitly by trusted application code;
- `ShopDocument::toArray()` excludes `file_path`;
- `User::toArray()` excludes `valid_id_path`.

- [ ] **Step 2: Run the test and verify it fails**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php --filter=disk_metadata
```

Expected: FAIL because disk columns do not exist and paths serialize.

- [ ] **Step 3: Add the additive migration and model fields**

Migration shape:

```php
Schema::table('shop_documents', function (Blueprint $table): void {
    $table->string('disk', 32)->default('public')->after('file_path');
});

Schema::table('users', function (Blueprint $table): void {
    $table->string('valid_id_disk', 32)->default('public')->after('valid_id_path');
});
```

Do not move files in a schema migration. Do not add checksum/version/expiration columns in Phase 0. Add raw storage paths to `$hidden`. If repository conventions require the disk fields in the existing explicit `$fillable` arrays, treat that only as an internal persistence detail: no Form Request, controller validation rule, DTO, or mass-assigned client payload may expose `disk` or `valid_id_disk`.

- [ ] **Step 4: Run the schema/model tests**

Run the command from Step 2.

Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add -- database/migrations/2026_08_12_000001_add_storage_disks_to_sensitive_documents.php app/Models/ShopDocument.php app/Models/User.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
git commit -m "security: track sensitive document storage disks"
```

## Task 5: Store New Sensitive Files Privately and Deliver Them Through Scoped Routes

**Files:**

- Create: `app/Http/Controllers/PrivateSensitiveDocumentController.php`
- Extend: `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ShopRegistrationController.php`
- Modify: `app/Http/Controllers/ShopOwnerAuthController.php`
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php`
- Modify: `app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `app/Actions/ShopOwner/SubmitShopOwnerUpgradeRequest.php`
- Modify: `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx`
- Use: `app/Services/PrivilegedAudit.php`

- [ ] **Step 1: Write failing upload/storage tests**

With `Storage::fake('local')` and `Storage::fake('public')`, prove:

- new full shop registration writes each `ShopDocument` to `local` and stores `disk=local`;
- malicious `disk` and `valid_id_disk` request values cannot change the trusted server-selected `local` disk;
- rejected-shop resubmission stores and verifies the new local file, commits the database reference change, and only then deletes the old file from its recorded disk;
- a resubmission database failure preserves the old row and old file and removes only the newly stored orphan;
- new customer registration writes the Valid ID to `local` and stores `valid_id_disk=local`;
- upgrade-request reuse reads the source document from `$source->disk`, not a hard-coded public disk.

Do not redesign replacement/version semantics here; Phase 6 will make document rows immutable.

- [ ] **Step 2: Write failing private-access and payload tests**

Cover these paths:

| Actor | Resource | Expected |
|---|---|---|
| Unauthenticated | Any private route | Login redirect/401 |
| Admin | Pending/rejected registration document | 200 |
| Admin | Approved registered-shop document outside operational scope | 404 |
| Super Admin | Approved registered-shop document | 200 |
| Privileged actor | Document under a different `{shopOwner}` | 404 |
| Admin/Super Admin | Customer Valid ID within account scope | 200 |
| Shop Owner | Own document | 200 |
| Shop Owner | Another owner's document | 404 |
| Valid signed resubmission link | Matching rejected-owner document | 200 |
| Expired/tampered signed link | Document | 403 |

Also assert:

- registration, shop-details, user-management, and owner-resubmission payloads contain route URLs but no `file_path`, `valid_id_path`, `storage/app`, or `/storage/` value;
- a missing file returns `404` and does not produce a success access audit;
- a successful response creates the mandatory access audit before returning content;
- replacing `PrivilegedAudit` in the container with a throwing test double results in `500` and no document bytes;
- headers include `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`;
- only server-side content inspection plus an allowlisted matching extension may classify a file as safe JPEG/PNG/PDF; client MIME and filenames are ignored;
- HTML, SVG, executable-like, mismatched, or unknown content/extension combinations use `application/octet-stream` and attachment disposition;
- filenames are generated from document type/record ID plus an allowlisted extension, never from stored path or client filename.

Privileged access must use `PrivilegedAudit`. Authenticated Shop Owner and signed resubmission access must also write synchronously to Spatie `activity_log` with a fixed event schema appropriate to that actor/authority; do not force non-privileged actors into a fake Super Admin identity, and do not log signed-link parameters or tokens.

- [ ] **Step 3: Run focused tests and verify failure**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
```

Expected: FAIL because uploads still use `public`, payloads expose raw/public URLs, and private routes do not exist.

- [ ] **Step 4: Switch all new writers and disk-aware reuse logic**

For each new upload:

```php
$path = $file->store('shop_documents', 'local');

ShopDocument::create([
    // existing fields
    'file_path' => $path,
    'disk' => 'local',
]);
```

For customer IDs, write to `local` and persist `valid_id_disk=local`. Assign both disk values from trusted application code after validation; never read them from request input. During mixed-state rollout, readers accept only the explicit `local` and `public` disk allowlist and fail closed for unsupported stored values.

Every replacement/delete/read in `ShopOwnerAuthController` must use the row's recorded disk. Resubmission ordering is mandatory: store and verify the new local file, perform the database reference mutation in a transaction, commit, then delete the old file from its recorded disk. If the database mutation fails, the old reference and old file remain authoritative and only the new orphan may be removed. `SubmitShopOwnerUpgradeRequest` must use `Storage::disk($source->disk)` and reject unsupported disk names.

Never call `Storage::url()` for these records.

- [ ] **Step 5: Add canonical scoped routes**

Use implicit scoped bindings where possible and explicit parent checks in the controller as defense in depth:

```php
Route::get('/shop-owners/{shopOwner}/documents/{document}', ...)
    ->scopeBindings()
    ->middleware('privileged.capability:review_registrations')
    ->name('shop-documents.show');

Route::get('/users/{user}/valid-id', ...)
    ->middleware('privileged.capability:intervene_accounts')
    ->name('users.valid-id.show');
```

Use `{document}` so scoped binding resolves the existing `ShopOwner::documents()` relationship. Add a Shop Owner-authenticated own-document route and a temporary signed resubmission-document route. The signed route must bind both owner and document, require the rejected resubmission source state, and expire with the parent resubmission link.

- [ ] **Step 6: Implement fail-closed streaming**

Controller order is mandatory:

```text
resolve actor/authority
→ verify capability and operational scope
→ verify parent/document relationship
→ validate supported recorded disk and file existence
→ inspect file content and determine safe MIME/disposition/filename
→ synchronously write access audit
→ create filesystem response
```

Derive MIME from server-side content inspection (for example, filesystem/Fileinfo-backed detection), never from the client MIME, filename, or stored extension alone. Compare that result with an allowlisted extension derived from known document metadata. Any unknown, risky, or mismatched content/extension combination returns `application/octet-stream` with attachment disposition.

For regular Admin, operational scope is a registration case (`pending` or `rejected`). Super Admin may additionally access approved/suspended registered-shop documents for legitimate administration. Do not infer scope from a client parameter. Use `403` for a missing capability and `404` for an authenticated actor requesting a document outside their authorized object scope, without a success access audit.

- [ ] **Step 7: Replace exposed paths with route URLs**

Server payloads return only IDs, types, statuses, and generated route URLs. Rename the user-management prop to `validIdUrl` and remove `buildDocumentUrl()` from `SuperAdminUserManagement.tsx`.

- [ ] **Step 8: Run focused backend tests**

```powershell
php artisan test tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php
```

Expected: PASS. Existing business-upgrade private evidence behavior remains intact.

- [ ] **Step 9: Run the focused frontend test suite and production build**

```powershell
pnpm run test:frontend -- resources/js/Pages/superAdmin
pnpm run build
```

Expected: PASS/build success. Do not claim TypeScript type-checking; the repository has no committed type-check script.

- [ ] **Step 10: Commit**

```powershell
git add -- app/Http/Controllers/PrivateSensitiveDocumentController.php routes/web.php app/Http/Controllers/ShopRegistrationController.php app/Http/Controllers/ShopOwnerAuthController.php app/Http/Controllers/UserController.php app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php app/Http/Controllers/SuperAdminController.php app/Actions/ShopOwner/SubmitShopOwnerUpgradeRequest.php resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
git commit -m "security: make sensitive documents private"
```

## Task 6: Migrate Existing Sensitive Files Copy-First

**Files:**

- Create: `app/Console/Commands/MigrateSensitiveDocumentsToPrivateStorage.php`
- Create: `tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php`

- [ ] **Step 1: Write failing command tests**

Cover both `ShopDocument` and `User::valid_id_path` records:

1. `--dry-run` reports counts but changes neither files nor metadata;
2. migration copies public bytes to the same private relative path, verifies size and SHA-256, updates disk metadata transactionally, verifies the update, then removes the public source;
3. rerunning after success reports already-private and remains successful;
4. matching public duplicates for already-private rows are verified and removed;
5. conflicting public/private bytes are preserved and produce a non-zero exit;
6. missing files produce a non-zero exit and no metadata guess;
7. a private-write/checksum failure preserves public bytes and public metadata;
8. a metadata-update failure preserves or restores a usable public source and reports failure;
9. `--restore-public` copies and verifies private bytes, switches metadata back to public, and keeps the private copy until rollback is complete;
10. `--chunk=1` proves bounded iteration and resumability.

- [ ] **Step 2: Run the command tests and verify failure**

```powershell
php artisan test tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php
```

Expected: FAIL because the command does not exist.

- [ ] **Step 3: Implement one focused resumable command**

Signature:

```text
security:migrate-sensitive-documents-private
    {--dry-run : Report without writes}
    {--restore-public : Prepare verified public copies and metadata for application rollback}
    {--chunk=100 : Records per batch}
```

Use the existing `MoveHandoffProofsToPrivate` behavior as the local precedent, with these stronger per-record steps:

```text
public metadata
→ source exists
→ copy bytes to local
→ verify size + SHA-256
→ lock row and recheck path/disk
→ update disk metadata
→ verify fresh row
→ remove public source
→ verify public source absent
```

For already-local metadata with a remaining public duplicate, compare hashes before removal. Never overwrite a conflicting private file. Never classify a missing path by guess. Return failure when `conflicts`, `missing`, `failed`, or `public_duplicates_remaining` is non-zero.

Keep counts separate for shop documents and customer IDs plus totals. Output IDs and safe categories only; never print filenames/paths, hashes, or document contents.

- [ ] **Step 4: Run command tests and inspect help**

```powershell
php artisan test tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php
php artisan help security:migrate-sensitive-documents-private
```

Expected: PASS; help shows dry-run, restore, and chunk options only.

- [ ] **Step 5: Commit**

```powershell
git add -- app/Console/Commands/MigrateSensitiveDocumentsToPrivateStorage.php tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php
git commit -m "security: migrate sensitive documents privately"
```

## Task 7: Remove Routine Hard Deletion End-to-End

**Files:**

- Create: `tests/Feature/SuperAdmin/HardDeleteContainmentTest.php`
- Create: `resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx`

- [ ] **Step 1: Write failing backend containment tests**

Assert:

- route names `admin.admins.delete`, `admin.shops.delete`, and `admin.users.delete` are absent;
- DELETE requests to `/admin/admins/{id}`, `/admin/shops/{id}`, and `/admin/users/{id}` return `404` or `405`;
- target records and representative dependent records still exist;
- `SuperAdminController` no longer exposes `deleteAdmin`, `deleteShop`, or `deleteUser` methods.

Do not replace these routes with archive endpoints in Phase 0.

- [ ] **Step 2: Write failing frontend tests**

Render `AdminManagement` and `RegisteredShops` with a Super Admin page prop and target rows. Assert there is no permanent-delete button/title/text and that the mocked Inertia router never receives `delete()`.

- [ ] **Step 3: Run tests and verify failure**

```powershell
php artisan test tests/Feature/SuperAdmin/HardDeleteContainmentTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx
```

Expected: FAIL because routes, methods, and controls exist.

- [ ] **Step 4: Remove only the destructive paths**

Delete the three route declarations, three controller methods, the administrator/shop delete handlers, confirmation dialogs, trash icons used only by those controls, and the buttons. Preserve suspend/activate behavior. Remove imports only when references confirm they became unused.

- [ ] **Step 5: Run focused tests and structural search**

```powershell
php artisan test tests/Feature/SuperAdmin/HardDeleteContainmentTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx
rg -n "deleteAdmin|deleteShop|deleteUser|admin\.admins\.delete|admin\.shops\.delete|admin\.users\.delete|delete permanently" app routes resources/js tests
```

Expected: tests PASS; `rg` returns no runtime implementation references (test assertions may name forbidden symbols).

- [ ] **Step 6: Commit**

```powershell
git add -- routes/web.php app/Http/Controllers/SuperAdminController.php resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx tests/Feature/SuperAdmin/HardDeleteContainmentTest.php resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx
git commit -m "security: remove routine privileged hard deletes"
```

## Task 8: Finalize GET-Only Registration Compatibility

**Files:**

- Extend: `tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php`
- Modify: `routes/web.php`
- Modify only if route URL props require it: `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx`

- [ ] **Step 1: Extend route ownership regression tests**

Assert:

- canonical named routes exist once under `/admin` for registration index, approval, and rejection;
- `/superAdmin/shop-owner-registration-view` is GET-only and redirects to the canonical index;
- old `/superAdmin/shop-owner-registration/{id}/approve` and `/reject` POSTs return `404`/`405` and do not change state;
- route collection contains exactly one mutation route for each registration decision;
- both canonical mutations require `review_registrations`;
- no legacy method other than the single GET compatibility route is registered.

- [ ] **Step 2: Run the route tests and verify failure**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php --filter=registration_route
```

Expected: authorization and mutation-ownership assertions from Task 1 remain PASS. New compatibility assertions may FAIL because duplicate legacy GET declarations have not yet been reduced to one redirect. If a legacy mutation exists, stop and repair the Task 1 containment regression before continuing.

- [ ] **Step 3: Consolidate legacy GET compatibility**

Keep the canonical `/admin` mutation ownership established in Task 1 unchanged. Remove duplicate legacy registration GET declarations and define one named, authenticated redirect to the canonical index; do not proxy POST/PUT/PATCH/DELETE. Treat any reintroduced legacy mutation as a security regression, not deferred cleanup.

Do not perform broad route/controller cleanup here. Remaining harmless duplicate GET structure belongs to Phase 7.

- [ ] **Step 4: Run tests and route inspection**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php
php artisan route:list --path=shop-owner-registration --except-vendor
```

Expected: PASS; one canonical approval and one canonical rejection mutation appear.

- [ ] **Step 5: Commit**

```powershell
git add -- routes/web.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx
git commit -m "refactor: finalize registration GET compatibility"
```

Only add the TSX file if it actually changed.

## Task 9: Write the Deployment and Rollback Runbook

**Files:**

- Create: `docs/runbooks/super-admin-phase-0-containment.md`

- [ ] **Step 1: Document preflight and stop conditions**

Include:

- verified database and public/private storage backups;
- confirmed console access by an authorized operator;
- confirmed production session driver, configured session table, and permission to clear all database-backed sessions;
- verified from the deployed framework/application behavior that session rows cannot reliably identify the `super_admin` guard; do not target `sessions.user_id`;
- count of active Super Admins and identification of the recoverable account;
- counts of `ShopDocument` and `User` Valid ID records by disk/path presence;
- maintenance window because public-file removal, credential rotation, and the deliberate global database-session logout are security-sensitive;
- stop on missing/conflicting files, failed audit writes, unsupported session driver, no recoverable Super Admin, or failed focused tests.

- [ ] **Step 2: Document the exact forward sequence**

```text
1. Put application in maintenance mode.
2. Back up DB and both storage roots.
3. Deploy additive disk metadata + mixed-disk readers + private writers.
4. Run database migrations.
5. Rotate the compromised seeded credential interactively; this clears all database sessions.
6. Confirm the old credential fails, the replacement succeeds, the configured session table is empty, and the command/audit correlation IDs match.
7. Run sensitive-document migration with --dry-run.
8. Resolve every missing/conflict result; do not guess.
9. Run the real migration in bounded chunks.
10. Rerun until already-private is stable and no public duplicate remains.
11. Run focused tests/route inspection/storage reconciliation.
12. Exit maintenance mode and perform both-role browser checks.
```

Do not place any password in the runbook, shell history, environment, or command examples.

- [ ] **Step 3: Document rollback with a security floor**

Rollback order:

1. re-enter maintenance mode;
2. run `security:migrate-sensitive-documents-private --restore-public` and require zero missing/conflicts/failures;
3. verify public copies before rolling application code/schema back;
4. keep private copies until rollback validation completes;
5. never restore the known password, hard-delete routes, or missing capability checks;
6. treat credential rotation as forward-only;
7. do not restore cleared sessions; affected users must authenticate again after maintenance;
8. if rollback would reintroduce public exposure or destructive routes, stop and roll forward with a corrective patch instead.

- [ ] **Step 4: Verify runbook command names against Artisan**

```powershell
php artisan help super-admin:rotate-compromised-credential
php artisan help security:migrate-sensitive-documents-private
```

Expected: both documented commands exist and options match exactly.

- [ ] **Step 5: Commit**

```powershell
git add -- docs/runbooks/super-admin-phase-0-containment.md
git commit -m "docs: add phase zero containment runbook"
```

## Task 10: Integrated Review and Verification Gate

**Files:** All Phase 0 files above.

- [ ] **Step 1: Run the focused Phase 0 backend suite**

```powershell
php artisan test tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php tests/Feature/SuperAdmin/KnownCredentialContainmentTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php tests/Feature/SuperAdmin/HardDeleteContainmentTest.php
```

Expected: PASS, zero failures.

- [ ] **Step 2: Run adjacent regression tests**

```powershell
php artisan test tests/Feature/BusinessScaling/ShopModuleInitialApprovalTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeReviewTest.php tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php tests/Feature/AdminPremiumPlanManagementTest.php
```

Expected: PASS. Capability middleware may require fixtures to use the correct role; update tests, not authorization, when the fixture contradicts the approved matrix.

- [ ] **Step 3: Run frontend tests and build**

```powershell
pnpm exec vitest run resources/js/Pages/superAdmin/__tests__/PhaseZeroDestructiveControls.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/BusinessUpgradeRequests.test.tsx
pnpm run test:frontend
pnpm run build
```

Expected: PASS and production build succeeds.

- [ ] **Step 4: Run route and structural checks**

```powershell
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
rg -n "admin123|deleteAdmin|deleteShop|deleteUser|/storage/.*(document|valid)|asset\('storage/" app database/migrations routes resources/js
git diff --check
```

Expected:

- no known migration credential remains in current source;
- no routine account hard-delete runtime path remains;
- no sensitive document payload constructs a public URL;
- legacy registration mutation routes are absent;
- diff check exits zero.

Review any broad `/storage/` result manually; product images and other explicitly public media are outside Phase 0 and must not be mass-converted.

- [ ] **Step 5: Run broader backend and dependency checks**

```powershell
composer test
composer audit
```

Expected: complete Laravel suite passes; dependency audit reports no unresolved vulnerability relevant to the deployment. If either cannot run, record the exact reason—never infer success.

- [ ] **Step 6: Perform sequential review stack**

Record each result:

1. **simplify / ponytail:** no configurable RBAC, no permission UI, no generic document manager, no temporary audit system, no new dependency.
2. **standards:** Laravel conventions, explicit fillable fields, route middleware ordering, scoped bindings, focused controller, and existing command/test patterns.
3. **spec:** every Phase 0 acceptance criterion maps to a test or deployment check; no Phase 1+ scope leaked in.
4. **clean-code TypeScript:** removed handlers/imports are not left behind; server URL props are named accurately; no new `any` is added.
5. **code splitting:** N/A—no heavy or conditional frontend dependency is introduced.
6. **gauge improvements:** record before/after counts for known credential rows, public sensitive files, destructive routes, duplicate registration mutations, and restricted routes with capability middleware.
7. **security:** verify IDOR, audit-failure denial, MIME/disposition, path secrecy, CSRF, session invalidation, migration conflicts, and rollback floor.
8. **reuse/dead-code:** existing local disk, Spatie activity log, migration command style, and route helpers reused; orphaned delete code removed only where created obsolete by this phase.

The repository operating model does not permit an unapproved subagent review. Perform this plan review sequentially in the main agent unless the user explicitly authorizes the optional bounded read-only review gate.

- [ ] **Step 7: Browser verification**

Use `webapp-testing` when the local application is runnable:

- Admin can review a pending registration and view its private document.
- Admin cannot open administrator management, plan controls, subscription interventions, or decide an appeal.
- Super Admin can access those restricted areas.
- Direct copied `/storage/shop_documents/...` and `/storage/valid_ids/...` URLs fail after migration.
- Cross-shop document URL tampering fails.
- Permanent-delete controls are absent from administrator and registered-shop pages.
- Suspend/reactivate controls still work.

- [ ] **Step 8: Commit verification-only adjustments, if any**

If verification required source changes, rerun every affected narrow check before committing. Do not create an empty commit.

## Phase 0 Completion Evidence

The implementation handoff must report:

- commit IDs for each completed task;
- exact test/build/audit commands and pass/fail counts;
- route inventory showing capability middleware and unique registration mutations;
- pre/post counts of sensitive records by disk;
- migration command totals: scanned, copied, already private, duplicates removed, conflicts, missing, failed;
- confirmation that the seeded credential was rotated without exposing it and that old sessions were invalidated;
- confirmation that no public sensitive duplicate remains before maintenance mode ends;
- confirmation that administrator/shop/user hard-delete routes and controls are absent;
- any skipped browser/deployment check with the exact blocker;
- unrelated working-tree changes that were preserved.

Phase 0 is not complete merely because the UI hides controls or a happy-path document request succeeds.
