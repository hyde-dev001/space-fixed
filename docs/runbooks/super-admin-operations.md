# Super Admin Billing Operations

This runbook covers bounded recovery for premium subscription billing. It does not authorize provider refunds, plan changes, paid-history edits, or production database correction outside the workflows below.

## Legacy subscription inventory

Run the legacy reconciliation command in dry-run mode first:

```powershell
php artisan premium-billing:reconcile-legacy --limit=100
```

Review the reported reliable, ambiguous, duplicate, and unchanged counts. Apply only the reviewed bounded batch:

```powershell
php artisan premium-billing:reconcile-legacy --apply --limit=100
```

Never use the command to guess a provider payment ID, amount, owner, plan, or entitlement date. Ambiguous `deactivated` rows remain visible for the narrow privileged correction workflow.

## Provider reconciliation

Inspect the local pending, processing, or unknown attempt state through the privileged billing screen and audit history, then run a bounded provider reconciliation:

```powershell
php artisan premium-billing:reconcile-provider --limit=100
```

The command retrieves known provider refund references or lists refunds for the original payment. It never creates a second refund request. Re-run only after the provider state or webhook delivery has changed; an unknown attempt remains blocked from blind retry until its outcome is known.

The command prints local attempt counts and statuses only. It must not print provider payloads, secrets, payment details, owner personal data, or exception text.

## Provider outage and irreconcilable state

- A timeout is recorded as `unknown`; retain entitlement and do not submit another refund with a new key.
- A known provider failure preserves the paid payment and subscription state and may be retried only as a new, explicitly reviewed attempt.
- A provider amount, currency, payment, or refund-reference mismatch is not auto-corrected. Preserve the attempt and escalate for provider reconciliation.
- If a provider refund succeeded while the local finalization failed, reconcile the existing provider refund reference before enabling any further intervention.

Do not delete refund attempts, set `paid_amount` to zero, reverse provider history locally, or restore withdrawn direct plan-swap/pseudo-refund endpoints during recovery.

## Safety checks before enabling interventions

Confirm production PayMongo credentials and webhook signature verification are configured, the migration is deployed, and the focused Phase 5 billing tests pass. Use sandbox/test credentials for browser verification. Never send a real refund during local verification.
