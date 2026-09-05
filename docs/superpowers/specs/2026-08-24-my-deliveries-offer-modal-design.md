# My Deliveries Offer and Picker UX Design

## Goal

Make rider assignment offers easier to read and act on, while removing the
mobile picker behavior that opens two selection surfaces and loses the user's
place.

## Root cause

`MyDeliveries.tsx` currently renders a native `<select>` together with a custom
mobile picker modal. On iOS, tapping the select can still open the browser's
native picker after the custom modal has opened. The custom picker also closes
from outside pointer events, so taps outside an option can dismiss it
unexpectedly.

The offer card currently expands an inline decline form. That pushes the card
content around and does not provide common decline reasons.

## Chosen approach

Reuse the page's existing modal and API patterns with the smallest scoped
frontend change:

1. Render the new-assignment offer card with a white surface and black/slate
   text and borders instead of the amber surface. Keep the existing blue
   accept action as the primary action.
2. Open the existing `DeliveryActionModal` when a rider chooses Decline.
3. Show common decline-reason buttons in the modal. Selecting one fills an
   editable textarea, so the rider can adjust the text before submitting.
4. Submit the existing `rejection_reason` string through the current leg or
   batch rejection endpoint. No backend or API contract change is required.
5. Keep the native `<select>` for desktop, but use a custom button trigger on
   compact viewports so iOS cannot open a second native picker. The custom
   picker closes only through its close button, Escape, or selecting an option;
   backdrop/outside taps do not dismiss it.

## Error handling and accessibility

- The existing action runner continues to handle online state, duplicate
  submissions, validation errors, stale offers, and refresh behavior.
- Decline remains disabled until the trimmed textarea has content and an
  action is not already pending.
- The decline modal keeps its visible label, focus restoration, Escape handling,
  and modal semantics.
- Picker options remain keyboard and screen-reader accessible through the
  button trigger and `listbox`/`option` roles.
- The existing backend validation remains the trust boundary for the final
  rejection reason and its 1,000-character limit.

## Acceptance criteria

- New assignment offers no longer use the amber card background or border.
- Decline opens a modal instead of expanding an inline form.
- Common reasons populate an editable decline textarea.
- A custom reason can be entered and submitted through the existing endpoint.
- On compact/mobile viewports, selecting a picker option does not open a second
  native select surface and the picker does not disappear from unrelated taps.
- Desktop picker behavior remains usable and existing delivery actions remain
  unchanged.

## Verification

- Add/update focused `MyDeliveries` tests for the modal, common-reason fill,
  editable custom reason, and the compact picker trigger/close behavior.
- Run the focused frontend test, the full frontend suite, the production
  build, and `git diff --check`.
- Run relevant Logistics PHP tests if the frontend-only change exposes any
  integration regression.
