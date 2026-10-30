# Failed-delivery recovery — manual testing guide

Use this guide on the QA/local application running `codex/failed-delivery-recovery`. Every numbered row identifies the current page and **the page to open next**. Switching users means switching to that user's separate browser profile/private session; it does not mean changing permissions on one account.

This is a test procedure, not a record that you performed or passed these tests. Mark each case **Pass**, **Fail** or **Blocked** and retain the observed result.

## October 30 current checks

Use the [current operations flow](failed-delivery-recovery.md#october-30-current-incident-and-return-flow) and [execution record](../superpowers/plans/2026-10-30-parcel-workflow-dead-ends.md). Earlier recipes below are historical where they mention custody selection, verification checkboxes, a generic Staff return panel or required manual physical closure.

1. Sign in normally with separate Rider, Dispatcher and Staff sessions, MFA and attendance. Report loss without photos; verify hold and no invented receipt/refund at acknowledgement.
2. Before final loss, Rider reports recovery with condition/photo/confirmation. Verify one original incident on Dispatcher, linked evidence/current revision and executable continuation. Accept the fresh offer and finish actual arrival/proof/review. Repeat the request and submit a stale final-loss approval; neither may duplicate effects.
3. Confirm loss through actual Dispatcher acknowledgement, reporting Rider confirmation and final approval. Verify eligible normal financial review without payout success. Report later recovery; authorize only shop return, accept, upload required pickup evidence, explicitly confirm pickup, start and hand over to shop.
4. Staff locates the original order, confirms real receipt, inspects actual quantities/condition and performs supported handling. Verify the obsolete panel is absent from both list and details, physical completion is automatic, and a paid ordinary return can still request eligible refund review. Preserve exact stock counts across replay and later refund execution.
5. Exercise ordinary return, retry with same/replacement Rider, source-specific repair/refund intake, Logistics OFF closing operations, unauthorized/foreign actors, expired unstarted offers versus legitimate continued movement, damaged/partial and allocated goods. Unsupported dispositions remain owned; customer repair property is not sellable stock.
6. Refresh/reopen all affected role views, including Owner read-only awareness and customer tracking. Check 390/768/1440 widths, keyboard/Escape/focus, duplicate submit, actionable errors and background refresh without losing an open form.
7. Preview historical null stages, missing confirmation, orphan/multiple links and absent real receiver evidence. Verify no rows change and ambiguous cases are explicitly flagged. Preserve original QA IDs/statuses; do not infer original host/provider results from synthetic checks.

## October 29 staged loss and linked recovery checks

Use disposable local synthetic sources and separate authenticated Rider/Dispatcher/Staff/Repairer sessions. Preserve the original 15 QA statuses. These steps supersede older loss-specific closure expectations below.

1. Report Lost with reason and no photograph. Verify delivery stays held. Dispatcher acknowledges; verify no refund, receipt or inspection task exists yet.
2. In Rider Delivery History, expand delivery details and choose Confirm Lost. Verify submission requires the warning acknowledgment. Wrong Rider, missing attendance, wrong shop and stale revision must fail. Dispatcher final approval closes the original loss and links only an eligible normal refund request; it does not approve payout. Retry the same request and verify no duplicate request or notice.
3. Pre-final branch: report actual recovery with condition/custody/photos, then Dispatcher authorizes eligible continuation with customer agreement and current schedule. Accept the fresh offer, start, arrive, submit/review proof; verify automatic physical completion. An open recovery must block stale final loss approval.
4. Post-final branch: preserve the original loss/refund snapshot; report a new linked recovery. Verify only shop return is offered. Accept, start and complete the actual shop handoff. Staff opens that order's details, records inspection and supported inventory disposition. Verify automatic physical completion and unchanged original loss/receiver/refund history.
5. Check safe retail restock, damaged/unsafe write-off, prior write-off, prior restock, active allocation and customer-owned repair property. Replays and later successful refund execution must never repeat stock. Partial/unsupported quantities must retain actual evidence and an actionable escalation.
6. Use signed provider fakes: exact older refund among several on the same payment, wrong payment/amount/currency, duplicate success, delayed pending/failure, unknown IDs, reconciliation and partial/full amounts. Verify Finance and Staff display the authoritative outcome and a separate pending request remains visible.
7. Check loading/error/retry behavior and keyboard use in existing details at 390/768/1440 widths. Verify Dispatcher/Staff page-wide recovery panels are absent and Repairer/non-loss actions remain. Record which steps were clicked versus exercised through authenticated browser requests. Hosted transaction matching and real provider checks require separately supplied evidence/authorization.

## October 27 parcel incident recovery extension

Use the [approved specification](../superpowers/specs/2026-10-27-parcel-incident-recovery-design.md) for all 15 case IDs and the [implementation evidence](../superpowers/plans/2026-10-27-parcel-incident-recovery.md#approved-implementation-execution-record) for actual runs. Preserve the original report statuses; a synthetic execution does not certify the missing original QA environment.

1. Rider reports the actual incident. Dispatcher investigates and records the required custody, condition, obstacle/dispute and customer-arrangement findings before selecting an eligible decision.
2. For a retained-parcel retry, Rider explicitly accepts the new offer and starts under its current schedule. Different-Rider recovery requires real release and receipt. For via-shop recovery, Staff or the eligible Repairer receives and inspects first; Dispatcher verifies readiness and stages the accepted target Rider's outbound handoff. Receipt alone must not dispatch anything, including while Logistics is OFF.
3. For terminal return, Rider confirms the existing return proof; a distinct authorized same-shop employee records actual receipt and evidence. Open **Returned parcels requiring action** in Staff Job Orders or the assigned repair workspace even when no refund exists. Inspect exact item references, quantities and condition. Unsafe, missing or unidentified items remain quarantined with actionable escalation.
4. Continue the supported source workflow: original-parcel retail redelivery retains its allocation and has no automatic new fee; refund review uses existing approvals/line inspection; repair arrangements and payments remain customer-owned. Shop-bound repair/refund collections continue inbound or reach actual shop receipt; do not send them out again through a via-shop retry.
5. Verify the final operational outcome, then Dispatcher closes operational recovery. Pending refund, collected COD, repair/source and loss-investigation obligations remain visible and actionable. No verdict, receipt, inspection or operational closure may fabricate payment, stock movement or full recovery completion.
6. Test legitimate current-assignment/stop activation before window end, then arrival, proof upload/correction, review and completion after the window. Window expiry alone must not block continuation. An unstarted expired immediate authorization needs Dispatcher reauthorization and renewed acceptance; new holds and all other action guards remain effective.

D9 Individual Shop Owner receipt/release remains deferred. Use existing authorized employee workflows only. Where no existing authorized receiver can serve the scenario, retain existing behavior and record **Blocked — deferred Owner physical custody capability**; do not alter permissions, create a fake employee or substitute an unauthorized receiver. Property-value payouts, new settlement accounting, automatic financial reversals and unsupported general replacement are outside this implementation.

## 1. Prepare the environment and accounts

- Ask the developer/QA administrator for the testing **BASE URL**. Do not assume an existing localhost tab serves this worktree. The earlier disposable test listeners were stopped; this document does not start a server or initialize a database.
- The QA environment must have all seven ordered logistics migrations, matching frontend build, private proof storage and working notification processing. Use the [operations runbook](failed-delivery-recovery.md) for developer setup. Do not run production migrations or seeders as part of manual testing.
- Use an approved company shop with Logistics enabled, an operating test date/window, valid source/payment/refund state, and two active available employee riders with capacity and no leave conflict.
- Prepare accounts: **Dispatcher**, **Rider A**, **Rider B**, and the shipment's **Customer**, all for the intended shop/customer. Add **Staff** and **Finance** for return inspection/COD cases. Use another shop/customer account for isolation tests.
- Enroll the employee test accounts in MFA and keep their test authenticator available. Each employee must sign in normally and **Clock In**. Never turn off MFA or attendance to get past a failed step.
- Use permitted synthetic test photos and disposable test orders/repairs/refunds. Do not upload personal documents or collect/refund real money.
- Record the source order/repair/refund number, shipment ID, delivery number, original rider and date/window. Keep using the same identifiers across pages; a new retry offer is not a new source order.

**Primary case starting state:** a shop-owned Retail Delivery, accepted by Rider A, with initial pickup/handoff requirements completed and the parcel already **In transit** with A. Use the normal order/dispatch workflow to reach this state. In **Shipments → Open delivery**, initial assignment uses **Choose available rider → Assign**; A then accepts the delivery and completes the normal pickup/start controls. If a required initial proof or source approval is missing, finish that existing workflow before beginning the failure test.

## 2. Page map

Append each path to your BASE URL. `{shipmentId}` means the actual shipment ID, not the order ID or delivery number.

| Who | Page / navigation | Path | Used for |
| --- | --- | --- | --- |
| All employees | Sign in | `/login` | Password login. |
| Enrolled employees | Two-Factor Authentication | `/erp/mfa/challenge` | Authenticator challenge reached after password login. |
| Employees | Log Attendance / Time In | `/erp/time-in` | **Clock In** before operational actions. |
| Dispatcher | Logistics → Shipments | `/erp/logistics/shipments` | Failure history, retry decision, shop handoff and proof review. |
| Riders A/B | Logistics → My Deliveries | `/erp/logistics/deliveries` | Offers, current work, failure reporting, rider handoffs and retry execution. |
| Dispatcher | ERP notification bell / Notifications | `/erp/notifications` | Failure and recovery notifications. |
| Customer | Notifications | `/notifications` | Customer's own shipment updates. |
| Customer | Shipment tracking | `/tracking/shipments/{shipmentId}` | Failure/retry/completion and permitted proof view. |
| Dispatcher | Logistics → Batches | `/erp/logistics/batches` | Supplemental batch/current-stop checks. |
| Rider | My COD Collections | `/erp/logistics/cod-collections` | Cash still owed by its actual collector. |
| Finance | COD Remittances | `/finance/cod-remittances` | Independent physical cash confirmation. |
| Staff | Job Orders | `/erp/staff/job-orders` | Returned-item inspection in the existing refund flow. |

If a page or button is unavailable to a correctly provisioned account, capture the error and mark the case Blocked. Do not use a Shop Owner account as a substitute for dispatcher/rider mutations; owner views are read-only.

## 3. Login and attendance — repeat for every employee tester

| Step | Current user / page | Action and expected result | Next page |
| --- | --- | --- | --- |
| L1 | Employee — Sign in | Enter the account's password. An enrolled account must reach the MFA challenge, not return to login or open work directly. | Same employee → **Two-Factor Authentication**. |
| L2 | Employee — MFA challenge | Enter the real test authenticator code and choose **Verify**. Invalid codes must not sign in. | Same employee → **Log Attendance / Time In**. |
| L3 | Employee — Time In | Choose **Clock In**, select **Open Camera**, allow camera access, select **Take Photo**, then confirm **Clock In** in the photo dialog. The upstream attendance flow requires a fresh camera photo. Confirm attendance succeeds; do not bypass photo, suspension or attendance errors. | Dispatcher → **Shipments**; rider → **My Deliveries**; Staff → **Job Orders**; Finance → **COD Remittances**, as needed. |

The customer signs in through the normal customer flow and opens **Notifications**; customer attendance is not required.

## 4. Main case — failed delivery, different rider, direct handoff

Use a prepaid Retail Delivery first so COD does not obscure the parcel-recovery test. Keep A's and B's sessions separate.

| Step | Current user / page | Action and expected result | Next page |
| --- | --- | --- | --- |
| M1 | Rider A — **My Deliveries** | Find the prepared in-transit delivery by number. Choose **Report issue**. | Stay on **My Deliveries → Report issue** dialog. |
| M2 | Rider A — Report issue dialog | Choose an **Issue reason**, enter a distinct note such as `QA first failed visit`, and attach an **Issue photo** when required. For `Other`, supply clear notes. Choose **Submit issue** and confirm. Expect success and dispatcher resolution required. A must retain the parcel; the failed visit must not complete delivery. | Customer → **Notifications**. |
| M3 | Customer — **Notifications** | Allow the normal 10/15-second refresh or refresh the page. Check one new failure notice for this shipment and open it. | Same customer → **Shipment tracking** for the same shipment. |
| M4 | Customer — **Shipment tracking** | Verify failed-attempt information is visible, delivery is not marked successful, and internal dispatcher notes/other customers' records are not exposed. | Dispatcher → **ERP Notifications**. |
| M5 | Dispatcher — **ERP Notifications** | Verify the shop's failure notice and open its action link. It should lead to Shipments, with the failure filter/context where supported. Locate the recorded shipment number. | Same dispatcher → **Shipments → Open delivery**. |
| M6 | Dispatcher — **Shipments**, filter **Failed attempts**, then **Open delivery** | Check the failed attempt/reason/photo/time and Rider A's history. In **Resolve delivery issue**, select **Arrange another delivery attempt**. Do not use a normal initial Assign action to skip resolution. | Same dispatcher → **Resolve delivery issue** panel. |
| M7 | Dispatcher — Resolve delivery issue | Set **Retry date**, **Retry window**, **Retry rider = B**, **Handoff route = Direct to assigned rider / same rider keeps parcel**, and **Resolution reason**. For this case use an internal planning date: leave **Customer confirmed this date and window** unchecked only when no binding appointment exists. Choose **Authorize retry**. Expect a new offer; A still has custody. | Rider B → **My Deliveries**. |
| M8 | Rider B — **My Deliveries** | Find the new offer, choose **Accept delivery** and confirm. Acceptance must not mean parcel receipt. Refresh if the offer has not arrived yet. | Stay on **My Deliveries → Current delivery / Package handoffs**. |
| M9 | Rider B — My Deliveries | Check **Start retry** is disabled while the handoff is outstanding. B must not submit completion proof or collect this parcel's COD before possession. | Rider A → **My Deliveries → Package handoffs**. |
| M10 | Rider A — Package handoffs | Physically hand the test parcel to B. Select **Handoff photo → Confirm release**. Expect **Released — awaiting receipt**. Release alone must not enable B to start. | Rider B → **My Deliveries → Package handoffs**. |
| M11 | Rider B — Package handoffs | Before receipt, verify start is still blocked. Once physically holding the parcel, select a receipt **Handoff photo → Confirm receipt**. A and B must use their own accounts. | Same Rider B → **My Deliveries → Current delivery**. |
| M12 | Rider B — Current delivery | For an otherwise eligible internal planning retry, choose **Start retry** and confirm. Expect in-transit work without recording a fictional second shop pickup. If a server block remains, record the reason instead of bypassing it. | Stay on **My Deliveries** at the delivery destination. |
| M13 | Rider B — My Deliveries | Choose **I've arrived** when shown and allow normal location checking. If an arrival exception is requested, record the actual supported reason/notes. Attach the **Delivery proof photo** and choose **Submit delivery proof**, then confirm. Expect proof awaiting dispatcher review. | Dispatcher → **Shipments**, filter **Awaiting Proof Approval**, then **Open delivery**. |
| M14 | Dispatcher — delivery proof section | View the original proof for the correct shipment. Choose **Confirm delivery** and confirm only after checking the test handoff. Expect delivery completion. | Customer → **Notifications**, then **Shipment tracking**. |
| M15 | Customer — Notifications / tracking | Verify the completion update and correct final state. Open the permitted delivery proof. Refresh repeatedly: no extra failure/completion notices should be manufactured. | Dispatcher → **Shipments**, filter **Completed**, then **Open delivery** for final history review. |
| M16 | Dispatcher — completed delivery details | Confirm the original failed visit, new rider/offer, release and receipt actors/times, resolution and completion proof remain visible where projected. Record IDs and evidence. | **Test result sheet** at the end of this guide. |

**Pass:** the same shipment recovers through a new accepted offer, B cannot start before receipt, and completion still requires the existing proof/review workflow. Assignment alone never moves custody.

## 5. Same-rider retry

Dispatcher photo check: after M2, open **Shipments → Open delivery** for the same shipment. When private issue evidence is available, the preview shows **View issue photo** instead of **Waiting for rider**. Open it to review the photo; this is issue evidence, not proof of successful delivery. Incident evidence links and failed-attempt photo links remain available. If no evidence URL is returned, verify the private uploaded file still exists on the serving host; the preview cannot recover missing files.

Use a separate prepared delivery and repeat M1–M6.

| Step | Current user / page | Action and expected result | Next page |
| --- | --- | --- | --- |
| S1 | Dispatcher — Resolve delivery issue | Select **Rider A** and the direct/same-rider route, valid date/window and reason; **Authorize retry**. | Rider A → **My Deliveries**. |
| S2 | Rider A — My Deliveries | Accept the new offer. A's already confirmed possession remains; no A-to-A release/receipt should be invented. | Same rider → **Current delivery → Start retry**. |
| S3 | Rider A — Current delivery | Start when eligible. Complete M13–M16, or use the repeated-failure case below. | Dispatcher → **Shipments → Open delivery** for review. |

## 6. Different rider through the shop

Repeat M1–M7 on another delivery, but select **Handoff route = Return to shop, then release to assigned rider**. This is a custody-transfer route for a retry; it is different from choosing the final **Return to shop** resolution.

| Step | Current user / page | Action and expected result | Next page |
| --- | --- | --- | --- |
| H1 | Rider B — My Deliveries | Accept the new offer. Start remains blocked. | Rider A → **My Deliveries → Package handoffs**. |
| H2 | Rider A — Package handoffs | Bring the parcel to the shop; attach evidence and **Confirm release**. | Dispatcher → **Shipments → Package handoffs**. |
| H3 | Dispatcher — Package handoffs | Independently inspect the physical handoff; attach a photo and **Confirm receipt**. Shop custody is now recorded; B still cannot start. | Stay on **Shipments → Package handoffs**, refresh if necessary. |
| H4 | Dispatcher — next outbound handoff | Hand the parcel to B; attach evidence and **Confirm release** for Shop → B. This must be a second transfer. | Rider B → **My Deliveries → Package handoffs**. |
| H5 | Rider B — Package handoffs | Attach receipt evidence and **Confirm receipt** only after taking possession. | Same rider → **Current delivery → Start retry**, then M13–M16. |

**Pass:** both transfers have release and receipt; shop receipt alone does not authorize B's possession.

## 7. Planning dates versus customer-confirmed appointments

Use two separate failed deliveries and choose future operating dates with an otherwise eligible rider. Keep existing customer commitments accurate.

| Case | Current page and action | Expected result | Next page |
| --- | --- | --- | --- |
| Internal planning date | Dispatcher — **Resolve delivery issue > Arrange another delivery attempt**: choose a future date without declaring a customer appointment. Finish the required offer acceptance/handoff. | An eligible rider may start early; the future planning date alone must not block. Availability, custody, attendance, source and module rules still apply. | Assigned rider → **My Deliveries → Start retry**. |
| Confirmed appointment | Dispatcher — **Resolve delivery issue > Arrange another delivery attempt**: tick **Customer confirmed this date and window** and enter the agreement note. Finish offer acceptance/handoff. | Before that appointment window, **Start retry** remains disabled even after receipt. | Assigned rider → **My Deliveries** at the agreed date/window, or dispatcher → **Shipments → Open delivery** to arrange an authorized reschedule where available. |
| Attempted unagreed change | Dispatcher — retry dialog on a case where retry resolution is available: attempt to move an existing appointment without recording agreement. | Validation must reject the change. If a handoff/offer is still outstanding, resolve that existing state first through its permitted controls; do not assume every state can be edited immediately. | Stay on the same dialog to record genuine test customer agreement, or mark Blocked with the actual state/error. |

Do not change device/server clocks or edit database appointment/custody fields. A valid start at the intended future time can be recorded as Not run until that time is reached.

## 8. Another failure after retry

| Step | Current user / page | Action and expected result | Next page |
| --- | --- | --- | --- |
| F1 | Assigned rider — **My Deliveries**, after Start retry | **Report issue** again with `QA second failed visit`, the appropriate reason and evidence. Submit/confirm. | Dispatcher → **Shipments → Failed attempts → Open delivery**. |
| F2 | Dispatcher — delivery details | Verify two distinct failed visits/counts with preserved first-visit information. A duplicate click/reload must not create a third attempt. The second failure must not silently reuse the first visit or auto-authorize another retry. | Dispatcher → **Resolve delivery issue** for another explicit decision, or **Return to shop** if appropriate. |

Reaching an attempt limit requires explicit resolution/escalation. It must not automatically approve a refund, clear cash liability or mark a parcel delivered. Existing Repair Pickup terminal compensation rules are source-specific; do not generalize Retail Delivery behavior to exhausted repair collections.

## 9. Cover the four shipment purposes

Run the main outbound sequence for both Retail Delivery and Repair Return. Run the collection sequence for both Repair Pickup and Retail Return.

| Purpose | Required starting source | Recovery / completion pages | Next page after physical completion |
| --- | --- | --- | --- |
| Retail Delivery | Valid shop-owned order, ready/shipped through the existing Staff workflow. | Rider **My Deliveries → Report issue**; dispatcher **Shipments → Failed attempts → Retry delivery**; handoff if rider changes; **Start retry**, proof and **Confirm delivery**. | Customer **Shipment tracking**, then dispatcher **Completed**. |
| Repair Return | Completed/released repair with required payments or approved sponsorship and shop-owned return method. | Same outbound failure/retry sequence. A release/payment validation failure is a setup/result to investigate, not permission to skip payment. | Customer **Shipment tracking** and the repair's existing completion view. |
| Repair Pickup | Authorized customer collection, accepted rider, nothing collected yet. | Rider **My Deliveries → Failed pickup**; dispatcher **Shipments → Failed pickups → Open delivery → Reschedule Pickup**. New rider accepts, collects through **Confirm pickup**, then **Start delivery** to the shop and existing proof/review. Record the current pickup arrival using the button below first. | Dispatcher **Shipments** for proof review, then the existing repair intake workflow. |
| Retail Return | Existing approved refund/return with shop-owned customer collection arranged through Staff; no duplicate refund request. | Same failed-pickup/reschedule sequence. At the shop rider uploads **Return handoff photo → Confirm return handoff**; authorized dispatcher uses **Shipments → Open delivery → Confirm return received**. | Staff **Job Orders → returned-item inspection**; Finance continues only through existing approval/payout rules. |

### Pickup arrival before a failed customer collection

For a Repair Pickup or Retail Return that is still assigned, use **My Deliveries → I've arrived for pickup** at the actual customer collection location. This records arrival for the current assignment; it does not claim possession. **Next: same page → Failed pickup → reason / notes / photo → Submit failed pickup**, or **Confirm pickup** only after the actual handoff. A previous assignment's arrival does not satisfy a new retry. **Next after a failed visit: Dispatcher → Shipments → Failed pickups → Open delivery → Reschedule Pickup**.

The earlier browser record used an authenticated arrival API request. The batch extension adds this missing button; only the fresh batch browser results establish its click-through coverage.

## 10. Return instead of retry, and real inspection

| Step | Current user / page | Action and expected result | Next page |
| --- | --- | --- | --- |
| R1 | Dispatcher — **Shipments → Failed attempts → Open delivery** | Select **Return parcel to shop** in **Resolve delivery issue**, enter **Decision reason**, then choose **Authorize return**. Only use an eligible unresolved case; finish/cancel any permitted outstanding handoff first. | Actual parcel holder → **My Deliveries** for the return work. |
| R2 | Actual holder — My Deliveries | Follow the return work's displayed pickup/start requirements. Use **Start return to shop** when offered. At the shop attach **Return handoff photo → Confirm return handoff**. The former rider must still have a custody hold until receipt. | Dispatcher → **Shipments → Open delivery**, return leg/proof. |
| R3 | Dispatcher — return proof | Physically receive the parcel and choose **Confirm return received**. The parcel receipt must not automatically mark every item resellable. | Staff → **Job Orders**, the source order/refund. |
| R4 | Staff — Job Orders | Select the **Refund** tab, locate the source order, then choose **Inspect returned items** on the compact card (or **Confirm returned item received** on the desktop row). In **Inspect Returned Items**, inspect each line's **Inspection Disposition** and select the actual **Resellable (restock)** or **Damaged (write-off)** result; choose **Save inspection and confirm return** only when accurate. Missing/partial quantities must follow the existing supported assessment path; if it cannot represent the actual result, stop and report instead of pretending full receipt. | Finance → existing refund approval/payout workflow, only when the normal prerequisites are met. |

Shop-mediated rider replacement (Section 6) does not itself create a return/refund inspection. This section tests a return resolution and its source-specific settlement flow.

## 11. COD responsibility check

Use a separate legitimate QA COD scenario; record only cash actually exchanged as test cash.

| Step | Current user / page | Action and expected result | Next page |
| --- | --- | --- | --- |
| C1 | Rider A — **My Deliveries** | Where legitimately collected before the subsequent exception, use **Cash collected** for the exact displayed amount. Then exercise failure/reassignment through the normal resolution flow. Do not record fictitious collection merely to advance a test. | Rider A → **My COD Collections**. |
| C2 | Rider A — My COD Collections | Check the original collection remains A's obligation after B receives the parcel. | Rider B → **My Deliveries / My COD Collections**. |
| C3 | Rider B — My Deliveries / collections | Verify B is not asked to collect the same paid amount again and A's collection did not become B's debt. | Rider A → **My COD Collections**. |
| C4 | Rider A — My COD Collections | Select the appropriate test collection and submit the displayed selected cash amount. Parcel transfer must not settle the remittance. | Finance → **COD Remittances**. |
| C5 | Finance — COD Remittances | Confirm the actual received amount using **Confirm physical cash**. Exact received cash may settle; a shortage must remain disputed under the existing process. | Rider A → **My COD Collections**, then **Test result sheet**. |

## 12. Negative checks and troubleshooting

Use separate test cases so an expected denial does not corrupt the main successful case.

| Check | Current page / action | Expected result and next page |
| --- | --- | --- |
| Assignment without receipt | B → **My Deliveries** after accepting a replacement offer. | Start remains blocked. **Next: A → Package handoffs**, then B receipt. |
| Cancel before release | Dispatcher → **Shipments → Package handoffs → Cancel unreleased handoff and retry offer**; enter **Cancellation reason → Cancel handoff**. | Transfer/offer canceled, actual holder unchanged, resolution required. **Next: Shipments → Open delivery → Retry delivery**. |
| Cancel after release | Dispatcher → Package handoffs after A's release. | Cancellation must not erase a physical release; finish receipt or investigated incident resolution. **Next: actual recipient → Package handoffs**. |
| Wrong person / shop | Another account opens the recorded shipment/proof URL. | No unauthorized access or confirmation. **Next: correct user → appropriate page**; log any leaked data as Fail. |
| Attendance | An employee without active attendance attempts a mutation. | Must be denied. **Next: Log Attendance / Time In → Clock In**, then return to the original page. |
| Ineligible rider/date | Dispatcher → retry dialog; select a rider on leave/off-duty or unavailable date. | Server validation rejects it; no partial offer/transfer. **Next: same dialog**, choose valid facts or record a setup blocker. |
| Proof correction | Dispatcher → pending proof → **Reject proof**, with reason. | Completion stays pending correction. **Next: rider → My Deliveries → Issues → Submit replacement proof**, then dispatcher reviews the new proof. |
| Incident | Rider → **My Deliveries → Report incident** with real test type, notes and evidence. | **Next: Dispatcher → Shipments → Open delivery**, incident resolution. Dismissal restores only a compatible prior state; loss needs investigation and does not auto-transfer cash/payout. |
| Logistics OFF | Authorized QA administrator disables the module on a separate in-progress case. | New dispatch/retries denied; an already-released, eligible receipt may finish. **Next: actual recipient → Package handoffs**. Shop receipt while OFF must not auto-create outbound work; after re-enabling, **next: dispatcher → Shipments** for explicit new authorization. |
| Unknown legacy custody | Dispatcher → retry on an ambiguous historical parcel. | Must not infer possession from old assignment/photos. **Next: existing evidenced return/investigation workflow**, or mark Blocked if history cannot support it. |
| Batch ordering | Rider → **My Deliveries** with a batch. | Only the active eligible stop can execute; earlier failures must not create duplicate/ghost batch work. **Next: dispatcher → Batches** to inspect membership and history. |
| Notification delay | Customer/dispatcher → Notifications after a saved action. | Refresh after normal polling; no repeated notices from simple reload. If absent, capture action time and IDs. **Next: developer/QA administrator** checks the existing scheduler, without rerunning financial jobs or changing recipients. |

If **Start retry** remains disabled, check the displayed reason, offer acceptance, final receipt, confirmed appointment window, attendance, rider availability, active-stop order, source/payment state and Logistics status. Open **Dispatcher → Shipments → Open delivery** to inspect the same case. Do not treat a disabled button as permission to force the request.

## 13. Batch vehicle breakdown: page-by-page test

Use a disposable shop with Logistics ON, eligible clocked-in riders A/B and an authorized clocked-in dispatcher. Log in normally, including MFA. Prepare three ordered stops through the existing **Batches** offer/accept workflow. Repeat before Start batch, after one completed stop and with one remaining stop. Use permitted synthetic evidence; do not manufacture a customer failure to simulate vehicle trouble.

| Step | Current user / page | Action and expected result | Next page |
| --- | --- | --- | --- |
| B1 | A → **My Deliveries**, accepted or in-progress batch | Choose **Report vehicle breakdown**, enter **Breakdown details**, attach **Vehicle evidence**, then **Hold remaining stops**. The batch shows interrupted. Completed stops, pending proof reviews and prior separate failures stay intact; no unvisited stop becomes a failed attempt. | Dispatcher → **Notifications → Delivery Route Interrupted**, opening **Batches** and the affected incident. |
| B2 | Dispatcher → **Batches → batch details** | Review affected stops, actual parcel holders and private evidence. Confirm correct shop, stop order and source/recovery history. | Same dialog → **Resolve interruption**. |
| B3a | Dispatcher → **Resolve batch interruption** | Select **Resume with the same rider**, enter **Decision reason**, **Save recovery decision**. Accepted work stays accepted; in-progress work retains its real stage. | A → **My Deliveries**, refresh; **Start batch** only if still accepted, otherwise continue the existing current stop. |
| B3b | Dispatcher → **Resolve batch interruption** | Select **Assign a replacement rider**, eligible B, recovery date/window, direct or shop route and reason. Record each displayed customer's agreement if changing a confirmed appointment. Save. | B → **My Deliveries → Accept batch**. |
| B4 | B → **My Deliveries** | Accept the recovery batch. Start stays blocked while any required parcel is outstanding. Assignment and acceptance do not transfer possession. | A → **My Deliveries → Package handoffs**. |
| B5 direct | A → **Package handoffs**, each affected parcel | Upload **Handoff photo → Confirm release**. Repeat per parcel. | B → **My Deliveries → Package handoffs → Handoff photo → Confirm receipt**, each parcel. |
| B5 shop | A → **Package handoffs** | Release each parcel with photo. | Dispatcher → **Batches → Package handoffs → photo → Confirm receipt**, then photo → **Confirm release** for the outbound transfer; next B → **My Deliveries → Package handoffs → photo → Confirm receipt**. |
| B6 | B → **My Deliveries** after partial receipt | Refresh. Check the confirmed/required count. **Start batch** must remain disabled; a stale start must not move any stop. | Complete the remaining **Package handoffs** with the actual participants, then B → **My Deliveries**. |
| B7 | B → **My Deliveries**, all required parcels received | **Start batch** becomes available only with current dispatch authorization and ordinary eligibility. Confirm start. The source batch becomes recovered with a recovery-batch reference. | Same page → current stop, in dispatcher order. |
| B8 | Assigned rider → current stop | For uncollected work use real pickup/required proof; customer collection can use **I've arrived for pickup**. For already-collected work do not record another source pickup. Use **Start delivery** / **Start return to shop**, arrival and original proof/handoff controls. | Dispatcher → **Shipments → Open delivery → Confirm delivery** or **Confirm return received** as appropriate. |
| B9 | Dispatcher → proof/receipt review | Inspect private evidence and confirm only actual delivery/receipt. Customer and dispatcher updates must survive refresh without duplicates. | Customer → own **Shipment tracking**; for Retail Return next Staff → **Job Orders → item inspection**, then the existing Finance approval/payout workflow. Repair Pickup continues through normal repair intake. |
| B10 | Dispatcher → **Batches → Batch history** | Verify original immutable stops and recovered lineage; completed deliveries must not be described as failed or moved to a new source. | Replacement rider → **My Deliveries** for remaining work, or record the final result below. |

Safekeeping alternative: at B3 select **Return to shop for safekeeping**, save the reason, then A releases each actual parcel and Dispatcher receives it in **Package handoffs**. **Next page: Dispatcher → Batches → Resolve interruption** for investigation or later authorized continuation. Receipt must not cancel orders, approve a refund, restock items or automatically create outbound work. If safe possession/transport is unknown, select **Keep held and escalate for investigation**; next stay in the same incident review rather than altering custody.

Repeat B1 on a resumed or recovery batch to test a new incident occurrence. Check that a new dispatcher/customer notice appears, while refreshing or double-clicking the same report/decision does not duplicate it.

OFF/ON negative case: an authorized QA operator changes the module on a separate disposable case. While OFF, new offers/resume/outbound release must fail; already-released receipt may close. After ON, a replacement must remain blocked until Dispatcher → **Batches → Resolve interruption → Authorize replacement continuation**. **Next: actual participants → Package handoffs** if anything remains, then B → **My Deliveries → Start batch**. Confirmed customer appointments still apply at each current stop. Never skip an earlier appointment-bound stop to execute a later stop.

COD check: record a legitimate prior collection with A, perform the parcel takeover and inspect the existing remittance view. The same collection belongs to A; B must not collect twice or inherit the cash debt. **Next page: A → existing remittance workflow**, with normal Finance verification. A vehicle interruption alone must not alter a refund, payout, compensation or inventory state.

## 14. Record the manual results

| Field | Tester entry |
| --- | --- |
| Date / tester / BASE URL / build or branch identifier | |
| Case: main / same rider / shop-mediated / appointment / repeat / return / COD / negative | |
| Source type and ID / shipment ID / delivery number | |
| A / B / dispatcher test accounts, shop | |
| Current page and step reached | |
| Expected next page | |
| Observed status / next page / exact error | |
| Failure count and relevant proof/transfer identifiers | |
| Customer and dispatcher notification observations | |
| Pass / Fail / Blocked / Not run | |
| Synthetic/redacted screenshot reference and issue reference | |

Do not attach passwords, TOTP/recovery codes, cookies, CSRF tokens, real customer photos or raw private evidence. Record blocked and untested cases honestly. The [verification report](2026-10-23-failed-delivery-verification-gaps.md) retains three pre-existing route-catalog test failures and target-host checks; this guide does not turn them into passed manual tests.


## Usability follow-up - October 24, 2026

See the [implementation and verification record](2026-10-24-logistics-usability-execution.md) for the U01-U15 corrections and paired screenshots. The original audit remains historical evidence.

| Current page / actor | What to check | Next page / action |
| --- | --- | --- |
| Dispatcher > Shipments > Resolve delivery issue > Arrange another delivery attempt | Use the calendar; Escape closes only the calendar. Check configured window hours, actual holder, candidate capacity and any customer agreement. Assignment does not transfer custody. | Assigned rider > My Deliveries > Accept delivery. |
| Rider > My Deliveries | Accept the retry offer first. Read the reason beside a disabled start; use Package handoffs for each outstanding parcel. An idle visible page refreshes delivery/custody props. A selected photo, note or open dialog pauses that refresh. | Named releaser/recipient > Package handoffs, then assigned rider > Start retry / Start batch. |
| Rider / dispatcher > Package handoffs | Match order/repair, shipment, route stage and participants. Replace an invalid photo after its inline error; confirm only actual physical release/receipt. Check the success announcement and remaining parcels. | Other named participant > their own Package handoffs; rider > Start only after all requirements clear. |
| Customer > Notifications | Search and Unread only are always available. Tools and Advanced Filters start collapsed on compact screens; open Tools for archive/export/settings/bulk actions. Use Tab and Enter on the notice title; delivery notices appear under Delivery Updates when not already categorized. Internal parcel transfers are not customer tasks. | Same customer's Shipment tracking. |
| Customer > Shipment tracking | A held route says Delivery delayed above the old estimate, hides misleading movement/map, and explains whether the shop is arranging recovery or awaiting transfer. | Wait for continuation notice; contact the shop if clarification is needed. |
| Staff > Job Orders > Refund | Below desktop width, identify the order/item/returned quantity on its card. Open Inspect returned items and explicitly select resellable/restock or damaged/write-off. Receipt alone does not inspect stock. | Existing Finance approvals, only when eligible. |
| Finance > Refund details / Execute payout | Tab remains in the named dialog; Escape returns focus when safe. During execution, Cancel/close/Escape must not dismiss the pending request. The screenshot privacy blocker remains unresolved. | Existing approved payout confirmation; do not record a real payment for a synthetic test. |
| Finance > COD Remittances | A failed read shows an error and Retry loading remittances, not an empty ledger. Previously loaded amounts are labelled stale; neither primary nor supplemental confirmation is available until reload succeeds. | Retry the read, then existing physical-cash confirmation. |


Later release evidence: the route-catalog failures described in the earlier verification-gap report were corrected and rerun in the [October 24 release-readiness report](2026-10-24-logistics-release-readiness.md). The [usability execution report](2026-10-24-logistics-usability-execution.md) records this follow-up's separate results and limitations. Neither report authorizes a production deployment.


## Unified delivery issue resolution - October 25, 2026

On **Dispatcher → Shipments → Open delivery**, review the reported issue and private photo, then use the single **Resolve delivery issue** panel. The separate Retry/Return shortcuts are removed when an incident is unresolved. Batch-held shipments continue through **Batches → Resolve interruption**; do not resolve individual stops to bypass that hold.

| Selected action | Required information / meaning | Next page / action |
| --- | --- | --- |
| Issue resolved — continue delivery | Incident-only option. Record the decision reason and investigated responsibility (default **Undetermined**). No new offer is created; the backend must validate restoration of the previous state. | Same rider → **My Deliveries**, ordinary eligibility and existing delivery controls. |
| Arrange another delivery attempt | Schedule, eligible rider, handoff route, reason and any required customer agreement appear inline. For an incident, the same save resolves the incident and authorizes recovery; its responsibility selection is retained. Choose **Authorize retry**. | Selected rider → **My Deliveries → Accept delivery**, then the named participants → **Package handoffs** before eligible start. |
| Return parcel to shop | Record **Decision reason**, then **Authorize return**. Incident responsibility is recorded when applicable. | Actual holder → existing return workflow; dispatcher → physical receipt. Receipt does not inspect stock or approve a refund. |
| Confirm parcel lost | Available only for a reported lost incident. Require investigation reason and evidence; use only an actual investigated finding. Existing source-specific loss/financial rules still apply. | Existing authorized exception and financial review, as required by the source workflow. |

While a decision is saving, action changes and closing the delivery details are blocked. Failed saves retain the form and display the server error. Successful saves announce the decision and refresh the existing shipment/custody data. Failed customer pickups retain their separate **Reschedule Pickup / Cancel Pickup** workflow and required arrival checks.

## Confirmed parcel loss — paid Retail Delivery

Use a synthetic company-shop online-paid order. This case does not require a fictional return, receipt or inspection. Normal source-specific COD collection/remittance holds remain in force; this is not permission to transfer cash responsibility or release an unsettled COD refund.

| Step / current page | Action / expected result | Next page |
| --- | --- | --- |
| Rider → My Deliveries | **Report incident → Parcel lost**; enter the actual test facts, upload private evidence, then submit/confirm. | Dispatcher → **Shipments**. |
| Dispatcher → Shipments list | The affected card shows **Incident needs review** and **Review incident** before opening details. | **Review incident → Resolve delivery issue**. |
| Dispatcher → incident review | After investigation, select **Confirm parcel lost**, enter the decision reason and investigation evidence, then confirm. Loss history remains; no unvisited attempts, successful delivery or stock restoration is fabricated. | Staff → **ERP Notifications**, then **Retail Job Orders → Refund tab → View order details**. |
| Staff → order details | New company loss claims start with **Awaiting Staff Review** and **Approve Refund Eligibility / Reject Refund** actions. Valid older system-created loss claims are reviewable without inventing a previous Staff approval. Record only a real eligibility decision. **Activate Receive must be absent**; a stale activation request must be rejected. | Finance → **Refund Approvals**. |
| Finance → refund details | After Staff approval, **Approve** becomes available. Before Staff review, a visible legacy request explains that Staff must decide first. Follow the snapshotted owner approval and Finance finalization stages when required. | Required owner approval → Finance finalization, or **Execute Payout** when all ordinary payout requirements pass. |
| Finance → payout-ready details | A confirmed-loss claim requires no physical return/inspection. Verify the correct amount and source; execute only through the existing authorized provider workflow in an approved payment test environment. This manual guide does not authorize a real payout. | Customer refund update and existing provider reconciliation. |

Staff receive shop-scoped incident/loss notices; the initial report is informational while the dispatcher investigates. New company loss refund reservations send the Staff eligibility-review notice, and an actual Staff decision forwards the request to Finance. Replaying the same logistics occurrence must not duplicate its notice. Previously delivered historical events are not automatically replayed by this change. No bulk production repair or automatic approval is performed.


## 15. Rejected confirmed-loss refund: owner reconsideration

This path applies to a company shop's confirmed parcel-loss claim, not an already attempted provider payout. Rejecting the claim does not authorize payment or restore stock. The original rejection stays recorded; employees cannot reopen it themselves.

| Step | Current actor / page | Action and expected result | Next page |
| --- | --- | --- | --- |
| O1 | Staff > Retail Job Orders > Refund, or Finance > Refund Approvals | Reject the loss claim with the real reason. Staff sees Awaiting Owner Review; Finance explains owner reconsideration. | Shop Owner > notification bell > Rejected loss refund needs review. |
| O2 | Shop Owner > Approval Center (`/shop-owner/action-center`) > Pending > Refunds | Open the rejected claim. Read its original reason, reviewer, amount and evidence/notes. This review is available even when normal owner monetary approval is optional. | Same details > Correction instructions. |
| O3 | Shop Owner > claim details | Enter 10?1000 characters explaining what Staff must reassess. Choose **Send back for correction**, then confirm. This creates one linked request and preserves the rejected one; it does not approve money. An identical retry must not duplicate it. | Staff > notification bell > Refund Eligibility Review Required > Retail Job Orders > Refund. |
| O4 | Staff > Retail Job Orders > Refund > View order details | Read **Owner correction instructions** and the rejected predecessor number. Reassess evidence and record a new approval or rejection. Do not activate customer receipt or invent inspection/return for a lost parcel. | If approved: Finance > Refund Approvals. If rejected: Shop Owner > Approval Center for another explicit decision. |
| O5 | Finance > Refund Approvals | Review the new claim using normal permissions and attendance. Required owner approval, captured-payment limits, COD settlement and payout controls still apply. Another rejection returns to O1; it never auto-approves or ends the correction path. | Existing owner approval / Finance payout flow, as required by the claim's policy. |
| O6 | Shop Owner > Approval Center > History > Refunds | Open the old rejected claim. Verify correction instructions, preserved rejection/reviewer and linked correction request ID. | Follow the new request through Staff > Retail Job Orders > Refund, then Finance > Refund Approvals. |

If another active refund already reserves the payment, or the old claim has provider execution evidence, correction is refused. Investigate the existing reservation/payment workflow rather than creating a second refund. Customer-facing notices must not expose the owner's internal correction instructions. See the [verification record](2026-10-25-loss-refund-reconsideration.md).
