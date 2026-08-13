# Privileged Setup Session-Independent Completion Design

## Status

Approved for implementation on 2026-08-13.

## Problem

The first Super Admin setup link exchanges successfully and displays the password form, but `POST /admin/setup/complete` redirects back with `errors.token = "The setup link is invalid or expired."`. Production correlation ID `a4f65eb2-2dc4-474e-91d7-1e26d6250982` identifies the failed request without exposing credentials.

The current flow stores the authorized token and subject IDs only in the Laravel session during the exchange request. Production loses that authorization before password submission. The frontend also omits the `token` error key from its displayed error priority, replacing the specific server response with a generic setup failure.

## Outcome

A fresh, valid setup link must allow a pending first Super Admin to create a password and continue to MFA even if the Laravel session authorization written during token exchange is unavailable on the next request.

## Chosen approach

The exchange endpoint will return a short-lived encrypted completion proof in addition to its success flag. The browser will hold the proof only in React memory and submit it with the password. The server will decrypt and validate the proof, then use the existing locked, atomic token-consumption path.

The proof payload contains only:

- the privileged security token ID;
- the pending Super Admin ID;
- the setup purpose;
- an issued-at timestamp;
- an expiry timestamp.

Laravel's existing application encryption provides confidentiality and tamper detection. The database token remains the source of truth for purpose, subject, expiry, account state, and one-time use.

## Data flow

1. The browser reads the raw setup bearer from the URL fragment and immediately removes the fragment from browser history.
2. `POST /admin/setup/exchange` validates the raw bearer against the database token and pending account.
3. The server returns `{ authorized: true, completion_proof: "..." }` with no-store headers. It may continue writing the existing session authorization for compatibility, but password completion must not depend on it.
4. The browser retains `completion_proof` in component memory. It is not written to the URL, storage, cookies, logs, Inertia props, or browser history.
5. `POST /admin/setup/complete` submits the password, confirmation, and completion proof over HTTPS with the existing CSRF protection.
6. The server decrypts the proof, validates its shape, purpose, timestamps, and configured short lifetime, and passes its integer IDs to `consumeAuthorized`.
7. Inside the existing database transaction, the server locks the token and administrator, revalidates token/account state, writes the password and MFA secret, writes the mandatory audit event, and marks the token used.
8. The server establishes the setup-stage authenticated session and redirects to `/admin/mfa/setup`.

## Security invariants

- The original raw invitation bearer remains fragment-only and is sent only to the exchange endpoint.
- The completion proof is an opaque bearer and receives the same handling as other credentials: no URL, persistent browser storage, session storage, logs, audit properties, queue payloads, or exception messages.
- Proof encryption and authentication use Laravel's configured encrypter and stable `APP_KEY`; no new cryptography or dependency is introduced.
- Proof lifetime is bounded by the existing setup authorization window and cannot extend the underlying database token expiry.
- A proof does not bypass the database token. Modified, expired, reused, wrong-purpose, wrong-subject, used-token, or invalid-account-state proofs fail.
- Password validation, CSRF middleware, setup throttling, HTTPS, no-store headers, strict ID comparison, mandatory audit rollback, and one-time token consumption remain unchanged.
- Failure responses remain generic to the operator and include a safe correlation ID for server-log lookup. Passwords and proof values are never logged.

## Error handling

- Missing, malformed, undecryptable, expired, or semantically invalid proofs return the existing invalid-or-expired setup-link response.
- Unexpected encryption, database, audit, password-write, or MFA-secret failures are reported server-side with correlation ID and return the existing generic completion failure.
- The frontend displays server `token` errors instead of replacing them with the generic fallback.
- A fresh setup link is required after deployment. Existing proof-less pages and previously used or replaced links are not migrated.

## Alternatives rejected

### Resubmit the original setup bearer

This is smaller but retains the more powerful invitation credential in browser memory through password entry and sends it twice. The derived completion proof narrows the client-held credential to the completion step.

### Continue relying only on the Laravel session

This preserves the current architecture but does not remove the production failure boundary. Cookie cleanup or session configuration may still be corrected operationally, but successful credential setup should not depend on that handoff.

## Test strategy

Backend feature coverage will prove:

- exchange returns an opaque completion proof without returning the raw setup bearer;
- password completion succeeds after deliberately removing all exchange session authorization;
- the proof is single-use because the database token is consumed atomically;
- modified, expired, wrong-purpose, and missing proofs fail without changing the password, MFA state, or token;
- audit failure rolls back password, MFA secret, and token use;
- logs and responses do not contain the password, raw bearer, or completion proof.

Frontend coverage will prove:

- the exchange hook retains the completion proof only in memory;
- password submission includes the proof;
- the specific `token` error is shown;
- no proof is written to browser storage or the URL.

## Deployment and recovery

Deploy backend code and the matching frontend build together, clear Laravel configuration/route/view caches through the normal deployment process, and restart long-lived PHP workers if the host requires it. Then replace the pending platform invitation with `php artisan super-admin:bootstrap` and use the fresh emailed link in a private browser window.

No schema migration or new dependency is required. If the new attempt fails, use the response `X-Correlation-ID` to locate the safe server-side exception record before making another change.
