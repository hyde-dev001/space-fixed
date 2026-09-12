# Delivery Proof Image Modal Design

## Goal

Improve the delivery-proof experience inside the ERP Logistics shipment details modal without changing shipment, proof-review, or authorization behavior.

## Preview layout

- Replace the small thumbnail and separate `View delivery proof` link with one full-width landscape proof preview in the `Assignment and progress` column.
- The preview fills the available column width, uses a stable responsive height, and crops only in the preview through `object-cover`.
- A dark translucent `View` button is centered over the image. The image itself is not a link and must never open a new browser tab.
- Existing proof approval, rejection, and return-receipt actions remain available below the preview and retain their current behavior.
- Multiple eligible proofs, if present, each receive their own preview and actions.

## Enlarged image modal

- Activating `View` opens an in-page modal above the shipment details modal.
- The modal uses a dark backdrop and displays the complete proof with `object-contain`, preserving its aspect ratio without cropping.
- An accessible close button with an `X` appears at the top-left.
- The modal closes through the top-left button, the `Escape` key, or a backdrop click.
- Opening the modal moves focus to its close button. Closing returns focus to the `View` button that opened it.
- Keyboard focus remains inside the image modal while it is open.
- The image modal is responsive and keeps the image within the viewport on desktop and mobile.

## Accessibility and visual behavior

- The `View` trigger is a semantic button with an explicit accessible name for the delivery proof.
- The enlarged modal uses `role="dialog"`, `aria-modal="true"`, and a descriptive accessible label.
- Focus indicators must remain visible.
- Light and dark mode styling follows existing ERP neutral colors and spacing.
- No new dependency or backend/API change is required.

## Testing and acceptance criteria

- The proof preview fills the right-side content width and no `View delivery proof` text link is rendered.
- No proof link uses `target="_blank"` for this delivery-proof flow.
- Clicking `View` opens the enlarged image in the current page.
- The enlarged image uses the same proof URL and is not cropped.
- The top-left close button closes the modal and restores focus to the trigger.
- `Escape` and backdrop click also close the modal.
- Existing confirm/reject proof behavior continues to pass its current tests.
- Focused shipment tests, the frontend test suite, and a production build pass.

## Scope boundaries

- This change applies to delivery and receive proofs shown in the shipment details modal.
- Failed-attempt photos and incident evidence links are unchanged.
- Shipment data, upload behavior, proof review endpoints, and backend authorization are unchanged.
