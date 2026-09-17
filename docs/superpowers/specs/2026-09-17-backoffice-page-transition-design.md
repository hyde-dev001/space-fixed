# Back-office Page Transition Design

## Goal

Add a subtle page-entry transition to ERP, Shop Owner, and Superadmin page content while leaving the existing customer-side animation unchanged.

## Approved behavior

- On an Inertia page navigation within the back office, only the new page content fades in and moves upward slightly into its resting position.
- The transition starts at `opacity: 0` and `translateY(12px)`, then reaches `opacity: 1` and `translateY(0)`.
- The transition lasts about 350ms with an ease-out curve.
- The sidebar and header remain stationary.
- No full-screen overlay, wordmark, curtain, or customer-side transition is used for this behavior.
- Users who prefer reduced motion receive no movement animation.
- Customer-side pages and their existing `CustomerPageTransition`/`scroll-reveal` behavior remain untouched.

## Approach

Use a small CSS keyframe class applied by the existing shared back-office layouts. The Inertia page component is used as the React key so a new page component gets a fresh entry animation without remounting content for query-only updates such as filters. The class is placed around the layout's page-content children, below the persistent sidebar/header chrome.

The affected layout boundaries are:

- `resources/js/layout/AppLayout_ERP.tsx`
- `resources/js/layout/AppLayout_shopOwner.tsx`
- `resources/js/layout/CanonicalOwnerLayout.tsx`
- `resources/js/layout/AppLayout.tsx`, scoped to `superAdmin/` pages so generic pages using the legacy layout do not gain unrelated motion.

The CSS lives in `resources/css/app.css`, alongside the existing customer motion rules, but uses a separate `backoffice-page-enter` class and keyframe.

## Non-goals

- Do not modify customer pages, customer transition components, or customer scroll-reveal classes.
- Do not add a new animation dependency.
- Do not animate the sidebar, header, viewport, or a full-screen loading curtain.
- Do not add per-page transition markup.

## Verification

- Add a focused frontend contract test for the scoped class/keyframe and reduced-motion rule.
- Run the focused test, the frontend test suite, and the production build.
- Inspect the final diff and verify no customer-side files or customer transition rules changed.
