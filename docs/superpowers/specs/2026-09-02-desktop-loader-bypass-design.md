# Desktop app loader bypass design

## Goal

Keep the first-open SoleSpace loader for mobile and tablet users while allowing desktop users to see the application immediately.

## User-facing behavior

- Viewports below `1280px` keep the current first-open loader and its handoff animation.
- Viewports at or above `1280px` do not show the loader when the website opens.
- Desktop bypasses the loader before the first-load class is applied, so landing-page entrance motion is not held behind an invisible delay.
- A desktop fallback guard hides the server-rendered loader if a stale or late first-load class exists.

## Implementation boundaries

- Reuse the existing loader markup, timing, classes, and CSS animation.
- Use the existing `1280px` mobile/tablet boundary used by the ERP responsive layout.
- Do not change page-level loading indicators, Inertia progress behavior, or session storage behavior for mobile/tablet.

## Acceptance criteria

1. Desktop (`>=1280px`) does not show the global app loader on first open.
2. Mobile/tablet (`<1280px`) still use the existing first-open loader behavior.
3. Desktop does not wait for the three-second loader timer before the app is marked ready.
4. Loader unit and Blade contract tests pass, the frontend build passes, and `public/build` is refreshed.
