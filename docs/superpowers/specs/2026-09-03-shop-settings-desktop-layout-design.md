# Shop Settings Desktop Layout

## Goal

Make the Shop Settings page feel like a clear, compact settings workspace on desktop: use the available width, remove the empty areas caused by the current twelve-column ordering, and group related settings into a predictable reading order.

## Scope

Modify only the desktop presentation of `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx` and add focused frontend coverage for the layout contract. Use the existing settings sections, child components, state, refs, handlers, IDs, API calls, controls, and save behavior. Do not change backend payloads, routes, validation, or business logic.

## Responsive Contract

- Keep the current base, small-screen, and tablet classes and DOM behavior intact.
- Apply the new shell at Tailwind's `xl` breakpoint (1280px and above), so a 1024px tablet remains on the existing layout.
- Verify the page at 1440px desktop, 1024px tablet, and 390px mobile.
- Preserve one accessible `Settings sections` navigation with the same six anchor destinations and active-state/focus behavior.

## Desktop Layout

At `xl` and above:

1. Use a wider centered content area with a maximum width aligned to the repository's `DESIGN.md` guidance (approximately 1440px) and balanced outer gutters.
2. Place the existing section navigation in a compact left rail. Keep it visible while scrolling with a safe top offset, and present its links vertically with clear active, hover, focus, and current-section states.
3. Place the settings content in a single natural vertical stack to prevent CSS-grid row gaps and unused columns. Use consistent section rhythm rather than forced `lg:order-*` grid placement.
4. Organize the stack in this visual order:
   - Profile and subscription/account access
   - Modules & Team, followed by compliance documents
   - Payments & Approvals, including approval workflow, payroll, payment gateway, and refund controls when available
   - Operations, including location/geofence and repair payment controls when available
   - Policies & Compliance
5. Keep each existing card's internal content and interaction affordances. Reduce desktop-only decorative blur/elevation where it competes with the settings hierarchy, using the `DESIGN.md` neutral canvas, ink, soft-cloud, and hairline tokens as the visual reference.
6. Preserve readable line lengths, visible section headings, keyboard focus rings, anchor scroll offsets, and adequate contrast. The left rail must not cover focused section content.

## Behavior and Accessibility

- Do not rename or remove `settings-section-*` IDs, refs, labels, controls, or interactive handlers.
- Initial section selection and user navigation must still update `aria-current`, focus the destination section, and scroll to the same bounded anchors.
- Existing toggles, form fields, maps, modals, success/error messages, and conditional rendering must remain functional.
- Do not introduce a new dependency, data source, or duplicated navigation.

## Verification

- Add a focused test that locks the desktop shell contract while confirming the existing six-section navigation contract remains intact.
- Run the focused settings tests, then the full frontend test suite and production build.
- Run `git diff --check`.
- Use browser verification/screenshots at 1440px, 1024px, and 390px to confirm no desktop blank grid space, no new horizontal overflow, no page errors, and no unintended tablet/mobile layout change.

## Acceptance Criteria

- Desktop settings content fills the available page width without the current large blank grid areas.
- Desktop users can understand the settings categories and move between them from the visible left rail.
- Tablet and mobile retain their current navigation/content arrangement and interaction behavior.
- Existing settings functionality and focused section navigation tests continue to pass.
- No backend or unrelated files are changed.
