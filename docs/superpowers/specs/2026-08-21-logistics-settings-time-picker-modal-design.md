# Logistics Settings iPhone-Style Time Picker Design

## Goal

Replace the browser-native time controls on the ERP Logistics Settings page with a consistent modal time picker that works on desktop, tablet, and mobile. The picker should feel like an iPhone wheel selector while keeping the existing settings form, validation, save, and discard behavior unchanged.

## Chosen approach

Use a reusable React `TimePickerModal` rendered through the existing project `Modal` component. The modal contains three vertically scrollable wheel columns:

- Hour: `1` through `12`
- Minute: `00` through `59`
- Period: `AM` or `PM`

Each column supports touch scrolling, mouse-wheel scrolling, click/tap selection, and pointer dragging. The selected row is highlighted in the center with a subtle fade at the top and bottom of the wheel. The component uses the existing Tailwind tokens and dark-mode conventions rather than adding a new dependency or design system.

## Interaction and data flow

1. A time field displays the current value in a readable 12-hour format and exposes `aria-haspopup="dialog"`.
2. Clicking the field opens the modal with a draft value initialized from the field's current `HH:mm` value.
3. Scrolling, dragging, or tapping updates only the modal draft.
4. `Cancel`, Escape, the close button, or the backdrop discard the draft and leave the form value unchanged.
5. `Done` converts the draft to the form's existing 24-hour `HH:mm` string and calls the page's existing state setter.
6. The existing `normalizeTimes` function remains the source of truth for API-safe values, and the existing Axios payload is unchanged.

## Responsive and accessibility behavior

- The modal is centered with a responsive width (`calc(100vw - 2rem)` capped at a readable max width) on all breakpoints.
- The modal body is bounded by the dynamic viewport height so it does not create page overflow on mobile.
- All wheel options are real buttons with at least 44px touch targets and visible keyboard focus states.
- The dialog has `role="dialog"`, `aria-modal="true"`, a labelled heading, and an explicit close/cancel route.
- Keyboard users can focus an option and select it without relying on dragging.
- Color and the selected-row treatment are paired with text/value changes; selection is not color-only.
- Existing dark mode colors are applied to the modal, controls, dividers, and selected state.
- No animation is required for the wheel itself; modal entrance uses the existing overlay behavior and respects the existing app patterns.

## Files and boundaries

- Add `resources/js/Pages/ERP/Logistics/components/TimePickerModal.tsx` for the reusable picker and wheel-column interaction.
- Update `resources/js/Pages/ERP/Logistics/Settings.tsx` only to open the picker, format the displayed value, and commit `HH:mm` values.
- Extend `resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx` for open, cancel, Done, and pointer-drag behavior while retaining the existing save/validation tests.
- Refresh `public/build` after the frontend build.

## Verification

- Run the focused Settings test and all Logistics frontend tests.
- Run the production Vite build.
- Run `git diff --check` and confirm the pushed diff contains only the picker implementation, its tests, the spec, and generated build artifacts; preserve unrelated working-tree changes.
