# Global modal backdrop standardization

## Goal

Standardize every application modal, drawer/sheet backdrop, preview overlay, and non-toast SweetAlert backdrop across SoleSpace so the page behind the active surface is consistently dimmed with a neutral translucent black overlay and subtle blur, without changing modal content, focus behavior, scrolling, or workflow behavior.

## Architecture and constraints

- Laravel 12, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4.
- Keep the existing `erp-modal-backdrop` hook as the compatibility class used by shared and page-owned overlays.
- Move only backdrop treatment to a global CSS contract; keep ERP-only palette normalization scoped to ERP themes.
- Add the hook to actual backdrop elements, not modal content wrappers or transparent event shields.
- Keep portal roots transparent and render one backdrop per modal/drawer layer.
- Preserve SweetAlert toast behavior, accessibility, keyboard/focus handling, body scroll locking, responsive behavior, and existing semantic colors.
- Preserve unrelated working-tree changes; generate a fresh `public/build` with the repository's `pnpm` workflow.

## Implementation tasks

1. Extend the backdrop contract test to cover global pages, shared components, layouts, SweetAlert styling, and representative full-screen overlays. Run the focused test first and record the expected failures.
2. Make the canonical `.erp-modal-backdrop` and non-toast SweetAlert backdrop treatment global in `resources/css/app.css`, while retaining ERP-only normalizer exclusions.
3. Audit and mark unscoped actual backdrops in shared components, layouts, customer/public pages, employee/ERP pages, Shop Owner pages, and Platform Admin pages. Remove or avoid duplicate transparent shields where a real backdrop already exists.
4. Restructure the payment address sheet only as needed so its white panel is not mistaken for a full-screen backdrop; render a separate global backdrop sibling.
5. Run focused frontend tests, the full frontend suite, production build, diff checks, and a final global source audit. Do not claim browser verification unless a runnable browser check succeeds.

## Verification

```text
vitest run resources/js/__tests__/globalModalBackdrop.contract.test.ts resources/js/components/ui/modal/__tests__/Modal.test.tsx
vitest run
pnpm run build
git diff --check
```
