# Logistics settings time picker modal

## Objective

Replace the native time inputs on the Logistics Settings page with one reusable,
responsive iPhone-style wheel picker modal. Keep the existing form state,
normalization, save/discard behavior, and `HH:mm` API payload unchanged.

## Acceptance criteria

- Cutoff, morning start/end, and afternoon start/end open the same modal.
- The modal supports touch scrolling, pointer dragging, mouse-wheel scrolling,
  clicking an option, and keyboard navigation through native focusable controls.
- Cancel, close, Escape, and backdrop dismissal discard the draft selection.
- Done commits the selected time to the existing form state and closes the modal.
- The submitted payload remains normalized to five-character `HH:mm` values.
- The modal is usable at desktop, tablet, and mobile widths with no horizontal
  overflow and touch targets of at least 44px.
- Existing numeric, blackout-date, service-area, save, discard, and validation
  behavior remains intact.

## Planned files

- `resources/js/Pages/ERP/Logistics/components/TimePickerModal.tsx`
  - Add the reusable modal and wheel-column interaction.
- `resources/js/Pages/ERP/Logistics/Settings.tsx`
  - Replace only the five native time controls and connect the modal.
- `resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx`
  - Add modal open/cancel/confirm and normalized-submit coverage.
- `public/build/`
  - Regenerate after source verification.

## Execution sequence

1. Extend the Settings test suite with a failing modal interaction test.
2. Implement `TimePickerModal` using the existing shared `Modal` component and
   repository styling conventions; do not add a dependency or change the shared
   modal contract.
3. Wire the five fields in `Settings.tsx`, keeping `normalizeTimes`, form
   submission, and all unrelated controls intact.
4. Run the focused frontend test, fix any regression, then run the broader
   frontend test suite and `git diff --check`.
5. Review the diff for desktop-scope leakage, dead code, accessibility gaps, and
   unnecessary complexity.
6. Build the frontend to refresh `public/build/`, verify the generated output,
   commit the implementation and build, then push the feature branch.

## Risks and mitigations

- Native time inputs currently provide browser behavior; the replacement will
  preserve the same 24-hour state and payload while adding keyboard and click
  alternatives to gesture input.
- Rendering the modal outside the form prevents its action buttons from
  submitting the settings form accidentally.
- Responsive sizing will use viewport-relative width and height limits so the
  picker remains usable on small mobile screens and tablets.
