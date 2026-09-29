# Shop Owner Document Review Gate

## Goal

Prevent a pending shop-owner registration from being approved or rejected until the super admin has opened every submitted document preview.

## Behavior

- Clicking a document's `View` button marks that document as viewed and opens its inline preview.
- Viewed state is tracked separately for each registration while the page remains open.
- Closing and reopening a registration modal preserves its viewed progress.
- `Approve` and `Reject` remain disabled until every submitted document has been viewed.
- A registration with no submitted documents cannot be approved or rejected from this modal.
- Each opened document displays a `Viewed` indicator.
- Existing approval, rejection, image lightbox, and modal-close behavior remains unchanged.

## Implementation

Keep the change inside `ShopOwnerRegistrationView.tsx`. Add component state keyed by registration ID, with a set of viewed document indexes for each registration. The existing document `View` handler records the index before toggling the preview. Derive whether all documents are viewed from the selected registration's document count and viewed-index count, then pass that value to both action buttons' `disabled` state and disabled styling.

No backend or persistent browser storage is needed because review progress only needs to survive modal close/reopen during the current page session.

## Verification

- Confirm both action buttons start disabled for a pending registration with documents.
- Open documents one at a time and confirm only the clicked documents show `Viewed`.
- Confirm both buttons enable only after the final document is opened.
- Close and reopen the same registration and confirm progress remains.
- Open a different registration and confirm it has independent progress.
- Confirm registrations with no documents keep both action buttons disabled.
- Run the project's available TypeScript/build check.
