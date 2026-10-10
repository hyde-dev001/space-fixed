# Landing hero animation timing design

## Goal

Slow and soften the entrance animation for the landing-page hero copy so the headline, supporting description, and calls to action feel deliberate, smooth, and subtly surreal.

## User-facing behavior

- The three headline lines rise over `1400ms`, starting at `0ms`, `300ms`, and `600ms`.
- The description fades in over `1200ms` after a `1050ms` delay.
- The CTA group fades in over `1200ms` after a `1450ms` delay.
- The copy begins with a subtle blur and scale bloom, then gently settles into focus without changing its final layout.
- The hero image carousel remains on its existing `4500ms` interval.
- `prefers-reduced-motion` continues to show the hero copy immediately without animation, blur, or transform.

## Implementation boundaries

- Change only the existing inline hero animation rules/keyframes in `LandingPage.tsx` and their source-level contract test.
- Keep the existing CSS classes, loader handoff, carousel timer, CTA interactions, and responsive layout.
- Do not add an animation library, JavaScript timers, or backend/database changes.

## Acceptance criteria

1. The headline, description, and CTA timing match the approved slower values and the copy has the subtle blur/scale bloom.
2. The carousel interval remains `4500ms`.
3. The reduced-motion override removes animation, transform, opacity, and filter effects.
4. The focused and full frontend suites pass, the production build succeeds, and fresh `public/build` output is committed.
