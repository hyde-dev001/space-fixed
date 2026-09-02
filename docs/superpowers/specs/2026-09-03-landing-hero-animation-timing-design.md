# Landing hero animation timing design

## Goal

Slow the entrance animation for the landing-page hero copy so the headline, supporting description, and calls to action feel more deliberate and premium.

## User-facing behavior

- The three headline lines rise over `1000ms`, starting at `0ms`, `220ms`, and `440ms`.
- The description fades in over `900ms` after an `820ms` delay.
- The CTA group fades in over `900ms` after a `1080ms` delay.
- The hero image carousel remains on its existing `4500ms` interval.
- `prefers-reduced-motion` continues to show the hero copy immediately without animation.

## Implementation boundaries

- Change only the existing inline hero animation rules in `LandingPage.tsx` and their source-level contract test.
- Keep the existing keyframes, easing curve, transforms, CSS classes, loader handoff, CTA interactions, and responsive layout.
- Do not add an animation library, JavaScript timers, or backend/database changes.

## Acceptance criteria

1. The headline, description, and CTA timing match the approved slower values.
2. The carousel interval remains `4500ms`.
3. The reduced-motion override remains intact.
4. The focused and full frontend suites pass, the production build succeeds, and fresh `public/build` output is committed.
