# Repair Platform Fee missing-charge review (read-only)

Prepared for approved workstream G. No production query, candidate export, historical fee creation or reconciliation has been run. Production source/build and historical configuration evidence are not available in this session.

## Candidate inventory

Use a read-only database connection or an authorized offline snapshot. Select repair internal ID, request_id, shop_owner_id, created_at, terminal status, origin_channel, pricing mode, billing_mode, is_warranty_job, total/final_total, payment status/raw paid amount, intake/return fee and lock flags, and reconciliation state. Join existing platform_fee_charges by source_type=repair, source_id and source_origin=marketplace; inventory only missing sources. Do not call finalizeRepair, settlement/provider APIs, threshold evaluation, credits application or any observer-triggering model save to produce the inventory.

Exclude unfinished/cancelled/rejected sources, POS/manual_pos/REP-POS origins, warranty/no-charge jobs, fully refunded service and unresolved reconciliation. Include completed, ready-for-pickup, ready_for_pickup, picked_up and shipped for inspection. A displayed paid/completed status alone is insufficient evidence of complete service collection.

## Evidence required per candidate

| Field | Required evidence |
| --- | --- |
| Source | Tenant-scoped persisted original repair and business reference; no existing same-source charge |
| Collection | Same-shop paid POS metadata service_amount plus paid RepairPaymentSession service_amount; resolved reconciliation applied service/credit amounts; retained legacy raw paid amount minus locked delivery amounts |
| Refund | Service versus delivery components, full/partial amount and succeeded state; original fee/refund snapshots if present |
| Eligibility date | Source created_at versus the applicable historical effective_from; pre-effective sources excluded |
| Fee configuration | Historical approved platform/shop-type/shop override rate, VAT settings and terms at the relevant date, with provenance |
| Outcome | Excluded with reason, evidence missing, or eligible candidate requiring separate review |

PlatformFeeSettingsResolver resolves current settings; its output is not proof of historical rates. If historical approved configuration cannot be established, retain the source as evidence missing and do not calculate or create a fee using today's defaults. Preserve charge/adjustment/payment IDs and append-only history.

## Review output and authorization

Produce a redacted table with source/shop IDs and business reference, service base, verified service collection, excluded delivery total, refund components, historical configuration evidence, existing charge ID, eligibility result/reason and proposed fee/VAT only when historical evidence is complete. No personal information or provider secrets are required. Totals must distinguish reviewed eligible candidates from unresolved records.

Any production financial backfill requires a separate dry-run review and explicit approval. This document authorizes no replay and contains no write command. Rollout of current-flow guards does not authorize scanning and finalizing historical records.
