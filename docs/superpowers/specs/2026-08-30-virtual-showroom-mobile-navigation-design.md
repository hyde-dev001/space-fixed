# Virtual Showroom Mobile and Navigation Design

**Date:** 2026-08-30  
**Status:** Approved by user

## Goal

Make the standalone Virtual Showroom load reliably on desktop, tablet, and mobile, while keeping the showroom page visually focused and removing the shared customer navigation from this page only.

## User-visible behavior

The standalone showroom will display a full-viewport 3D showroom with the existing Back to Shop Profile action. It will not render the shared customer navigation, so the following elements disappear from this page:

- moving promotional bar;
- SoleSpace header logo;
- hamburger menu;
- search icon;
- notification bell;
- chat/messages icon; and
- cart icon.

Existing showroom controls remain available: Night Mode, room switching, mobile joystick, drag/swipe camera controls, and product focus/close actions.

The standalone viewport will use dynamic viewport height so browser chrome on mobile devices does not leave the canvas incorrectly sized. The layout must remain usable in portrait and landscape tablet/mobile views without horizontal overflow.

## Root-cause fix

`VirtualShowroom.tsx` starts its render loop immediately inside the Three.js setup effect. The loop calls `clearMovementKeys`, but that callback is declared later in the same effect. The first animation frame can therefore read the callback while it is still in the temporal dead zone, producing `Cannot access 'Xe' before initialization` after Vite minification.

Move the `clearMovementKeys` declaration above the first render-loop invocation. The callback's behavior and event listeners stay unchanged.

## Scope and isolation

- Modify `VirtualShowroomPage.tsx` to omit `Navigation` for this standalone page and retain the back link.
- Modify `VirtualShowroom.tsx` only for the initialization-order fix and standalone dynamic viewport sizing.
- Add a focused frontend regression test covering the page-level navigation omission and the callback-before-render-loop ordering.
- Do not modify `Navigation.tsx`; all other user-side pages continue using the shared navigation unchanged.
- Do not change routes, controllers, product payloads, premium access, Three.js scene geometry, or global CSS behavior.

## Verification

1. Run the focused showroom regression test and confirm it fails before the implementation and passes after it.
2. Run `pnpm run test:frontend`.
3. Run `pnpm run build` and confirm the showroom asset compiles without errors.
4. Run `git diff --check`.
5. Use the local app/browser when available to check the showroom at a desktop viewport and mobile/tablet viewport, confirming no console `ReferenceError` and no shared navigation controls on the showroom page.
