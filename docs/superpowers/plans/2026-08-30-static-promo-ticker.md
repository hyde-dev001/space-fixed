# Static Promo Ticker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Keep the user-side offers ticker at page top only while the fixed header remains aligned.

**Architecture:** The shared navigation observes whether scrolling has passed the 40px ticker and changes only its fixed top offset. The ticker remains in document flow. The existing no-scrollbar utility hides search-results scrollbar chrome.

**Tech Stack:** React, TypeScript, Tailwind CSS, Vitest, Vite.

## Global Constraints

- Reuse Navigation.tsx and no-scrollbar; add no dependency.
- Preserve routes, drawers, search behavior, accessibility, ticker pause behavior, and reduced-motion support.
- Commit a fresh public/build.

### Task 1: Test and implement shared navigation behavior

**Files:**

- Modify: resources/js/Pages/UserSide/Shared/Navigation.tsx
- Modify: resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts

- [x] Add a failing contract assertion for isPromoTickerAtTop, window.scrollY < 40, top-10/top-0 header offsets, solid ticker color, logo without drop-shadow, and no-scrollbar search results.
- [x] Run the navigation contract test and confirm it fails because these source tokens are absent.
- [x] Add the minimal passive scroll listener and state cleanup. Make the ticker relative and solid black, make nav top conditional, remove only the logo drop shadow, and apply no-scrollbar to the modal results container.
- [x] Run the navigation contract test and confirm it passes.

### Task 2: Verify and package

**Files:**

- Modify: public/build/**

- [x] Run focused navigation tests and the full frontend suite.
- [x] Run Vite production build and git diff --check.
- [ ] Stage only the two source/test files, specification, plan, and public/build. Stash unrelated local work, fetch, rebase onto origin/solespace-b, push the feature branch, and restore the stash.
