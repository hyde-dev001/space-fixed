# Showroom Interaction and XOX Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add cursor-look navigation, in-world interaction cues, reliable owner/staff shelf drag-and-drop affordances, and a polished continuous scored XOX session.

**Architecture:** Keep the existing `VirtualShowroom` event loop and `showroomScene` layout. Add lightweight Three.js sprite prompts that follow existing cards/seats, gate camera-follow behavior around edit and touch modes, and retain server-authorized placement writes. Keep XOX rules pure in `showroomTicTacToeRules.ts`; keep session state, timed transitions, and themed UI in `ShowroomTicTacToe.tsx`.

**Tech Stack:** React 18, TypeScript, Three.js, Vitest, Laravel/Inertia payloads, Vite 7.

## Global Constraints

- Reuse the existing SoleSpace showroom palette, layout, raycasts, and permission payloads.
- Do not add dependencies or weaken server-side owner/staff authorization.
- Preserve touch joystick behavior and reduced-motion support.
- Preserve the existing public showroom route and customer read-only behavior.

---

### Task 1: Strengthen pure XOX rules

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/showroomTicTacToeRules.ts`
- Test: `resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts`

**Interfaces:**
- Produce `getWinningLine(board): number[]` and `getBestBotMove(board, random): number | null`.
- Keep `applyMove`, `getWinner`, `isDraw`, and `getRandomBotMove` compatible with existing callers/tests.

- [ ] Add failing tests for a bot taking an immediate win, blocking an immediate player win, and returning the winning line.
- [ ] Run the focused Vitest rule suite and confirm the new assertions fail before implementation.
- [ ] Implement minimax scoring with random tie-breaking among equal best moves, plus `getWinningLine` built from the existing line list.
- [ ] Run the focused rule suite and confirm it passes.

### Task 2: Add world-space prompts and cursor-look camera behavior

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/showroomScene.ts`
- Modify: `resources/js/Pages/UserSide/Products/VirtualShowroom.tsx`
- Test: `resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts`

**Interfaces:**
- `createShowroomScene` returns prompt sprite collections for seats.
- `VirtualShowroom` creates shoe prompt sprites beside each shelf card and updates them in the existing animation loop.

- [ ] Add source-contract assertions for pointer-position camera updates, `E / PLAY`, `CLICK`, and edit-drag gating.
- [ ] Run the contract test and confirm the assertions fail before implementation.
- [ ] Add a cached showroom prompt texture/sprite helper using the current charcoal/brass palette; add seat prompts and shoe prompts with base positions.
- [ ] Update prompt visibility and small bounce offsets in the existing render loop; disable decorative motion under `prefers-reduced-motion`.
- [ ] Add non-touch pointer movement that maps normalized viewport coordinates to smoothed camera yaw/pitch; reset toward neutral on pointer leave. Keep pointer-drag look for compatibility while edit mode reserves drag for placement.
- [ ] Keep raycast click-to-inspect and placement drag/drop paths intact; expose clear edit-mode text when `canEditShowroom` is true.
- [ ] Run the focused showroom contract and layout/placement suites.

### Task 3: Replace XOX UI with continuous scored themed session

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/ShowroomTicTacToe.tsx`
- Modify: `resources/js/Pages/UserSide/Products/showroomTicTacToeRules.ts`
- Test: `resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts`

**Interfaces:**
- `ShowroomTicTacToe` owns per-seated-session score and automatic round reset.
- It calls `getBestBotMove` and `getWinningLine` from the pure rules module.

- [ ] Add component/source assertions for score labels, result animation classes, automatic-round copy, and absence of `New game`.
- [ ] Run the focused XOX suite and confirm the new assertions fail before implementation.
- [ ] Add wins/losses/draws state, delayed round reset, winning-cell state, and cleanup for bot/round timers.
- [ ] Restyle the dialog, board, score panel, marks, and buttons with showroom charcoal/walnut/linen/brass tokens.
- [ ] Add reduced-motion-safe result animation and live result status; remove the New game button while retaining Close and Stand up.
- [ ] Run the XOX suite and confirm all rules/contract tests pass.

### Task 4: Verify and package

**Files:**
- Generated: `public/build/**`

- [ ] Run the focused Vitest suites and `php artisan test tests/Feature/ShowroomPlacementTest.php`.
- [ ] Run `pnpm run build` to refresh `public/build`.
- [ ] Run `git diff --check`, inspect `git status --short`, and review changed source files for dead code and unrelated changes.
- [ ] Commit source/tests, build assets, and documentation in coherent commits, then push the authorized feature branch.
