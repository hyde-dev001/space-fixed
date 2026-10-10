# SoleSpace Galaxy Loader Design

**Status:** Approved for implementation on 2026-08-11

## Goal

Give the first-open SoleSpace loader a restrained galaxy-inspired reveal that feels premium and remains visually consistent with the landing page.

## Experience

The full-screen overlay keeps the landing page's navy/black palette. A low-contrast radial nebula and a small set of static star points create depth without turning the loader into a colorful space theme. The existing `SOLESPACE` letters begin as dim, distant points around their final positions, travel inward, and settle into the centered wordmark with a subtle stagger. The existing progress line and caption remain beneath the wordmark.

## Technical approach

- Reuse the existing server-rendered loader markup and nine wordmark spans.
- Implement the galaxy field, halo, and letter travel with CSS gradients, pseudo-elements, `transform`, and `opacity`; add no image, canvas, dependency, or network request.
- Keep the existing three-second first-open lifecycle and session-storage behavior unchanged.
- Keep the current `role="status"`, live label, and screen-reader-only loading text.
- Disable decorative motion under `prefers-reduced-motion: reduce` while leaving the loader readable.

## Acceptance criteria

- The wordmark visibly converges toward the center from different directions before settling.
- The background is minimal and uses the landing page's navy, black, and white palette.
- Existing loader timing, first-open-only behavior, accessibility markup, and application boot flow are unchanged.
- The production build and focused loader tests pass.
