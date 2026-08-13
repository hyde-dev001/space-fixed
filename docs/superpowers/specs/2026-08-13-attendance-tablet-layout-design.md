# SoleSpace Attendance Tablet Layout Design

**Date:** 2026-08-13
**Status:** Approved for implementation

## Goal

Keep the approved phone attendance layout through tablet widths, show the SoleSpace brand in the compact ERP header, and preserve the existing desktop layout at `xl` widths and above.

## Design

- Treat widths below Tailwind's `xl` breakpoint as the compact ERP shell: no persistent sidebar, compact header visible, and no desktop content offset.
- Render the compact header brand as an inline blue SoleSpace mark and text. This avoids stale TailAdmin artwork while leaving the desktop sidebar branding unchanged.
- Keep the attendance dashboard stacked below `xl`; retain its two-column summary card grid so tablet values have room to wrap.
- Keep Attendance History in the existing card presentation below `xl`; show the dense table only at `xl` and above.
- Preserve all attendance state, API calls, controls, modals, filtering, pagination, colors, and accessibility behavior.

## Constraints

- Use existing React, Tailwind, and SVG patterns; add no dependency or abstraction.
- Keep touch targets at least 44px and prevent page-level horizontal overflow.
- Make no backend, route, attendance-rule, or database change.
- Include a fresh generated `public/build` after implementation.

## Acceptance criteria

1. The compact ERP header shows `SoleSpace`, not `TailAdmin`, on phone and tablet widths.
2. At 390px, 768px, and 1024px, the attendance clock, summary, and history use the readable compact layout without a persistent sidebar or compressed desktop table.
3. At 1280px and wider, the existing desktop sidebar, five-column attendance dashboard, stretched summary, and history table remain available.
4. Existing attendance behavior and accessibility contracts remain unchanged.
5. Focused tests, the full frontend suite, production build, and diff hygiene checks pass.

## Self-review

- No placeholders or unresolved design choices remain.
- Compact and desktop breakpoints consistently switch at `xl`.
- Scope is limited to the shared ERP shell, attendance responsive classes, tests, documentation, and generated build.
