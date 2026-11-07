# Low-End Loader Performance Design

## Goal

Make the first-open SoleSpace loading animation remain visually smooth on low-end phones, laptops, and desktops while preserving the white, minimal, galaxy-inspired wordmark and the existing three-second first-open experience.

## Current Behavior and Root Cause

The loader starts as soon as the server-rendered shell becomes visible. At the same time, the browser is parsing the application bundle, constructing the React tree, decoding assets, and preparing the landing page. The current wordmark compounds that startup work by animating nine independent text layers with translation, rotation, scale, and opacity. Each letter permanently requests compositor promotion through `will-change`, while two oversized pseudo-elements paint several full-screen radial gradients.

The animation therefore asks the browser to promote and rasterize multiple glyph layers during the busiest part of first load. This can produce uneven frame delivery even when the device has adequate average CPU and GPU capacity. Shortening the duration alone would not remove that contention.

## Approved Design

### Full-motion presentation

- Keep the nine-letter `SOLESPACE` markup and the visual idea of letters arriving from different nearby positions.
- Animate only `transform: translate3d(...)`; remove animated rotation, scale, and opacity.
- Use a smooth, decelerating cubic-bezier curve instead of linear movement.
- Promote letters only for the short entrance and release `will-change` after the animation completes.
- Keep the progress line transform-only.
- Replace the many oversized galaxy gradients with one central glow and one small repeating static star texture. Neither background layer will animate.
- Preserve the white background, light-gray wordmark, and minimal visual relationship to the landing page.

### Adaptive lightweight presentation

The loader will expose a CSS media-query fallback for devices likely to have limited rendering headroom:

- `prefers-reduced-motion: reduce` displays the completed wordmark without movement.
- Coarse-pointer devices with a small viewport use one wordmark-level reveal instead of nine independently moving letters.
- The fallback must remain readable and must not delay dismissal or landing-page readiness.

No JavaScript hardware scoring, user-agent detection, dependency, canvas, WebGL, blur filter, or animated box shadow will be introduced. CSS capability and preference queries are sufficient and avoid adding startup work to solve a startup-performance problem.

## Timing and Application Flow

The existing `sessionStorage` first-open rule remains unchanged:

1. The loader appears only on the first website open in the browser session.
2. It remains for the existing total duration of 3,000 milliseconds, including the exit fade.
3. The loader is removed and `solespace-app-ready` is applied.
4. Landing-page hero animation begins only after `solespace-app-ready` is present.
5. Refreshes and later Inertia navigation do not replay the loader.

## Files and Responsibilities

- `resources/css/app.css`: simplify loader rendering, define adaptive motion behavior, and retain the existing visual tokens.
- `tests/Feature/AppShellLoaderTest.php`: enforce the lightweight CSS contract and reject known expensive effects in the loader block.
- `resources/js/utils/appLoader.ts`: no behavior change expected; it remains the dismissal and landing-readiness coordinator.
- `resources/js/utils/__tests__/appLoader.test.ts`: existing timing tests remain the regression coverage for the three-second lifecycle.

## Accessibility

- Keep `role="status"`, `aria-live="polite"`, and the screen-reader loading label.
- Honor `prefers-reduced-motion` with no entrance or progress animation.
- Keep text contrast and sizing unchanged.
- The adaptive fallback changes motion only, not information or timing.

## Verification

1. Add a failing feature assertion that requires transform-only letter movement, a lightweight background, temporary compositor hints, and adaptive/reduced-motion rules.
2. Run `php artisan test tests/Feature/AppShellLoaderTest.php` and confirm the new assertion fails before CSS changes.
3. Implement the minimum CSS changes and rerun the focused test.
4. Run `pnpm run test:frontend` or the repository-local Vitest binary if Corepack is unavailable.
5. Run `pnpm run build` or the repository-local Vite binary and confirm a fresh `public/build` is generated only if requested for the delivery step.
6. Run `git diff --check` and inspect the final diff for unrelated files.

## Success Criteria

- The full-motion loader animates only compositor-friendly translation.
- Low-motion and small coarse-pointer fallbacks avoid nine simultaneous moving glyphs.
- No animated blur, filter, box shadow, background position, or large multi-gradient layer exists in the loader.
- The three-second first-open duration and landing-animation sequencing remain unchanged.
- Existing loader, frontend, and production-build checks pass.
- Unrelated local changes remain untouched.
