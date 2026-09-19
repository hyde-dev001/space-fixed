# Shop Owner TOTP Security

Date: 2026-09-10

Status: Approved

## Goal

Replace the Shop Owner email-delivered login OTP with authenticator-app TOTP
while preserving the existing Shop Owner login boundary and keeping the
implementation aligned with the employee and customer MFA flows.

The Shop Owner must be able to enroll an authenticator, verify a six-digit
code at login, use one-time recovery codes, regenerate recovery codes, and
disable TOTP after reauthentication.

## Existing implementation

The current Shop Owner flow:

- stores only two_factor_email_enabled on shop_owners;
- generates a random six-digit code in ShopOwnerAuthController;
- hashes the code in the pending session and sends it through email;
- renders UserSide/Auth/ShopOwnerTwoFactor with a resend-email action;
- enables or disables the email OTP flag from Shop Owner Settings.

The existing employee/customer flow already provides:

- Google Authenticator-compatible TOTP generation and verification;
- QR and manual provisioning data;
- hashed, one-time recovery codes;
- replay protection using the last accepted TOTP timestep;
- current-password confirmation for enrollment and sensitive changes;
- shared EmployeeTotpSecurity and EmployeeMfaChallenge React components.

There is no Shop Owner TOTP secret or recovery-code storage today.

## Design decisions

### Storage

Add the following nullable fields to shop_owners:

- shop_owner_totp_secret, encrypted;
- shop_owner_totp_enabled_at, datetime;
- shop_owner_totp_recovery_codes, JSON-encoded hashed codes;
- shop_owner_totp_last_used_timestep, unsigned integer.

The ShopOwner model will hide the secret and recovery-code fields and cast them
to their appropriate types. A Shop Owner is TOTP-enabled only when both the
enabled timestamp and non-empty secret are present.

Keep two_factor_email_enabled in the database for backward compatibility
with already-deployed schemas, but stop exposing or updating it from the new
settings UI and stop using it to issue email codes.

### Canonical MFA implementation

Reuse the existing TOTP and recovery-code cryptography from
EmployeeMfaService. Add only the smallest account-neutral primitives needed
for Shop Owner attributes; do not copy a second Google2FA or recovery-code
implementation.

Employee and customer User-account behavior must remain unchanged.

### Shop Owner enrollment

Add authenticated, throttled Shop Owner security endpoints for:

- starting setup after current-password confirmation;
- verifying the authenticator code and enabling TOTP;
- regenerating recovery codes after current-password and TOTP
  reauthentication;
- disabling TOTP after current-password and TOTP reauthentication.

The setup secret is encrypted while pending in the session, expires, and is
never returned after successful enrollment. Recovery codes are shown only once
after generation and only their hashes are persisted.

The existing EmployeeTotpSecurity component will be reused with Shop Owner
route overrides. Shop Owner does not gain employee-only session-management
actions. The old email OTP toggle and resend action are removed from the
Shop Owner experience.

### Shop Owner login

The password step remains separate from the second factor:

1. Validate the Shop Owner credentials and approved account status.
2. If TOTP is enabled, store a short-lived pending Shop Owner login context
   without authenticating the guard.
3. Render the existing TOTP challenge component with Shop Owner verify and
   login routes.
4. Verify either a current TOTP code or a one-time recovery code inside a
   transaction with a row lock.
5. Enforce the existing attempt limit and pending-session expiry.
6. Clear the pending context, regenerate the session, authenticate the
   shop_owner guard, and update login metadata.

The email-code generation, email delivery, and resend endpoint are removed
from the active login flow. No email login OTP is sent after this change.

### Existing email-OTP accounts

An email OTP cannot be converted into a TOTP secret because the authenticator
secret was never stored. Existing accounts with the legacy
two_factor_email_enabled flag set will therefore be required to complete a
TOTP enrollment flow after password verification before their next login is
completed. This preserves the old account's second-factor requirement without
silently downgrading it.

Accounts without the legacy flag remain password-only until the owner enables
TOTP in Settings.

The migration must not fabricate a secret or copy email OTP state into TOTP
fields.

### UI reuse

Use:

- the existing neutral SoleSpace security-card pattern;
- EmployeeTotpSecurity for setup, recovery, and disable actions;
- EmployeeMfaChallenge for the six-digit TOTP/recovery-code login challenge;
- Shop Owner route overrides rather than employee/customer endpoint defaults.

The Shop Owner page must show authenticator-app TOTP wording and must not show
email OTP or resend-code controls.

## Security and authorization

- All Shop Owner security mutations require the shop_owner guard.
- All mutations are throttled using the existing route middleware pattern.
- Setup and disable require the current password.
- TOTP and recovery-code consumption occur under a row lock.
- A TOTP timestep cannot be accepted twice.
- Recovery codes are hashed and consumed once.
- Failed challenge attempts are counted and expire the pending login context.
- Pending login and setup sessions are bound to the current Shop Owner.
- Successful TOTP verification regenerates the session before authentication.
- Secret, recovery hashes, and pending setup secret are never serialized to the
  browser beyond the setup response needed to enroll the authenticator.
- Existing employee/customer and Super Admin MFA routes remain unchanged.

## Routes and responsibilities

Shop Owner security routes will be grouped under an authenticated and
throttled Shop Owner TOTP prefix. The login challenge will keep the existing
Shop Owner route area where practical so the public route catalog does not
gain duplicate concepts, but its semantics will become TOTP verification.

The Shop Owner MFA controller owns setup, recovery, and disable operations.
The Shop Owner authentication controller owns credential validation and the
pending login handoff. Shared MFA primitives remain in the existing MFA
service.

## Testing

Add focused Laravel coverage for:

- setup requires the current password;
- setup returns a QR/manual key and verifies a real TOTP code;
- secret and recovery data are stored in the Shop Owner fields and hidden from
  serialized responses;
- enabled Shop Owners reach the TOTP challenge without authenticating first;
- valid TOTP and recovery codes complete login;
- invalid codes, replayed timesteps, expired contexts, and excessive attempts
  are rejected;
- no login email OTP is sent and no resend flow is used;
- disabling and regenerating recovery codes require reauthentication;
- one Shop Owner cannot use another Shop Owner's setup or login context;
- legacy email-OTP accounts are forced through TOTP enrollment.

Add focused React coverage for:

- Shop Owner route overrides use TOTP setup and verification endpoints;
- the Shop Owner security UI no longer renders email OTP or resend controls;
- the shared challenge accepts TOTP and recovery-code modes;
- the Shop Owner page does not render employee session-management actions.

Run the focused Laravel and Vitest suites, the production build, and
git diff --check before completion.

## Out of scope

- WebAuthn/passkeys;
- SMS or email fallback MFA after migration;
- Active Sessions for Shop Owner;
- changes to customer, employee, or Super Admin MFA semantics;
- changes to unrelated Shop Owner settings or authorization policies;
- automatic TOTP enrollment without user confirmation.

## Acceptance criteria

- Shop Owner email OTP is no longer used for login.
- Shop Owner can enroll and verify an authenticator-app TOTP.
- Shop Owner can log in with TOTP or a one-time recovery code.
- TOTP replay and brute-force protections are enforced server-side.
- Existing email-OTP accounts cannot bypass their prior second-factor
  requirement and are required to enroll TOTP.
- Settings uses the existing SoleSpace TOTP UI pattern.
- No second cryptographic MFA implementation is introduced.
