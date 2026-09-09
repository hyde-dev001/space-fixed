# Employee Account Security Hardening + Optional TOTP Implementation Plan

> **For agentic workers:** Execute this plan sequentially in the target worktree and verify each phase before starting the next.

**Goal:** Harden employee account-management boundaries and credential handling, then add optional employee TOTP using the existing Laravel authentication/session architecture and installed Google2FA dependency.

**Architecture:** Keep `users.password` and the existing `user` guard as the canonical employee login. Add tenant-scoped, dedicated authorization for credential-management actions; centralize employee password rules and forced-password/session-version enforcement; use encrypted pending/persisted TOTP secrets and hashed recovery codes; keep Shop Owner/customer email OTP separate.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript, database sessions, Sanctum, `pragmarx/google2fa`, Bacon QR Code.

---

## Phase 1 — Employee account security hardening

- [ ] Add dedicated account-management permissions to the existing user permission seeder and assign them to the existing authorized management roles.
- [ ] Add tenant-safe authorization to employee invitation, resend, reset, and setup-related management operations; preserve attendance/payslip self-service permissions.
- [ ] Add explicit employee account serialization so password hashes and credential material cannot enter HR responses; hide legacy `Employee.password` and document its non-use.
- [ ] Harden invitation token storage/lookup while preserving live-link compatibility, expiry, and single-use behavior.
- [ ] Add employee security-version/session revocation support and apply it to self-service password changes and administrative resets; revoke applicable Sanctum tokens.
- [ ] Add one centralized middleware for employee forced-password setup and stale security sessions.
- [ ] Reuse one canonical employee password-rule helper in profile change, invitation setup, and reset completion.
- [ ] Exclude employee company accounts from the email OTP recovery flow without changing customer/Shop Owner behavior.
- [ ] Correct the legacy API logout guard and prevent employee API login from bypassing the browser/MFA flow.
- [ ] Add safe HR audit entries for employee security actions without logging secrets.
- [ ] Add Phase 1 feature/regression tests and run the existing ERP password and email OTP suites.

## Phase 2 — Optional employee TOTP

- [ ] Add encrypted employee TOTP fields and security-version storage to `users`, with hidden/cast model attributes.
- [ ] Implement a focused `EmployeeMfaService` using the existing Google2FA and QR services for setup, verification, replay protection, and hashed single-use recovery codes.
- [ ] Add authenticated enrollment, recovery-code regeneration, disable, and management MFA-reset endpoints with throttling and dedicated authorization.
- [ ] Add a pre-authentication employee TOTP challenge so password verification never grants a full employee session before MFA succeeds.
- [ ] Extend employee profile props/UI with a native Security section, setup/verification, recovery-code, disable, session-revoke, and recent-security-activity flows using existing SoleSpace styles.
- [ ] Add the login challenge page and update company-account wording without implying a real mailbox or email recovery.
- [ ] Add Phase 2 feature tests for optional login, enrollment, invalid/throttled codes, recovery codes, disable, management reset, tenant isolation, and secret exposure.

## Final verification

- [ ] Run focused PHP tests, frontend tests/build, route inspection, `git diff --check`, and a dead-code/security review.
- [ ] Confirm no plaintext password/hash, invite token, TOTP secret, recovery code, session ID, or API token is returned or logged.
- [ ] Confirm no employee email-OTP recovery was introduced, no existing Shop Owner/customer OTP path regressed, and no production-default predictable credential was added.
