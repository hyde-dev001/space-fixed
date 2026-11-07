# Customer MFA and Security Activity Design

## Goal

Add the existing TOTP MFA and security-activity experience to the customer account profile while keeping the implementation separate from employee/ERP access rules and explicitly excluding Active Sessions from the customer experience.

Customer MFA is optional to enable. After enrollment, customer sign-in must stop before normal authentication and require the same second-factor verification flow used by employees.

## Repository findings

- Customer profiles are rendered by `CustomerProfileController@show` and `resources/js/Pages/UserSide/Profile/customerProfile.tsx`.
- Customer authentication uses the `user` guard and `User::isCustomerAccount()`.
- Employee MFA already uses encrypted `users.employee_totp_secret`, hashed recovery codes, single-use TOTP timestep tracking, and `EmployeeMfaService`.
- Employee MFA setup and challenge are handled by `EmployeeMfaController`; its current account guard intentionally accepts employees only.
- Employee security activity is written through `EmployeeSecurityService` to `hr_audit_logs` and queried by `UserProfileController`; its query is employee/tenant scoped.
- The customer profile currently has no MFA or security-activity props/routes/components.
- The existing employee UI includes both security activity and Active Sessions, so it cannot be mounted for customers without an explicit no-sessions mode.

## Chosen approach

Reuse the existing MFA service and shared `User` storage, and extend the account-specific controller/service boundaries minimally:

1. Add customer MFA actions to the existing MFA controller through small customer-scoped entry points that reuse the same setup, verification, recovery-code, disable, and login-challenge logic.
2. Add customer-only route names under `/customer-profile/security/totp` and `/customer-profile/security/activity`, protected by `auth:user`, `EnsureCustomerAccount`, and existing throttles.
3. Add a customer security-audit writer/query path in `EmployeeSecurityService` (or its smallest equivalent shared helper) using a distinct `customer_security` tag and customer-safe descriptions. Customer records have no shop owner or employee association; queries must filter by the authenticated customer’s user ID and entity ID.
4. Add customer MFA status and recent activity to the profile response. Do not include session data.
5. Make `EmployeeTotpSecurity` accept route configuration and a `showSessions` flag. Employee defaults remain unchanged; the customer profile passes customer route names and `showSessions={false}`. Customer rendering may reuse the existing setup/recovery-code/disable/activity UI but must never call employee or session routes.
6. Extend customer login before `Auth::guard('user')->login()` so an enrolled customer is logged out, given a customer-specific pending-MFA session, and redirected/responded with a customer MFA challenge. Only successful second-factor verification establishes the authenticated customer session and updates login/audit state.

## Customer login flow

```text
valid customer password
  -> email verification/status checks
  -> enrolled customer MFA?
       no  -> existing customer login flow
       yes -> clear user guard + regenerate session
           -> store pending customer ID/security version/remember flag
           -> customer MFA challenge
           -> verify TOTP or one recovery code
           -> atomically consume factor and establish user session
           -> redirect to the existing customer destination
```

Failed customer MFA attempts must remain generic, rate-limited, and unable to establish a normal authenticated session. Recovery codes are single-use. Security-version changes invalidate old pending challenges and other sessions according to the existing shared security behavior.

## Customer profile flow

```text
GET /customer-profile
  -> customer-only controller
  -> return totp_enabled and five recent customer_security entries

POST /customer-profile/security/totp/setup
  -> verify current password
  -> create short-lived encrypted setup secret

POST /customer-profile/security/totp/verify
  -> verify setup TOTP
  -> store encrypted secret, timestamp, hashed recovery codes

POST /customer-profile/security/totp/recovery-codes/regenerate
  -> verify current password + current TOTP
  -> atomically replace recovery-code hashes

POST /customer-profile/security/totp/disable
  -> verify current password + current TOTP
  -> invalidate access, clear TOTP data, audit the action

GET /customer-profile/security/activity
  -> paginate only the authenticated customer’s customer_security records
```

There will be no customer `/security/sessions` route, session props, session component, “Active Sessions” card, or logout-other-sessions action.

## Security and authorization

- Every customer endpoint must validate the `user` guard and `isCustomerAccount()` server-side.
- Customer endpoints must not accept a user ID, destination account, arbitrary email, or target account from the client.
- Setup secrets are kept only in the session, encrypted, tied to the authenticated customer, and expire after the existing short setup window.
- TOTP secrets remain encrypted and recovery codes remain hashed/one-time.
- Setup, verification, recovery regeneration, disable, and challenge routes remain throttled.
- Customer activity responses must exclude employee/tenant records and sensitive MFA secrets/codes.
- Employee routes, employee session management, HR audit behavior, and ERP login flow must remain unchanged.

## Audit event vocabulary

Customer security events will use customer-specific actions (for example `customer_totp_enabled`, `customer_totp_disabled`, `customer_totp_verified`, `customer_recovery_codes_regenerated`, `customer_login_succeeded`, and `customer_login_failed`) and the `customer_security` tag. The existing audit model’s nullable shop-owner/employee fields are retained; the authenticated customer ID remains the actor and entity identity.

## Verification plan

Backend feature tests will cover:

- customer can begin and complete MFA enrollment;
- customer cannot use another account’s setup/challenge state;
- customer MFA is required after enrollment and successful verification alone completes login;
- invalid TOTP and recovery code behavior, single-use recovery codes, expiry, and rate limiting;
- disabling MFA invalidates access and clears the secret/codes;
- customer activity is private and paginated;
- employee MFA/login routes remain employee-only;
- no customer Active Sessions route or payload is exposed.

Frontend tests will cover:

- customer profile renders MFA status and setup controls;
- customer profile renders recent security activity and opens/paginates “View all” activity;
- customer profile contains no Active Sessions text/control and makes no session request;
- customer route configuration is used for setup, recovery, disable, and activity requests;
- employee security UI continues to render Active Sessions and use ERP routes.

Required checks after implementation: focused Laravel tests, focused frontend tests, `pnpm run build`, and `git diff --check`.

## Alternatives rejected

- A separate customer MFA service/controller implementation would duplicate cryptographic and recovery-code behavior.
- Reusing ERP routes for customers would weaken the employee/customer authorization boundary and make accidental session exposure likely.
- Adding Active Sessions for customers is explicitly out of scope.
