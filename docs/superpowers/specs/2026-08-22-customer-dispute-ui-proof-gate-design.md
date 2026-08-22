# Customer dispute resolution, proof preview, and investigation gate

## Approved behavior

The dispatcher shipment page will expose only the resolutions that have a supported workflow, and the choices will be constrained by the reported reason:

- `customer_confirmed` — available only for `item_not_received`, when the investigation establishes that the customer has now received the order.
- `refund_required` — displayed as **Refund / Return required** because the existing refund workflow requires the item to be returned before inspection and refund processing.
- `report_rejected` — closes the dispute as rejected.

For `damaged`, `incomplete`, `wrong_item`, and `other`, the dispatcher must choose **Refund / Return required** or **Reject report**. A physical-condition or unspecified complaint must not be resolved as a receipt confirmation.

`replacement_required` remains supported in the backend for compatibility with existing records, but is not offered as a new UI choice until a replacement workflow exists. The legacy `return_required` value is also retained for compatibility; new cases use `refund_required` and the combined label.

An open dispute must first be moved to `investigating` with **Start investigation**. Only an investigating dispute may show **Resolve** in the shipment UI, and the service enforces the same state transition rule server-side.

Staff Job Orders already receives the rider proof metadata and file URL. Its proof thumbnails will become accessible buttons that open the same image-preview modal pattern used by the Shipment page. No new storage or delivery-proof endpoint is introduced.

## Acceptance criteria

1. The resolution selector does not offer replacement or a separate return option.
2. The resolution selector offers `Customer confirmed` only for `item_not_received`; other reasons offer only Refund / Return required or Reject report.
3. The service rejects `customer_confirmed` for any reason other than `item_not_received`.
4. Resolve is hidden while a dispute is open and appears only after investigation starts.
5. A direct resolve request for an open dispute is rejected and leaves the dispute open.
6. Staff can click a delivery-proof thumbnail on Job Orders and view the full image in a modal, with an accessible close action.
7. Existing legacy resolution records remain readable and existing refund/return processing is not changed.

## Scope and risks

Changes are limited to the Shipment dispute UI/service guard, the Staff Job Orders proof presentation, and focused regression tests. The main risks are accidentally hiding resolution history or breaking modal keyboard/focus behavior; those are covered by compatibility-preserving backend values and UI tests.
