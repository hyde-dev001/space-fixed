# Customer Pages Scroll-Reveal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reuse the landing page's accessible fade-and-rise scroll reveal across Products, Men, Women, Kids, Sports, Repair, My Orders, and My Repairs.

**Architecture:** A focused `useScrollReveal` hook will own viewport observation for explicitly marked elements and observe dynamically inserted marked cards. Shared CSS will hold the existing motion treatment; the landing and four destination page components will only provide a root ref and opt-in markers.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, browser `IntersectionObserver`/`MutationObserver`, Vitest 3 with jsdom.

## Global Constraints

- Preserve navigation, filtering, ordering, repair, modal, and responsive behavior.
- Reveal each marked element once and cap any optional stagger delay.
- Show content immediately for reduced-motion users or when `IntersectionObserver` is unavailable.
- Do not add dependencies, page transitions, parallax, smooth-scroll navigation, or scroll snapping.
- Preserve unrelated working-tree changes.

---

### Task 1: Shared scroll-reveal behavior

**Files:**
- Create: `resources/js/Pages/UserSide/Shared/useScrollReveal.ts`
- Create: `resources/js/Pages/UserSide/Shared/__tests__/useScrollReveal.test.tsx`
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: a stable `RefObject<HTMLElement | null>` attached to a page root.
- Produces: `useScrollReveal(rootRef): void`; marked descendants use `data-scroll-reveal`, optional `data-scroll-delay`, and `scroll-reveal`.

- [ ] **Step 1: Write failing hook tests**

Create a jsdom harness with two statically marked nodes and a controllable `IntersectionObserver` mock. Assert that the hook observes both nodes, copies `data-scroll-delay="120"` to `transitionDelay`, adds `is-visible` only after intersection, calls `unobserve`, and disconnects on unmount. Add a second test that sets `window.matchMedia(...).matches` to `true` and asserts immediate visibility without constructing an observer. Add a third test that appends a marked node beneath the mounted root and asserts that the native `MutationObserver` path registers it with the viewport observer.

```tsx
const Harness = () => {
  const rootRef = useRef<HTMLDivElement | null>(null);
  useScrollReveal(rootRef);

  return (
    <div ref={rootRef} data-testid="root">
      <section data-scroll-reveal className="scroll-reveal" />
      <section data-scroll-reveal data-scroll-delay="120" className="scroll-reveal" />
    </div>
  );
};
```

- [ ] **Step 2: Run the focused test and verify RED**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/useScrollReveal.test.tsx`

Expected: FAIL because `../useScrollReveal` does not exist.

- [ ] **Step 3: Implement the minimum shared hook**

Create `useScrollReveal.ts` with one effect. It must:

```ts
export const useScrollReveal = (rootRef: RefObject<HTMLElement | null>): void => {
  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;

    const reveal = (element: HTMLElement) => element.classList.add('is-visible');
    const elements = () => Array.from(root.querySelectorAll<HTMLElement>('[data-scroll-reveal]'));
    const prefersReducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    // Apply valid non-negative delays. Reveal immediately for reduced motion or
    // unsupported IntersectionObserver. Otherwise observe current and newly
    // inserted marked elements, revealing and unobserving each once.
  }, [rootRef]);
};
```

Use `Number.isFinite` and `Math.min(600, Math.max(0, delay))` for delay values. Use one `IntersectionObserver` with the landing thresholds `{ threshold: 0.16, rootMargin: '0px 0px -10% 0px' }`, and one native `MutationObserver` scoped to `{ childList: true, subtree: true }`. Disconnect both on cleanup. Do not export extra configuration.

- [ ] **Step 4: Move the reusable reveal CSS into the application stylesheet**

Add the existing `.scroll-reveal`, `.scroll-reveal.is-visible`, `.scroll-reveal--side`, and `.scroll-reveal--scale` rules to `resources/css/app.css`. Add this shared accessibility override:

```css
@media (prefers-reduced-motion: reduce) {
  .scroll-reveal,
  .scroll-reveal.is-visible {
    opacity: 1 !important;
    transform: none !important;
    transition: none !important;
  }
}
```

- [ ] **Step 5: Run the focused test and verify GREEN**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/useScrollReveal.test.tsx`

Expected: PASS with three tests and no warnings.

### Task 2: Make the landing page consume the shared implementation

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.tsx`
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`

**Interfaces:**
- Consumes: `useScrollReveal(revealRootRef)` and the shared CSS from Task 1.
- Produces: unchanged landing reveal behavior without page-local observer or duplicate reveal styles.

- [ ] **Step 1: Add failing migration assertions**

Update the layout test to assert:

```ts
expect(landingSource).toContain("import { useScrollReveal } from '../Shared/useScrollReveal';");
expect(landingSource).toContain('useScrollReveal(revealRootRef);');
expect(landingSource).not.toContain("root.querySelectorAll<HTMLElement>('[data-scroll-reveal]')");
expect(landingSource).not.toContain('.scroll-reveal {');
```

- [ ] **Step 2: Run the landing test and verify RED**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`

Expected: FAIL because the landing page still owns its observer and CSS.

- [ ] **Step 3: Replace the duplicated landing implementation**

Import the hook, call `useScrollReveal(revealRootRef)` immediately after refs are declared, remove only the reveal-specific `useEffect`, and delete only reveal CSS now present in `app.css`. Retain hero animation CSS, footer observers, and all current `data-scroll-reveal` markup.

- [ ] **Step 4: Run the landing and hook tests**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts resources/js/Pages/UserSide/Shared/__tests__/useScrollReveal.test.tsx`

Expected: PASS.

### Task 3: Apply the reveal to Products and Repair

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/Products.tsx`
- Modify: `resources/js/Pages/UserSide/Products/Products.layout.test.ts`
- Modify: `resources/js/Pages/UserSide/Repairs/Repair.tsx`
- Create: `resources/js/Pages/UserSide/Repairs/Repair.layout.test.ts`

**Interfaces:**
- Consumes: `useScrollReveal(rootRef)` and `data-scroll-reveal` markers.
- Produces: scroll reveals for all product category routes and the repair catalog.

- [ ] **Step 1: Write failing page-contract assertions**

For each source file, assert the hook import, a `revealRootRef`, `useScrollReveal(revealRootRef)`, a root `ref={revealRootRef}`, and at least two `data-scroll-reveal` markers. Products must assert that the mapped product card carries `scroll-reveal`; Repair must assert the mapped shop card carries `scroll-reveal`.

- [ ] **Step 2: Run the two layout tests and verify RED**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Products/Products.layout.test.ts resources/js/Pages/UserSide/Repairs/Repair.layout.test.ts`

Expected: FAIL because neither page consumes the hook.

- [ ] **Step 3: Wire the Products page**

Import the hook, create `const revealRootRef = useRef<HTMLDivElement | null>(null)`, call it once, and attach the ref to `.userside-products-page`. Mark the breadcrumb/sort header and product results region. Add `data-scroll-reveal` and `scroll-reveal` to each mapped product card so newly fetched and paginated products are handled by the hook's mutation observer. Do not mark the fixed mobile header, menus, modals, or mobile bottom navigation.

- [ ] **Step 4: Run the Products layout and hook tests**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Products/Products.layout.test.ts resources/js/Pages/UserSide/Shared/__tests__/useScrollReveal.test.tsx`

Expected: PASS.

- [ ] **Step 5: Wire the Repair page**

Import and call the shared hook with a new root ref attached to `.userside-repair-list-page`. Mark the header/filter area and shops region. Add `data-scroll-reveal` and `scroll-reveal` to each shop card. Exclude fixed navigation, the address manager modal, account menu, and bottom navigation.

- [ ] **Step 6: Run the Repair layout and hook tests**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Repairs/Repair.layout.test.ts resources/js/Pages/UserSide/Shared/__tests__/useScrollReveal.test.tsx`

Expected: PASS.

### Task 4: Apply the reveal to My Orders and My Repairs

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.layout.test.ts`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.layout.test.ts`

**Interfaces:**
- Consumes: the shared hook and marker contract.
- Produces: revealed filter controls, state panels, and dynamically loaded order/repair cards.

- [ ] **Step 1: Write failing source-contract assertions**

In both existing layout tests, assert the hook import/call/root ref. Assert that the tab controls and empty/loading/list regions include `data-scroll-reveal`, and that mapped order cards use `scroll-reveal` while preserving `data-order-id` or `data-repair-id`.

- [ ] **Step 2: Run the two layout tests and verify RED**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Orders/MyOrders.layout.test.ts resources/js/Pages/UserSide/Repairs/myRepairs.layout.test.ts`

Expected: FAIL because the pages have no scroll-reveal wiring.

- [ ] **Step 3: Wire My Orders**

Import the hook, add one page root ref to the main flex-column shell, and call the hook. Mark mobile and desktop tab groups, the empty state, and each mapped element carrying `data-order-id`. Add `scroll-reveal` without changing the existing conditional border classes. Exclude refund dialogs, return forms, confirmation overlays, and other modal content.

- [ ] **Step 4: Run My Orders tests**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Orders/MyOrders.layout.test.ts resources/js/Pages/UserSide/Shared/__tests__/useScrollReveal.test.tsx`

Expected: PASS.

- [ ] **Step 5: Wire My Repairs**

Import the hook, add one root ref to the main flex-column shell, and call it. Mark mobile and desktop tab groups, loading/empty state panels, and each mapped element carrying `data-repair-id`. Add `scroll-reveal` without changing status, highlight, scheduling, payment, warranty, or modal behavior.

- [ ] **Step 6: Run My Repairs tests**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Repairs/myRepairs.layout.test.ts resources/js/Pages/UserSide/Shared/__tests__/useScrollReveal.test.tsx`

Expected: PASS.

### Task 5: Sequential review and verification

**Files:**
- Review all files changed in Tasks 1â€“4.
- Update `docs/ai-learning-log.md` only if the work produced a durable, reusable project lesson.

**Interfaces:**
- Consumes: completed feature diff.
- Produces: evidence that the feature meets the spec without regressions.

- [ ] **Step 1: Run the required review stack sequentially**

Record: Ponytail simplification; repository Standards review; Spec review; TypeScript/React readability and Vercel performance review; Karpathy minimum-diff review; code-splitting as `N/A` unless bundle behavior changed; improvement measurement as `not measured` unless a baseline exists; security review as `N/A` because no trust boundary changes; verification evidence pending the next steps.

- [ ] **Step 2: Run focused feature tests**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/useScrollReveal.test.tsx resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts resources/js/Pages/UserSide/Products/Products.layout.test.ts resources/js/Pages/UserSide/Repairs/Repair.layout.test.ts resources/js/Pages/UserSide/Orders/MyOrders.layout.test.ts resources/js/Pages/UserSide/Repairs/myRepairs.layout.test.ts
```

Expected: all selected files pass.

- [ ] **Step 3: Run the full frontend suite**

Run: `pnpm run test:frontend`

Expected: exit code 0 and zero failed tests.

- [ ] **Step 4: Run the production build**

Run: `pnpm run build`

Expected: exit code 0.

- [ ] **Step 5: Run diff hygiene and inspect scope**

Run: `git diff --check`

Expected: exit code 0. Then inspect `git diff --stat`, `git diff --name-only`, and changed sections to confirm no unrelated file was edited and no import, marker, or local reveal implementation is orphaned.

- [ ] **Step 6: Commit the implementation only if requested**

Stage only the implementation/test files listed above, leaving existing unrelated modifications untouched. Suggested message: `feat: add customer page scroll reveals`.
