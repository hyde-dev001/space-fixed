# Customer Report Evidence and Logistics-Specific Refund Routing

**Date:** 2026-08-23
**Status:** Approved design; implementation pending

## Context

The customer currently has both `REPORT ORDER` and `REFUND` actions on delivered orders. They represent different operational paths:

- Shop-owned logistics needs a dispatcher-led investigation for delivery disputes.
- Third-party logistics already has a legacy direct refund/return workflow that goes to staff and finance.

The new customer delivery dispute also needs evidence, especially a parcel-opening video. The existing refund form already uses an exact media requirement of five images and one video.

## Goals

1. Require customer evidence when submitting a Shop-owned delivery dispute.
2. Reuse the existing refund media interaction and validation rules: exactly five images and one video.
3. Let the dispatcher review the evidence before resolving the dispute.
4. Carry the same evidence into the existing staff refund/return approval workflow when the dispatcher selects `refund_required`.
5. Remove the duplicate customer entry points by routing the actions according to logistics type.
6. Preserve the existing third-party delivery, refund, and return workflow unchanged.

## Non-goals and constraints

- Do not modify third-party delivery or return behavior.
- Do not implement replacement workflow; `replacement_required` remains unavailable in the current dispatcher resolution choices.
- Do not change the authoritative delivery event. Dispatcher-approved rider proof still controls `order.status = delivered`.
- Customer acknowledgement remains separate from delivery status.
- Do not add an Action Center dependency.

## Customer action routing

The customer-facing actions are carrier-specific:

| Logistics type | Customer action | Operational owner |
| --- | --- | --- |
| Shop-owned logistics | `REPORT ORDER` | Dispatcher investigation |
| Third-party logistics | Existing `REFUND` | Existing staff/finance refund workflow |

For Shop-owned orders, the direct `REFUND` action is hidden so the customer cannot create a second workflow for the same damaged, incomplete, wrong-item, or non-receipt concern. For third-party orders, the existing `REFUND` action remains available and its endpoint, evidence handling, and return flow are not changed.

The backend remains authoritative regardless of UI visibility:

- An active delivery dispute blocks a competing refund request.
- An active refund request blocks a new delivery dispute.
- A customer can submit only one active dispute for an order.

## Customer dispute evidence

`REPORT ORDER` opens the same media-selection experience used by the refund form. The customer must submit:

- exactly 5 images;
- exactly 1 video showing the parcel opening;
- image types: JPG, JPEG, PNG, or WEBP;
- video types: MP4, MOV, AVI, MKV, or WEBM;
- maximum image size: 20 MB per file;
- maximum video size: 256 MB.

The client validates the selection for immediate feedback. The server repeats all validation and never trusts the client count, MIME type, extension, or file size.

The report request remains a multipart request containing the reason, optional notes, and `media[]`. If the report is rejected by ownership, deadline, duplicate, payment/refund, or media validation, no new dispute is created.

## Evidence storage and authorization

Dispute evidence is stored separately from refund evidence under a dispute-specific path and recorded in a JSON evidence field on `delivery_disputes`. The record contains only safe metadata needed to identify each file (media identifier, storage path, MIME type, and original filename); raw storage paths are never returned to the browser.

Evidence viewing uses an authorized application endpoint. It must verify:

- the authenticated actor is an authorized logistics dispatcher or staff user;
- the actor belongs to the same shop as the dispute;
- the dispute and requested media belong to the same order.

Customer-submitted media is not made publicly downloadable by exposing a storage URL. Existing third-party refund evidence storage remains untouched.

## Dispatcher flow

The shipment page shows the dispute evidence in the customer dispute card, with image thumbnails and a video control/modal. Evidence is viewable while the dispute is `open`; the dispatcher does not need to resolve the dispute before reviewing it.

The existing investigation gate remains:

```text
open
  -> Start investigation
  -> investigating
  -> Resolve customer dispute
```

The dispatcher can still choose the currently supported resolutions:

- `customer_confirmed` only for `item_not_received`;
- `refund_required` / `Refund / Return required`;
- `report_rejected`.

The original order remains delivered. The dispute status and customer receipt status continue to represent the customer-side exception separately.

## Refund-required handoff to staff

When the dispatcher resolves a Shop-owned dispute as `refund_required`:

1. The existing `OrderRefund` request-approval workflow is created.
2. The dispute is linked to that refund record.
3. The six customer evidence references are copied/linked into the refund payload used by staff.
4. Staff can view the five images and the opening video in the Job Order refund review before approving or rejecting the refund/return.
5. Existing staff approval, return logistics, item inspection, and finance release continue unchanged.

The evidence remains attached for audit after the dispute is resolved. It does not alter `order.status`.

## Failure handling

- Missing or invalid evidence returns a validation error and leaves the order/dispute unchanged.
- Evidence storage failures clean up files already written by the failed request where possible.
- A repeated submission for an already-active dispute returns the existing dispute and does not create another record or duplicate evidence.
- A dispute resolution failure does not partially create a refund request or change the dispute status.
- Existing third-party refund failures and validation behavior remain covered by regression tests.

## Verification plan

### Backend

- Shop-owned report requires exactly five images and one video.
- Missing, wrong-count, wrong-type, oversized image, and oversized video submissions are rejected.
- Valid evidence is stored and associated with the dispute without changing the delivered order status.
- Customer cannot view dispatcher/staff-only evidence endpoints.
- A same-shop dispatcher can view evidence; a dispatcher from another shop cannot.
- `refund_required` copies evidence to the staff refund payload.
- Staff payload renders evidence metadata for the resulting refund request.
- Active dispute/refund duplicate guards remain enforced.
- Third-party refund and return regression tests remain green.

### Frontend

- Shop-owned orders show `REPORT ORDER` and do not show direct `REFUND`.
- Third-party orders preserve the existing `REFUND` action and do not use dispatcher report routing.
- The report uploader enforces the five-image/one-video rule before submission.
- Dispatcher evidence previews show both images and video.
- Staff Job Order refund review shows the propagated evidence after `refund_required`.
- Investigation is still required before the dispatcher can resolve a dispute.

## Acceptance criteria

The change is complete when a Shop-owned customer can submit a report with five images and one parcel-opening video, a same-shop dispatcher can review and investigate that evidence, and a `refund_required` resolution makes the same evidence available to staff before refund/return approval—while the existing third-party refund/return flow remains behaviorally unchanged.
