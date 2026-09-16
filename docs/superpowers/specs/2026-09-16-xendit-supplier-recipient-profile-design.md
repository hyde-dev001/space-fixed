# Xendit Supplier Recipient Profile Design

**Date:** 2026-09-16  
**Status:** Approved for implementation planning

## Goal

Allow SoleSpace shops to pay both business and individual suppliers through
Xendit without hardcoding the recipient entity type. Supplier payment profiles
must capture the recipient identity, address, and payout account details needed
to build a valid Xendit payout request.

## Acceptance Criteria

- A supplier payment profile supports `business` and `individual` recipient
  types and defaults new profiles to `business`.
- Business profiles require `business_name`.
- Individual profiles require `given_name` and `surname`.
- Both recipient types require the payout recipient's country, province/state,
  city, street address, and postal code.
- Existing bank-account and e-wallet destination fields remain supported and
  account numbers or wallet identifiers remain encrypted.
- Xendit receives `recipient.type` from the verified profile rather than a
  hardcoded value.
- Every payout sends `relationship = SUPPLIER`.
- A change to recipient identity, address, or payout destination returns the
  profile to `unverified` and affects only future payment attempts.
- Payment attempts retain an encrypted snapshot of the complete verified
  recipient destination.
- Xendit validation failures expose only safe field-level guidance and never
  expose credentials, full account identifiers, or raw provider responses.

## Data Model

Extend `supplier_payment_profiles` with:

- `recipient_type`: `business` or `individual`, default `business`;
- nullable `business_name`;
- nullable `given_name` and `surname`;
- `recipient_country` using an ISO alpha-2 country code;
- `recipient_province_state`;
- `recipient_city`;
- `recipient_street_line_1`;
- nullable `recipient_street_line_2`;
- `recipient_postal_code`.

The existing destination type, bank or wallet provider, routing code, account
name, and encrypted account identifier remain the source of payout account
details. No Xendit recipient object or supplier API credential is stored.

Existing profiles are migrated with `recipient_type = business`. Their current
supplier name and address may prefill the new fields where an unambiguous value
already exists, but no missing compliance value is invented. Profiles missing
any required field become `unverified` and cannot be used for a new payout until
Procurement completes the profile and Finance verifies it.

## Validation and Profile Lifecycle

The profile request validates a strict recipient-type allowlist.

- `business`: requires `business_name` and prohibits individual-only names.
- `individual`: requires `given_name` and `surname` and prohibits
  `business_name`.
- both: require the complete recipient address and existing destination-specific
  payout fields.

Country values are normalized to uppercase ISO alpha-2 form before storage.
Changing any identity, address, routing, or account field resets verification.
Leaving the encrypted account input blank while editing keeps the saved value,
consistent with the current profile workflow.

## User Interface

Supplier Management adds a recipient type selector above the payment profile.
Business is selected by default for a new profile.

- Business selection shows Business Name.
- Individual selection shows Given Name and Surname.
- Both selections show the complete recipient address and the existing payout
  destination fields.

Existing supplier name and address fields may prefill a new profile, but the
user must review and save them. Conditional fields are removed from the request
when not applicable. Validation errors remain attached to their corresponding
form fields.

Finance continues to see only the data needed to review the destination. Full
account identifiers remain hidden by default and existing authorization rules
continue to govern any reveal action.

## Xendit Payload

`XenditPayoutService` builds the recipient object from the payment-attempt
snapshot:

- Business sends `type = BUSINESS` and `business_name`.
- Individual sends `type = INDIVIDUAL`, `given_name`, and `surname`.
- Both send the complete address, account details, and
  `relationship = SUPPLIER`.

Only fields valid for the selected recipient type are included. The service
continues to send amount values in minor units and to use the payment attempt's
idempotency key.

## Provider Error Handling

For Xendit `API_VALIDATION_ERROR` responses, extract the provider's `errors`
array into a short allowlisted diagnostic. Safe field paths and validation
messages may be shown to Finance. Values from the submitted payload, raw
provider response bodies, API keys, account numbers, and personal identifiers
must not be logged or returned.

Unknown, timeout, rate-limit, and server-error responses retain the current
indeterminate handling so Finance cannot accidentally create a duplicate
payout.

## Security and Authorization

- Secret API keys remain encrypted and are never exposed to the browser or
  logs.
- Account numbers and wallet identifiers remain encrypted at rest and masked in
  API responses.
- Recipient profile changes continue to require the existing shop-scoped
  Procurement permissions and Finance verification.
- Payout creation continues to enforce shop ownership, verified profile state,
  server-calculated amount, and idempotency.
- Provider diagnostics are sanitized before logging or presentation.

## Verification

Backend coverage will verify:

- conditional business and individual validation;
- profile verification reset after identity or address changes;
- business and individual Xendit payload shapes;
- `relationship = SUPPLIER` for both types;
- complete snapshot use and exclusion of stale profile changes;
- safe provider validation diagnostics and sensitive-value redaction;
- existing unknown-response and idempotency protections.

Frontend coverage will verify the default Business selection, conditional
identity fields, required address fields, edit prefill behavior, and submitted
payload for both recipient types. The focused Finance payout tests and a fresh
production build must pass before deployment.
