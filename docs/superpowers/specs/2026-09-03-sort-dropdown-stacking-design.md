# Sort Dropdown Stacking Context Fix

Date: 2026-09-03

## Goal

Keep the `Sort by:` menu visible above the catalog cards when it is opened on the Products and Repair Services pages.

## Root cause

The shared `.scroll-reveal` animation applies `transform`, which creates a stacking context. The sort header and each card are sibling stacking contexts, so the dropdown's existing `z-20` value cannot escape the header context. Later card contexts can therefore paint above the open menu.

## Approved design

- Add `relative z-30` to the existing scroll-reveal wrapper around each page's sort header.
- Raise each dropdown panel to `z-40` while keeping it absolutely anchored to the existing trigger.
- Keep the current sort state, outside-click handling, keyboard attributes, responsive width, and sorting options unchanged.
- Apply the fix only to `Products.tsx` and `Repair.tsx`, with source-contract regression assertions in their existing layout tests.

## Non-goals and risks

No portal, fixed-position overlay, global CSS change, backend change, dependency, or sorting behavior change is needed. The main regression risk is changing the dropdown's responsive placement or covering unrelated page controls; the local stacking levels avoid both.

## Verification

Run the two layout tests first to prove the new contract, then the complete Vitest suite, a Vite production build, and `git diff --check`. Browser-visible verification should confirm that the open menu is above the first card on both routes when local catalog data is available.
