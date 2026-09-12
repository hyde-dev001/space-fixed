# Desktop app loader bypass implementation plan

## Context

The global first-open loader is rendered by `resources/views/app.blade.php` and dismissed by `resources/js/utils/appLoader.ts`. It currently applies a three-second handoff to every first-open viewport. The requested behavior is to preserve that experience on mobile/tablet and skip it on desktop without changing page-level loading states.

## Plan

1. Add failing regression tests for desktop loader bypass and responsive loader styles.
2. Add one shared desktop viewport boundary to the loader utility and use the same boundary in the server-rendered bootstrap script.
3. Add a CSS desktop fallback that prevents a loader flash if the first-load class is present.
4. Run focused tests, the complete frontend suite, the Laravel loader contract test, and the frontend build.
5. Review the diff, refresh `public/build`, commit only intended files, and push the feature branch.

## Constraints

- Preserve the existing mobile/tablet three-second loader timing and animation.
- Preserve the first-open `sessionStorage` behavior for mobile/tablet.
- Do not remove Inertia progress indicators or unrelated loading states.
- Do not change backend data, routes, permissions, or database behavior.
