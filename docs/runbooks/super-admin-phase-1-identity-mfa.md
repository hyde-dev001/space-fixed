# SoleSpace Phase 1 Super Admin Identity and MFA Runbook

This runbook covers deployment, first-account bootstrap, forced MFA enrollment, recovery, and rollback for the Phase 1 privileged identity controls. It assumes the Phase 0 containment changes are already deployed.

## Operating rules

- Treat setup, reset, MFA, recovery-code, role, lifecycle, and platform-security actions as privileged security operations.
- Never place a bearer token in a URL path, query string, redirect, Inertia prop, session, audit record, queue payload, log, or exception message. Email links carry the bearer only in the URL fragment (`#token=...`); the browser removes it before the exchange request.
- Do not backfill fake MFA confirmation, generate secrets or recovery codes offline, restore invalidated sessions, or create a password-only operational path.
- Recovery codes are displayed once, returned only in the immediate no-store JSON response, and kept in browser memory until the operator acknowledges that they were saved.
- A successful reauthentication authorizes sensitive work for 15 minutes and is bound to the current administrator security version. Reauthentication never authorizes a recovery code as a TOTP substitute.

The named operator owns the maintenance window, evidence capture, and escalation decision. A second operator should independently verify the account and MFA state before high-risk changes.

## Preflight and stop conditions

Complete every item before enabling privileged traffic:

1. Confirm the deployed revision includes the Phase 0 tip and the Phase 1 focused tests pass.
2. Take a current database and storage backup. Test the restore procedure against a disposable target and record its result.
3. Confirm `APP_KEY` is present, stable, and available to every web, queue, and CLI process. Do not rotate it during rollout; encrypted TOTP secrets depend on it.
4. Confirm the canonical admin origin is HTTPS and matches the configured application URL. Do not send setup or reset links through an alternate origin.
5. Confirm the database session driver and session table are available. Physical cleanup of invalidated privileged sessions depends on the configured session store.
6. Verify SMTP, the queue connection, an active worker, and failed-job visibility. Send a non-production or test-recipient message through the encrypted queued mail path.
7. Confirm the `iconv`, `openssl`, and `xmlwriter` PHP extensions are available on the application and queue hosts.
8. Confirm platform time synchronization is healthy and materially correct using the host or container NTP/time-sync tooling. Do this before enrollment or challenge traffic is enabled; TOTP is time-sensitive.
9. Confirm at least one named operator has a recoverable current Super Admin password and that the incident escalation path is staffed.
10. Run `composer audit --locked` against the deployed lockfile. Stop on any unresolved advisory affecting production dependencies; if release policy permits an exception, record the advisory, reachability, compensating controls, owner, and expiry before proceeding.
11. Announce the maintenance window and record the operator, reviewer, deployment revision, backup identifiers, and start time.

Stop immediately and restore the maintenance boundary if any item fails, if mail/queue encryption or delivery cannot be verified, if system time is unhealthy or materially incorrect, if a status migration is unsupported, if no recoverable account exists, or if any Phase 1 security test fails.

## Fresh-install bootstrap

Run this sequence on a new installation:

```text
migrate
→ run interactive super-admin:bootstrap
→ confirm exactly one pending_setup account and one queued setup mail
→ open the fragment-bearing setup link over HTTPS
→ verify the browser requests only the clean token-free setup URI
→ exchange the fragment bearer by POST and continue on the clean URL
→ set the password, enroll TOTP, and save/acknowledge recovery codes
→ verify the account is active and MFA-complete
→ verify a second bootstrap attempt is refused
```

The bootstrap command must be interactive and must not accept a secret-bearing CLI argument. Inspect the pending account and queue handoff without printing the raw token. If setup mail fails after the account transaction, leave the account resumable and use the supported resend/recovery procedure; do not create a second bootstrap account.

After acknowledgement, verify all of the following:

- the account is `active` and `hasCompletedMfaSetup()` is true;
- the registered privileged session has the complete stage and current security version;
- the recovery-code count is the server-reported count;
- the audit success event exists without a password, TOTP secret, recovery plaintext, or bearer token;
- the setup bearer cannot be exchanged again.

## Existing-deployment forced enrollment

Use a maintenance window for the first rollout to an existing deployment:

```text
maintenance mode
→ deploy dependencies and application code
→ run additive security migrations
→ verify the admin route middleware inventory
→ verify pre-Phase-1 cookies are denied without a complete stage and registry row
→ operator signs in with the current password
→ complete forced MFA enrollment and recovery acknowledgement
→ verify the complete registered session
→ verify a second recoverable Super Admin before high-risk changes
→ run focused browser and security checks
→ exit maintenance mode
```

Existing active administrators remain active in the database but cannot operate until MFA enrollment is complete. Do not silently activate `pending_setup`, mark `mfa_confirmed_at`, or manufacture recovery codes. If an administrator loses the enrollment page before acknowledging codes, start the code-generation portion again so the previous unacknowledged set is replaced.

## Normal identity and recovery operations

### Invitations and pending setup

Invite with identity, contact, and role only. The target starts as `pending_setup` and creates their own password and TOTP enrollment from the one-time fragment link. A pending administrator may receive a resend; the old setup token is replaced. Mail handoff failure must not activate or delete the account.

For an inactive administrator, the supported activation flow returns the account to `pending_setup`, clears old MFA state, issues a fresh setup token, and queues a new setup message. It is not a direct password or MFA bypass.

### Password reset

The public forgot-password response is generic for every account state. Only applicable active accounts receive mail. The reset bearer is exchanged from the fragment, consumed once, and then removed from the browser URL. A reset changes the password and security version, invalidates other privileged sessions, and leaves existing MFA enrollment intact.

### Recovery-code regeneration

Recovery-code regeneration requires a recent password-plus-TOTP reauthentication. It returns the new plaintext codes and acknowledgement token directly as no-store JSON. The operator must copy, download, or print the codes and explicitly acknowledge them. The acknowledgement token is short-lived and purpose-bound; losing the page means generating a replacement set, not retrieving the old plaintext.

An exhausted count of zero does not mean MFA is disabled. Confirm the server-reported `mfa_complete` boolean and generate a new recovery set while MFA remains enabled.

### Reauthentication and high-risk actions

When a lifecycle, role, platform-MFA, own-password, own-MFA, or recovery-code mutation requires reauthentication:

1. Follow the redirect to the standalone reauthentication page.
2. Enter the current password and a newer six-digit TOTP code.
3. Confirm the page shows the 15-minute validity window.
4. Deliberately retry the original action after success; never auto-replay a mutation.

The retry destination must be a local `/admin/...` path. External destinations are replaced with the security page. A security-version change, logout, password reset, MFA reset, stage downgrade, or expiry invalidates the grant.

## Monitoring and evidence

During rollout and normal operations, monitor:

- failed login, MFA, setup, reset, reauthentication, and recovery acknowledgements;
- queue failures and mail delivery failures for setup/reset messages;
- repeated invalid bearer exchanges or rate-limit responses;
- audit writes and any mandatory-audit rollback;
- privileged session creation, invalidation, and cleanup;
- account status, role, MFA-complete state, and recovery-code count transitions.

Audit and application logs must contain event type, actor/target identifiers where appropriate, outcome, and safe request metadata only. They must never contain passwords, TOTP secrets, recovery plaintext, acknowledgement tokens, or bearer tokens. Preserve the deployment revision, test output, route inventory, migration status, queue check, and backup identifiers with the change record.

## Failure and recovery procedures

- **Setup or reset mail fails:** keep the account or reset state resumable, inspect the queue and failed-job record for secret leakage, correct delivery, and use the supported resend flow. Do not expose or manually copy a token into a console command.
- **A setup/reset link expires or is used:** request a new invitation/reset through the normal endpoint. Do not revive the old token.
- **Recovery acknowledgement is lost:** replace the unacknowledged recovery-code set and display the replacement once. Do not query storage for plaintext codes.
- **Authenticator is lost but recovery code remains:** use one saved recovery code for login, then immediately enroll a new authenticator and generate/acknowledge a replacement set.
- **Authenticator and every recovery code are lost:** there is no unapproved console bypass. Enter incident escalation, preserve evidence, verify the operator’s identity through the approved out-of-band process, and obtain an explicitly reviewed recovery action.
- **Queue or audit storage is unavailable:** stop privileged rollout and high-risk mutations. Mandatory audit failures must not leave a committed credential or authority change.

## Rollback security floor

If deployment rollback is required:

1. Re-enter maintenance mode and preserve encrypted MFA data, audit history, backups, and failed-job evidence.
2. Do not restore known passwords, password-only operational access, hard deletes, or Phase 0 bypasses.
3. If schema rollback encounters `pending_setup`, use an explicit operator-reviewed forward recovery rather than silently activating the account.
4. Never restore cleared or invalidated privileged sessions.
5. If an application rollback would disable active/MFA enforcement after any Phase 1 credential was issued, stop the rollback and roll forward with a corrective patch.
6. Record which accounts are pending setup, active, suspended, or inactive and which tokens/sessions were invalidated.

The security floor is staged authentication, MFA for operations, registered-session validation, recent reauthentication for high-risk changes, final-Super-Admin protection, and auditable mutations. A rollback that removes any of these controls is not an acceptable rollback.

## Verification commands

Run these commands from the deployed application revision and attach the output to the change record:

```powershell
composer audit --locked
php artisan help super-admin:bootstrap
php artisan route:list --path=admin --except-vendor
php artisan queue:failed
```

The route output must show the expected `privileged.recent` boundary on high-risk mutations and no secret-bearing token route. The queue check must be empty or have an operator-reviewed explanation for each existing failure.
