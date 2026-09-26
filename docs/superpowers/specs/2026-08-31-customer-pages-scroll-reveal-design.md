# Customer Pages Scroll-Reveal Design

## Goal

Apply the landing page's fade-and-rise scroll-reveal treatment to the main content of the customer Products, Repair, My Orders, and My Repairs pages. The Products page also serves the Men, Women, Kids, and Sports category routes, so the same behavior covers those navigation destinations.

## Acceptance criteria

- Major content sections reveal once as they enter the viewport.
- Repeated cards or panels may use a short, capped stagger where it improves visual hierarchy.
- Products, Men, Women, Kids, Sports, Repair, My Orders, and My Repairs retain their existing navigation, filtering, ordering, repair, modal, and responsive behavior.
- Elements remain visible when `IntersectionObserver` is unavailable.
- Users who prefer reduced motion see content immediately without reveal movement or delay.
- No new frontend dependency is introduced.

## Design

Extract the landing page's reveal lifecycle into one small shared customer-side hook. The hook will receive a page-root reference, find descendants marked with a reveal data attribute, apply optional delays, and reveal each element once through `IntersectionObserver`. It will immediately reveal marked elements for reduced-motion users or browsers without observer support.

Move the reusable reveal styles to the shared application stylesheet. Keep the same landing-page motion language: a subtle upward offset with opacity transition, plus an optional side or scale variant only where already useful. The landing page will use the shared hook and styles so it remains the single visual reference instead of maintaining a duplicate implementation.

Add the root reference and explicit reveal markers only to stable, visible content groups on the four destination page components. Do not automatically animate every child, because those pages contain conditional panels, dropdowns, and dialogs that should not be affected. Dynamic list items will be marked only when their current rendering lifecycle is compatible with the shared observer; otherwise their containing section will reveal as one unit.

## Likely areas changed

- Shared customer-side scroll-reveal hook under `resources/js/Pages/UserSide/Shared/`.
- Shared reveal CSS in `resources/css/app.css`.
- `LandingPage.tsx` to consume the shared behavior.
- `Products.tsx`, `Repair.tsx`, `MyOrders.tsx`, and `myRepairs.tsx` to mark major sections.
- Focused source-contract or component tests near the affected pages.

## Constraints and risks

- Preserve all unrelated working-tree changes.
- Avoid attaching observers to modal or drawer content.
- Prevent initially hidden content from remaining invisible if browser APIs are unavailable.
- Keep animation offsets and delays modest to avoid sluggish long pages.
- Do not add page transitions, smooth-scroll navigation, parallax, or scroll snapping; those are outside this request.

## Verification

1. Run focused frontend tests for the shared behavior and affected page contracts.
2. Run `pnpm run test:frontend`.
3. Run `pnpm run build`.
4. Run `git diff --check`.
5. Review the final diff for standards, acceptance criteria, reduced-motion accessibility, reuse, dead code, and accidental changes to existing page behavior.
