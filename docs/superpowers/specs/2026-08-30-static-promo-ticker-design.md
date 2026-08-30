# Static Promo Ticker Design

## Goal

Keep the moving offers ticker visible only at the top of a user-side page while the primary navigation stays aligned and usable during scrolling.

## Agreed behavior

- The offers ticker is a solid `#111111` bar in normal document flow. It scrolls away with page content and retains its looping, hover/focus pause, and reduced-motion behavior.
- The shared navigation remains fixed. It starts below the 40px ticker at the top of the page and transitions to the viewport top after the page scrolls past the ticker, so no empty gap remains above the logo or action icons.
- The SoleSpace logo keeps its existing typography and color but has no drop shadow.
- The desktop search results pane remains scrollable but does not display browser scrollbar chrome.

## Constraints

- Reuse the existing shared `Navigation.tsx`, existing `no-scrollbar` utility, and existing navigation contract test; add no dependencies.
- Preserve user-side routes, drawer behavior, search behavior, accessibility labels, the ticker stacking order, and motion-reduction support.
- Include a fresh production `public/build` in the feature branch push.

## Acceptance criteria

1. Scrolling down moves the ticker out of view while the navigation shifts from below it to the top with aligned logo and icons.
2. The ticker is always solid black, independent of page/header transparency.
3. The SoleSpace logo has no `drop-shadow` utility.
4. The search dialog results can still scroll without a visible scrollbar.
5. Navigation contract tests and the frontend production build pass.
