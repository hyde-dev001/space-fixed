# Privileged Setup Session-Independent Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a pending first Super Admin create a password and enter MFA setup without depending on session data written by the setup-token exchange request.

**Architecture:** The exchange endpoint validates the fragment bearer and returns a short-lived Laravel-encrypted completion proof containing token/subject IDs, setup purpose, and bounded timestamps. The React page keeps that proof only in memory and sends it with the password; the controller validates it and reuses the existing locked, atomic database-token consumption path. Exchange-time session mutation is removed, while the normal authenticated setup session is still established after password completion.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent transactions, Laravel Crypt, Inertia 2, React 18, TypeScript 5.7, Vitest, PHPUnit.

---

## File map

- Create `app/Services/PrivilegedSetupProofService.php`: issue and validate opaque completion proofs; no HTTP or database responsibility.
- Create `tests/Unit/Services/PrivilegedSetupProofServiceTest.php`: verify proof round-trip, expiry, tampering, and purpose validation without HTTP coupling.
- Modify `app/Http/Requests/Privileged/CompleteSetupPasswordRequest.php`: validate the proof as required credential input alongside the password.
- Modify `app/Http/Controllers/PrivilegedSetupController.php`: return the proof from exchange, stop storing exchange authorization in session, and authorize completion from the proof.
- Modify `resources/js/Pages/superAdmin/Auth/PrivilegedAuthShell.tsx`: retain an optional exchange proof only in hook state.
- Modify `resources/js/Pages/superAdmin/Auth/PrivilegedSetup.tsx`: submit the proof and display specific setup-link errors.
- Modify `tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php`: cover session-independent completion, proof validation, one-time use, and secret-safe failures.
- Modify `resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx`: cover in-memory proof submission and token error display.
- Modify `docs/ai-learning-log.md`: record the durable rule that pre-auth credential continuation must not rely on a cross-request session rotation when an opaque bounded proof can preserve the boundary.

### Task 1: Encrypted setup-proof boundary

**Files:**
- Create: `app/Services/PrivilegedSetupProofService.php`
- Create: `tests/Unit/Services/PrivilegedSetupProofServiceTest.php`

- [ ] **Step 1: Write failing proof-service tests**

Add focused unit tests that issue a proof from integer token/subject IDs and a future database-token expiry, then assert `authorization()` returns the same IDs. Add tests proving a modified proof, expired proof, and an otherwise authenticated payload with the wrong purpose throw `InvalidArgumentException`.

Use time control for expiry:

```php
Carbon::setTestNow('2026-08-13 12:00:00');
$proof = app(PrivilegedSetupProofService::class)->issue(
    tokenId: 10,
    subjectId: 20,
    tokenExpiresAt: now()->addDay(),
);

Carbon::setTestNow(now()->addMinutes(16));

$this->expectException(InvalidArgumentException::class);
app(PrivilegedSetupProofService::class)->authorization($proof);
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php artisan test tests/Unit/Services/PrivilegedSetupProofServiceTest.php
```

Expected: FAIL because `PrivilegedSetupProofService` does not exist.

- [ ] **Step 3: Implement the minimal proof service**

Create a final service using Laravel's existing `Crypt` facade and `JSON_THROW_ON_ERROR`:

```php
final class PrivilegedSetupProofService
{
    public function issue(int $tokenId, int $subjectId, CarbonInterface $tokenExpiresAt): string
    {
        $issuedAt = now()->timestamp;
        $expiresAt = min(
            $tokenExpiresAt->getTimestamp(),
            now()->addMinutes((int) config('privileged_security.token_authorization_minutes', 15))->timestamp,
        );

        return Crypt::encryptString(json_encode([
            'token_id' => $tokenId,
            'subject_id' => $subjectId,
            'purpose' => 'setup',
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{token_id: int, subject_id: int} */
    public function authorization(string $proof): array
    {
        // Decrypt, JSON-decode, strictly validate positive integer IDs,
        // setup purpose, issued_at <= now, expires_at > now, and
        // expires_at <= issued_at + configured authorization lifetime.
        // Convert every parse/decrypt/shape failure to InvalidArgumentException
        // without including the proof in the exception message.
    }
}
```

Keep the service independent of sessions, requests, models, and database queries. Do not add a dependency or custom cryptography.

- [ ] **Step 4: Run the proof tests and verify GREEN**

Run the Step 2 command.

Expected: PASS for proof round-trip, tamper, expiry, and purpose tests.

- [ ] **Step 5: Commit the proof boundary**

```powershell
git add -- app/Services/PrivilegedSetupProofService.php tests/Unit/Services/PrivilegedSetupProofServiceTest.php
git commit -m "feat: add privileged setup completion proof"
```

### Task 2: Make password completion independent of exchange session state

**Files:**
- Modify: `app/Http/Requests/Privileged/CompleteSetupPasswordRequest.php`
- Modify: `app/Http/Controllers/PrivilegedSetupController.php`
- Test: `tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php`

- [ ] **Step 1: Write the failing lost-session regression**

Replace the numeric-session compatibility test with a production regression that:

1. exchanges a fresh setup bearer;
2. captures `completion_proof`;
3. asserts `privileged_setup_authorization` is absent from the session;
4. starts password completion with no exchange authorization in session;
5. submits the proof and valid password;
6. expects redirect to `/admin/mfa/setup`, password/MFA persistence, used token, and setup-stage authentication.

Update every existing successful/failure completion request in this test file to submit the exchanged proof. Keep the second-submit assertion to prove one-time use.

- [ ] **Step 2: Run the lost-session test and verify RED**

Run:

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php artisan test tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php --filter='setup_password_completes_without_exchange_session_authorization'
```

Expected: FAIL because the controller still reads `privileged_setup_authorization` from session.

- [ ] **Step 3: Validate the completion proof input**

Add to `CompleteSetupPasswordRequest::rules()`:

```php
'completion_proof' => ['required', 'string', 'max:2048'],
```

Add safe messages for required/string/max that say the setup link is invalid or expired. Do not echo submitted data.

- [ ] **Step 4: Replace the session handoff in the controller**

Inject `PrivilegedSetupProofService` into `PrivilegedSetupController`.

In `exchange()`:

- keep raw bearer authorization and audit behavior;
- issue a proof bounded by `$authorization['expires_at']`;
- remove `session()->regenerate()`, `session()->put('privileged_setup_authorization', ...)`, and manual `session()->save()`;
- return `authorized` and `completion_proof` as no-store JSON through the existing route middleware.

In `completePassword()`:

```php
try {
    $authorization = $this->setupProofs->authorization(
        (string) $request->validated('completion_proof'),
    );
} catch (InvalidArgumentException $exception) {
    Log::warning('Privileged setup completion proof rejected', [
        'correlation_id' => $request->attributes->get('privileged_audit_correlation_id'),
        'exception_class' => $exception::class,
    ]);

    return $this->invalidSetupLink($request);
}
```

Then pass the proof IDs to the unchanged `consumeAuthorized()` transaction. Delete `setupAuthorization()` and `positiveSessionInteger()` after confirming no references remain. Keep post-password session regeneration, `super_admin` login, setup-stage fields, redirect, and all audit rollback behavior.

- [ ] **Step 5: Run the backend setup suite and verify GREEN**

Run:

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php artisan test tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php
php artisan test tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php
```

Expected: PASS; proof-less, modified, expired, wrong-purpose, and reused proof attempts do not mutate credentials or token state.

- [ ] **Step 6: Commit the controller integration**

```powershell
git add -- app/Http/Requests/Privileged/CompleteSetupPasswordRequest.php app/Http/Controllers/PrivilegedSetupController.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php
git commit -m "fix: remove privileged setup session handoff"
```

### Task 3: Carry the proof only in React memory

**Files:**
- Modify: `resources/js/Pages/superAdmin/Auth/PrivilegedAuthShell.tsx`
- Modify: `resources/js/Pages/superAdmin/Auth/PrivilegedSetup.tsx`
- Test: `resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx`

- [ ] **Step 1: Write failing frontend tests**

Update the setup exchange mock to return:

```ts
{ data: { authorized: true, completion_proof: 'opaque-completion-proof' } }
```

Add one test that fills a valid password and asserts:

```ts
expect(routerPostMock).toHaveBeenCalledWith(
  '/admin/setup/complete',
  {
    completion_proof: 'opaque-completion-proof',
    password: 'LongEnough-Setup1!',
    password_confirmation: 'LongEnough-Setup1!',
  },
  expect.any(Object),
);
```

Also invoke the captured `onError` callback with `{ token: 'The setup link is invalid or expired.' }` and assert the specific text appears. Assert the raw bearer and proof are absent from URL, local storage, session storage, and rendered text.

- [ ] **Step 2: Run the frontend test and verify RED**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx
```

Expected: FAIL because the hook discards response data and setup submission omits the proof/token error.

- [ ] **Step 3: Implement minimal hook and page changes**

Extend `BearerExchangeState` with `completionProof?: string`. In the exchange success callback, copy only a non-empty string `response.data.completion_proof` into React state; do not write it anywhere else.

In `PrivilegedSetup`:

- require `exchange.completionProof` before rendering the password form;
- include `completion_proof` in the submit payload;
- include `token` and `completion_proof` before the generic fields in `firstError()` priority.

Do not change password-reset behavior: its exchange response may omit a completion proof, and `PrivilegedResetPassword` continues using only `exchange.authorized`.

- [ ] **Step 4: Run focused and full frontend tests**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx
pnpm run test:frontend
```

Expected: PASS. Existing reset, MFA, recovery, reauthentication, and invitation tests remain green.

- [ ] **Step 5: Commit the frontend integration**

```powershell
git add -- resources/js/Pages/superAdmin/Auth/PrivilegedAuthShell.tsx resources/js/Pages/superAdmin/Auth/PrivilegedSetup.tsx resources/js/Pages/superAdmin/Auth/__tests__/PrivilegedAuthFlows.test.tsx
git commit -m "fix: submit privileged setup completion proof"
```

### Task 4: Security review, documentation, and deployment verification

**Files:**
- Modify: `docs/ai-learning-log.md`
- Verify: all files changed since `origin/solespace-b`

- [ ] **Step 1: Run the required sequential review stack**

Record:

- simplify/ponytail: no dependency, migration, persistent client storage, or duplicate token-consumption path;
- Standards: Laravel Form Request, existing service/controller/audit conventions, focused React state;
- Spec: every approved proof field, lifetime, storage prohibition, and fresh-link requirement;
- TypeScript/React: typed optional proof, no assertions/`any`, no extra render subscription;
- code splitting: N/A, no new module weight;
- gauge: lost-session regression changes from fail to pass; bundle-size improvement not measured;
- security: tamper, expiry, purpose, subject, one-time use, CSRF, no-store, audit rollback, and secret-safe logs;
- verification-before-completion: fresh commands below must pass before any completion claim.

- [ ] **Step 2: Update durable learning**

Add a concise entry to `docs/ai-learning-log.md`: a pre-auth continuation credential should use a bounded authenticated proof when production session rotation cannot reliably bridge requests; the database token must remain authoritative and one-time.

- [ ] **Step 3: Run backend verification**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php artisan test tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php tests/Feature/SuperAdmin/PrivilegedIdentitySchemaTest.php
php artisan test tests/Feature/SuperAdmin
php artisan route:list --path=admin/setup --except-vendor
php -l app/Services/PrivilegedSetupProofService.php
php -l app/Http/Requests/Privileged/CompleteSetupPasswordRequest.php
php -l app/Http/Controllers/PrivilegedSetupController.php
```

Expected: all tests/checks exit `0`; repository no-`.env` warnings may remain documented, with no failures.

- [ ] **Step 4: Run frontend/build verification**

```powershell
pnpm run test:frontend
pnpm run build
```

Expected: both exit `0`. Review generated `public/build` changes according to `docs/git-workflow.md` and include the fresh build required by deployment.

- [ ] **Step 5: Run final hygiene checks**

```powershell
rg -n "privileged_setup_authorization|completion_proof" app resources/js tests docs
git diff --check
git status --short
git diff --name-status origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
```

Confirm no proof/token/password logging, persistent browser storage, stale session helper, dead import, unrelated change, or unexpected deletion.

- [ ] **Step 6: Commit documentation/build output and push**

```powershell
git add -- docs/ai-learning-log.md public/build
git commit -m "docs: record resilient privileged setup handoff"
git fetch origin --prune
git rebase origin/solespace-b
git push -u origin super-admin-phase-0-containment
```

If rebase changes the tested tree, rerun the focused backend test, focused frontend test, build, and `git diff --check` before pushing. Never force-push.

### Task 5: Production handoff

**Files:**
- Reference: `docs/superpowers/specs/2026-08-13-privileged-setup-session-independent-completion-design.md`
- Reference: `docs/git-workflow.md`

- [ ] **Step 1: Deploy matching backend and build**

Deploy the pushed `super-admin-phase-0-containment` commit, rebuild/clear Laravel caches through the host's normal release command, and restart long-lived PHP workers if applicable.

- [ ] **Step 2: Replace the pending invitation**

From the deployed release, run the interactive command:

```powershell
php artisan super-admin:bootstrap
```

Confirm replacement of the sole pending platform account and use only the newly delivered setup link.

- [ ] **Step 3: Verify the real flow**

In a private browser window:

```text
fresh link -> token exchange -> password creation -> MFA enrollment -> recovery-code acknowledgement -> privileged dashboard
```

Confirm the account becomes `active` and MFA-complete, and that the setup bearer/proof cannot be reused. If a request fails, capture only its status and `X-Correlation-ID`; do not capture credentials, cookies, request payloads, or bearer values.
