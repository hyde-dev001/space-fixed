# Registration ID Screening Overlay — Minimalist Design

## Decision

Refine the registration ID-screening overlay into a compact, neutral, user-side dialog. The overlay will use the existing registration page typography and the `DESIGN.md` palette: white surface, ink-black text, soft gray secondary surface, and hairline borders. It will remove the decorative shield tile, dark progress row, heavy shadow, and unnecessary visual emphasis while keeping the two-stage status visible.

## Goals

- Make the loading state feel calm, professional, and consistent with the customer-facing registration page.
- Keep the screen-level blocking behavior while an image is being prepared or validated.
- Preserve the existing dynamic status copy for `loading` and `recognizing` states.
- Keep keyboard and screen-reader semantics intact.
- Respect reduced-motion preferences and avoid layout shift.

## Non-goals

- Do not change file upload handling, OCR, duplicate detection, validation outcomes, API calls, or registration submission.
- Do not add a dependency or introduce a new modal system.
- Do not change the registration form, navigation, or backend behavior.

## Visual direction

- Scrim: neutral black at readable opacity, without decorative blur.
- Dialog: white, compact, bordered, and flat; use the existing user-side `font-outfit` family.
- Header: small uppercase `ID VERIFICATION` eyebrow, concise dynamic title, and one short explanatory line.
- Progress: a quiet two-step vertical list with a thin connector. The completed step uses a small check icon, the active step uses a single spinner, and the pending step remains neutral. No full-width black status card.
- Footer note: a simple hairline separator and muted privacy message.
- Color tokens from `DESIGN.md`: canvas/white, ink `#111111`, soft cloud `#f5f5f5`, hairline, and mute text. No blue, purple, or decorative accent colors.

## Interaction and accessibility

- Keep `role="dialog"`, `aria-modal="true"`, and the existing labelled heading.
- Keep a live status region announcing the current operation without stealing focus.
- Keep the overlay visible for both `loading` and `recognizing`; remove it when the existing OCR state becomes ready, rejected, or error.
- Use SVG/CSS indicators only; no emoji icons.
- Keep the spinner at 150–300ms visual rhythm where motion is enabled and disable it with `motion-reduce`.
- Use `min-h-dvh`, responsive horizontal padding, and a width that fits 375px mobile viewports without horizontal scrolling.

## Acceptance criteria

1. The overlay is visibly flatter and more compact than the current design, with no navy tile, heavy drop shadow, or dark active row.
2. `Preparing image` and `Validating ID` still reflect the existing screening state.
3. The existing Register regression test confirms the overlay appears during screening and disappears after the existing OCR release path.
4. No screening/upload/submission logic changes outside the overlay markup and its presentation test.
5. Focused tests, the full frontend test suite, and a fresh Vite build pass.
