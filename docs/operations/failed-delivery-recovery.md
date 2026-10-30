> Publication follow-up: [October 24 commit/rebase verification](2026-10-24-logistics-publication.md) records the authorized feature publication and new upstream camera-photo clock-in requirements. Historical uncommitted-status notes below describe earlier implementation checkpoints. Merge and deployment remain separate actions.

# Failed-delivery recovery operations

For role-by-role testing with explicit next-page directions, use the [manual testing guide](failed-delivery-recovery-manual-testing.md).

## October 30 current incident and return flow

This section supersedes the older checkbox, custody-dropdown, generic Staff panel and manual physical-closure instructions below. See the [root causes, workflow map and verification record](../superpowers/plans/2026-10-30-parcel-workflow-dead-ends.md).

- Dispatcher opens the original incident in Logistics. Recovery evidence and current confirmation/actions are linked there. Supply the decision reason and only the relevant customer arrangement, resolved-obstacle plan or unsafe transport arrangements; no verified-findings checkbox establishes custody or inspection.
- Reporting Rider uses Delivery History to report recovery with details, condition, required photo and confirmation. There is no custody dropdown. Legacy evidence missing confirmation has a real Confirm Recovery action. A final loss remains final; recovery follows a separate linked shop return.
- Accept a fresh recovery return offer. When pickup is required, upload the normal pickup proof and explicitly confirm pickup before starting. Return handoff proof does not itself mean Staff received the parcel.
- Staff uses that order's **Confirm Parcel Received**, **Inspect Returned Items** and permitted item/source actions in the existing order workflow. The generic Return receipt and disposition panel is removed, including order details. Record real quantities and conditions; never assume expected quantity was received. Supported handling completes physical return automatically. Refund review remains reachable afterward; physical completion does not approve or pay money.
- A verified parcel already at the shop needs no extra Rider trip. Link the existing actual receiver's receipt record. Ambiguous historical identity/links remain Dispatcher review; do not invent a receiver or confirmation. Repair property follows existing assigned Repairer/customer arrangements and never increases retail sellable stock.
- For a read-only historical inventory, an authorized operator may run `php artisan logistics:preview-parcel-recovery --shop=ID` in the intended environment. It emits IDs/reasons, has no apply mode and does not repair records. Review ambiguous rows before separately authorized correction.

No new Owner physical-receipt authority, migration or payment policy is introduced. Owner operational labels are read-only; Finance/provider outcomes remain independent.

## October 29 loss and linked recovery amendment

This workflow supersedes the older loss/closure instructions below. It is implemented locally for review; it has no publication or deployment authorization. Other incident types retain their existing safeguards.

1. Rider reports Lost with a written reason; photographs are optional. Dispatcher acknowledges the report. Delivery remains held and no receipt task or refund is created at acknowledgment.
2. The reporting Rider opens **Deliveries → History → delivery details → Confirm Lost**, reads the warning and requests approval. Dispatcher final approval in incident details closes the original loss and reserves/links an eligible normal refund request. Staff/Finance/applicable Owner approval and confirmed payout remain separate. Uncollected COD does not create a cash refund.
3. If physically recovered, the reporting Rider uses **Report Recovery** with actual custody, condition, details and photographs. Dispatcher reviews the separate linked occurrence. Before final loss, eligible continuation or return is possible; after final loss, return to the original shop is mandatory. Rider explicitly accepts the new offer; existing actual handoffs and movement/proof gates apply.
4. Staff opens the existing **order details → Return receipt and disposition** section; Repairer retains its existing source workspace. Confirm actual receipt, inspect actual items and record a supported disposition. Physical completion is automatic; no extra Dispatcher closure is required. Unsupported partial or unsafe outcomes retain an owned escalation.
5. Eligible unallocated resellable retail goods restock once across recovery and later refund execution. Damaged, unsafe, already-restocked and customer-property goods do not increase sellable stock. Never clear old inventory markers or change approved/refunded quantities to repeat a stock action.

Finance's explicit **Refresh provider status** action reconciles an existing provider refund; it does not submit another payout. Approval, processing, partial/full success and failure are distinct. An ambiguous callback requires exact transaction reconciliation. Preserve the original loss, refund, receiver and evidence history. D9 remains deferred; no production/provider or historical-data operation is implied.

## October 27 parcel incident recovery

The approved D1–D8/D10 implementation is authorized for commit/push with fresh production assets; D9 remains approved but deferred. The [plan and evidence](../superpowers/plans/2026-10-27-parcel-incident-recovery.md#approved-implementation-execution-record) distinguish implementation/publication checks from historical QA results and remaining release gates. Merge and deployment remain separately unauthorized.

Incident decisions authorize work; they no longer claim its operational completion. Existing Staff/Repairer workspaces show recovery independent of refund history. Actual receipt, item inspection, Dispatcher readiness, explicit Rider acceptance and two-person custody remain separate. Original-parcel retail redelivery creates a linked source attempt without a second stock deduction or automatic fee. Existing refund, repair payment, warranty transport and customer-arrangement services retain ownership of their outcomes.

Dispatcher operational closure does not settle pending refund, COD, repair/source or loss-investigation work. Those obligations remain visible after closure. Recovered confirmed loss permits supervised safekeeping receipt and investigation only; it does not reopen the source, reverse a claim or authorize property-value compensation. Unsafe/partial goods remain quarantined. Unsupported replacement or settlement requests remain actionable escalations.

Only `2026_10_27_020923_add_recovery_context_to_delivery_incidents.php` is added for this extension. It adds nullable JSON and refuses destructive reversal once populated. No Owner custody migration, actor fields or physical-action surface is added. Preserve existing Owner workflows and report scenarios requiring deferred D9 as operational limitations. A later deployment and target-host verification require separate authorization; no development or production migration has been run by this task.

Start eligibility is distinct from reliable current-assignment/stop continuation. Legitimately activated work may arrive, upload/correct proof and complete after window expiry, subject to every other action guard. Parent batch start, acceptance or an old assignment timestamp does not activate a stop. Material pre-start schedule changes require renewed acceptance; a post-start schedule edit alone does not revoke valid continuation.

## Batch vehicle interruptions

The batch extension and its fresh verification are recorded in the [batch execution report](2026-10-23-batch-vehicle-breakdown-execution.md). Rider **My Deliveries → Report vehicle breakdown** is separate from reporting an attempted customer visit. It holds the current applicable stop and remaining unvisited work atomically; it does not create failed attempts or change completed/proof-review work.

Dispatcher **Batches → interrupted batch → Resolve interruption** can authorize same-rider resume, offer a linked replacement batch, arrange evidenced safekeeping at the shop, or keep the incident held for investigation. Same-rider resume restores accepted/in-progress state. Replacement offers preserve stop order/stages/history and require acceptance plus every required private release/receipt before route start. Partial handoffs never unlock the first stop. Unknown possession of already-started work blocks takeover; investigate it rather than editing custody. Uncollected work still needs normal pickup.

Original batches remain interrupted until authorized continuation; successful takeover records recovered lineage rather than falsely reporting all stops delivered. Confirmed appointments remain binding and changed appointments require each customer's agreement note. OFF mode blocks offers/resume/outbound release; an already-released receipt can close safely. Turning Logistics back on does not silently resume replacement work: dispatch must choose **Authorize replacement continuation**. A canceled unreleased transfer does not clear the batch hold. Released transfers cannot be canceled or reassigned to erase custody evidence.

Safekeeping is physical receipt only. Vehicle trouble never invokes order cancellation, refund, compensation, restocking, inspection approval or cash reassignment. Previously collected COD remains the original collector's remittance obligation. The original rider's interrupted responsibility remains visible until recovery is resolved; assignment alone never removes it.

Two additional migrations follow the five existing recovery migrations, before the matching application/build is released:

- `2026_10_23_132125_add_batch_scope_to_delivery_incidents.php`
- `2026_10_23_132200_add_batch_incident_holds_and_stops.php`

Both refuse populated destructive reversal. Keep the additive schema and immutable evidence for rollback; an old application that cannot enforce active batch holds is unsafe while incidents remain open. Verify the actual host's backups, SQL mode/version, migration locks, private storage, scheduler and matching frontend under separately approved deployment. The local MariaDB checks do not authorize production changes.

## Existing per-delivery recovery

Development branch: `codex/failed-delivery-recovery`, based on `origin/solespace-b` at `e9f4e25a11f8a81c2e4440b91dff7dd77fee3fe5`. See the [execution and verification record](2026-10-23-failed-delivery-recovery-execution.md) for evidence and open gates. This feature has not been committed, pushed, merged or deployed by the implementation task.

A failed visit requires an explicit dispatcher decision. Choose a retry date/window, eligible rider and reason. Each retry is a new offer requiring acceptance. Reusing a request identity with changed facts is rejected. Planning dates permit eligible early execution; a customer-confirmed date/window remains binding unless the dispatcher records customer agreement to a reschedule. Assignment and acceptance do not transfer a parcel.

For a different rider, the holder confirms release with a private photo, then the recipient confirms receipt with another private photo. Shop mediation requires both transfers. Until final receipt, recipient start/proof/COD actions remain blocked and the old holder remains responsible. Same-rider retries retain existing confirmed possession. Only an unreleased transfer may be canceled, by an exception resolver with a reason; a released handoff requires receipt or investigated incident resolution. Confirmed loss preserves evidence and does not automatically decide financial liability.

With Logistics OFF, new offers/dispatch/retries remain blocked. Already-started custody receipt can close. If a parcel reaches the shop while OFF, no next outbound handoff is automatically arranged. After enabling Logistics, dispatch explicitly reauthorizes the next retry/handoff. The former holder is released only by actual receipt; no assignment change fabricates custody.

Legacy records default to unknown custody. Do not backfill from assignments, timestamps or old leg-level photos. Existing in-flight work may close under its original proof rules. An unknown-custody failed delivery cannot start a new staged retry: arrange an evidenced return-to-shop through the existing authorized return workflow, then use the appropriate source recovery/inspection process. If historical assignment/proof data cannot support that workflow, escalate for manual investigation; do not edit the holder column to unblock it. Legacy incidents without a safe interrupted-state snapshot require explicit current-state resolution rather than guessed dismissal.

Physical receipt and inspection are separate. Staff must record actual per-line condition/quantity using the existing return screen; damaged/missing items must not be marked resellable to advance the refund. Existing restock, write-off, refund approval and Finance payout controls remain. COD already collected by rider A stays A's remittance obligation after B is assigned; parcel handoff never transfers cash or permits collecting twice.

Notifications are existing persisted in-app updates, not email/SMS/push. Normal display uses existing 10/15-second polling. Events process after commit and failures remain pending without undoing a saved business action. `logistics:deliver-pending-notifications --limit=100` retries pending events; the scheduler runs it each minute without overlap. A separately approved deployment must verify the existing Laravel scheduler runs, pending counts drain and recipient links/ownership remain correct. Old events without the new request marker are intentionally not replayed.

## Additive migrations and release prerequisites

Apply these only during a separately approved deployment, before the new code queries their columns:

- `2026_10_23_004848_add_recovery_snapshot_to_delivery_incidents.php`
- `2026_10_23_005530_create_shipment_custody_transfers_table.php`
- `2026_10_23_005531_add_confirmed_custody_to_shipment_legs_and_proofs.php`
- `2026_10_23_010928_add_customer_appointments_and_retry_identity.php`
- `2026_10_23_014614_add_logistics_notification_delivery_state.php`
- `2026_10_23_132125_add_batch_scope_to_delivery_incidents.php`
- `2026_10_23_132200_add_batch_incident_holds_and_stops.php`

Ship matching Laravel/frontend assets and preserve private local proof storage. Build output was generated locally; it is not a deployment. Keep existing OCR runtime delivery intact. The [verification-gap follow-up](2026-10-23-failed-delivery-verification-gaps.md) corrects the local enrolled-MFA and seeder defects and verifies GD sanitization. The [final release verification](2026-10-24-logistics-release-readiness.md) resolves the three catalog failures and records the fresh Staff/Finance and regression evidence. Verify the target database version/configuration, backups, migration locks and scheduler using the normal release process.

Prefer reverting application/frontend behavior while retaining additive columns and immutable evidence if rollback is needed. Never automatically drop transfer/attempt/notification history, rewrite custody, reverse COD debt or erase inspection records. Any production or data-destructive action requires separate approval.

Local browser/concurrency scripts in `tests/Browser/failed_delivery_*` require a dedicated loopback MariaDB instance using `output/failed-delivery-recovery/mariadb-data`, the disposable schema `solespace_recovery_test` on 33317 and the normal HTTP test server on 8027. They deliberately refuse another data directory. They are local test tools, not production diagnostics. Retain synthetic evidence in ignored output; do not add production credentials, cookies, OTPs or applicant documents.

## Hostinger scheduler and environment checks

The following is a future configuration for a separately approved release; nothing was changed in hPanel. Use one existing application scheduler cron entry, not a second duplicate. In Websites -> Dashboard -> Cron Jobs, choose **Custom**, set every schedule field to `*` (once each minute), and use the actual absolute PHP executable and Laravel application path:

```text
/absolute/path/to/php /home/ACCOUNT/domains/solespace.shop/APP_DIRECTORY/artisan schedule:run
```

Replace both paths after checking the hosting account. `artisan` belongs in the Laravel application root; the public web root contains `index.php` and built assets. Do not assume the CLI PHP version or PHP path matches the web runtime. Hostinger documents Custom commands and UTC cron scheduling in its [cron setup guide](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/). Laravel's scheduler expects [one every-minute invocation](https://laravel.com/docs/12.x/scheduling#running-the-scheduler). Use hPanel's output viewer initially; its PHP job fields do not accept shell redirection characters, as described in [Hostinger troubleshooting](https://www.hostinger.com/support/1583514-troubleshooting-cron-jobs-at-hostinger/).

During the approved release, confirm the deployment user's PHP binary/version/extensions and run `artisan schedule:list`; the recovery command must appear once with `* * * * *`. Confirm writable `storage/` and `bootstrap/cache/`, a persistent cache store for overlap locks (existing database/file/Redis configuration, not array/null), and database cache/lock tables when database cache is used. Observe a normal pending event draining and recipient notices persisting; verify a replay does not duplicate them. Do not run all scheduled financial tasks as a diagnostic experiment. No HTTP cron/auth bypass route is required.

Check **both web PHP and CLI PHP** have GD enabled, including the image formats in use. Local verification used `php -d extension=gd`; it did not change shared php.ini. Without GD, the existing private-original fallback remains available but cannot promise metadata removal. Enable GD on the host before claiming sanitized customer image delivery. Session/cookie/HTTPS configuration, session persistence and proxy behavior must be checked on the target host with normal enrolled-MFA login. Rebuild existing configuration/route caches as part of the approved release so they contain the corrected challenge catalog; do not retain cached old user-auth middleware on the challenge.

Ship the matching fresh `public/build` manifest and assets and the existing prepared `public/ocr` runtime directory. Run the asset read-only check in the build environment. Verify same-origin OCR URLs, CSP/WASM/MIME delivery, private proof storage/permissions and absence of development `public/hot` on the deployment artifact. The local migration reversal test proves DDL reversibility on MariaDB 10.4.32, not safety of dropping feature data or compatibility with every host version/SQL mode. Production backups, existing-data DDL locks, exact engine/version, live cron, mail and browser/CDN behavior remain target-host checks.
