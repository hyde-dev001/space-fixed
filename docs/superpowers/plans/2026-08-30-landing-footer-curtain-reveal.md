# SoleSpace Landing Footer Curtain Reveal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the landing page's static negative-margin footer overlap with a measured fixed-footer curtain reveal that works on desktop and mobile.

**Architecture:** Keep all behavior inside `LandingPage.tsx`. An opaque `landing-curtain` contains the existing navigation and landing sections above a fixed footer; a transparent flow spacer uses `--landing-footer-height` to provide the exact reveal distance. `ResizeObserver` updates that height without scroll work, and `IntersectionObserver` gates footer interaction while it is hidden.

**Tech Stack:** React 18, TypeScript 5.7, Inertia React, Tailwind CSS 4, native `ResizeObserver`/`IntersectionObserver`, Vitest, Playwright, Vite 7.

## Global Constraints

- Modify only `LandingPage.tsx`, its focused contract test, this plan/spec documentation, and generated `public/build` output.
- Preserve footer copy, routes, navigation groups, mobile disclosures, wordmark, landing section order, and existing reveal animations.
- Do not add a dependency, scroll event listener, `requestAnimationFrame` loop, or scroll-progress state.
- Keep the footer inaccessible while concealed, then keyboard- and pointer-operable during the reveal.
- Preserve the user's unrelated ERP/backend/package-lock worktree changes and never stage them.
- Rebase and push only the current feature branch; the user will create the PR to `solespace-b`.

---

### Task 1: Define the curtain-reveal contract

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`
- Test: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`

**Interfaces:**
- Consumes: the existing `landingSource` text fixture.
- Produces: a regression contract for the fixed footer, foreground curtain, measured spacer, observer lifecycle, and removal of the superseded overlap.

- [ ] **Step 1: Replace the old sticky-overlap assertions**

Replace the current footer test with:

```ts
  it('uses a measured fixed-footer curtain reveal with responsive navigation', () => {
    [
      'id="landing-footer"',
      'landing-footer',
      'landing-curtain',
      'footer-curtain-spacer',
      'footer-wordmark',
      '--landing-footer-height',
      'ResizeObserver',
      'IntersectionObserver',
      'SOLESPACE',
      'Explore',
      'Support',
      'Community',
      'Shipping to',
      'Language',
      '<details',
      '<summary',
    ].forEach((marker) => {
      expect(landingSource).toContain(marker);
    });

    expect(landingSource).toContain('fixed inset-x-0 bottom-0 z-0');
    expect(landingSource).toContain('aria-hidden={!footerIsInteractive}');
    expect(landingSource).toContain("toggleAttribute('inert', !footerIsInteractive)");
    expect(landingSource).toContain('prefers-reduced-motion');
    expect(landingSource).not.toContain('footer-reveal-stage');
    expect(landingSource).not.toContain('-mt-32');
    expect(landingSource).not.toContain('sticky bottom-0');
    expect(landingSource).not.toContain("addEventListener('scroll'");
    expect(landingSource).not.toContain('--footer-reveal-progress');
  });
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
```

Expected: one footer-contract failure because `LandingPage.tsx` still uses `footer-reveal-stage`, negative margins, and a sticky footer.

---

### Task 2: Implement the measured fixed-footer curtain

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.tsx`
- Test: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`

**Interfaces:**
- Consumes: the existing `revealRootRef`, footer markup, landing sections, and browser observer APIs.
- Produces: `landingRootRef`, `footerRef`, `footerRevealSpacerRef`, `footerIsInteractive`, `--landing-footer-height`, `landing-curtain`, and `footer-curtain-spacer`.

- [ ] **Step 1: Add landing/footer refs and interaction state**

Immediately after `activeHeroSlide` and the existing reveal ref, add:

```tsx
  const [activeHeroSlide, setActiveHeroSlide] = useState(0);
  const [footerIsInteractive, setFooterIsInteractive] = useState(false);
  const landingRootRef = useRef<HTMLDivElement | null>(null);
  const revealRootRef = useRef<HTMLDivElement | null>(null);
  const footerRef = useRef<HTMLElement | null>(null);
  const footerRevealSpacerRef = useRef<HTMLDivElement | null>(null);
  const prefersReducedMotionRef = useRef(false);
```

- [ ] **Step 2: Add observer lifecycle effects**

Place these effects after the existing landing scroll-reveal effect:

```tsx
  useEffect(() => {
    const landingRoot = landingRootRef.current;
    const footer = footerRef.current;
    const spacer = footerRevealSpacerRef.current;

    if (!landingRoot || !footer || !spacer) {
      return;
    }

    const updateFooterHeight = () => {
      const footerHeight = Math.ceil(footer.getBoundingClientRect().height);
      landingRoot.style.setProperty('--landing-footer-height', `${footerHeight}px`);
    };

    updateFooterHeight();

    const resizeObserver = typeof ResizeObserver === 'undefined'
      ? null
      : new ResizeObserver(updateFooterHeight);

    resizeObserver?.observe(footer);

    if (typeof IntersectionObserver === 'undefined') {
      setFooterIsInteractive(true);

      return () => {
        resizeObserver?.disconnect();
        landingRoot.style.removeProperty('--landing-footer-height');
      };
    }

    const revealObserver = new IntersectionObserver(
      ([entry]) => setFooterIsInteractive(entry?.isIntersecting ?? false),
      { threshold: 0 },
    );

    revealObserver.observe(spacer);

    return () => {
      revealObserver.disconnect();
      resizeObserver?.disconnect();
      landingRoot.style.removeProperty('--landing-footer-height');
    };
  }, []);

  useEffect(() => {
    footerRef.current?.toggleAttribute('inert', !footerIsInteractive);
  }, [footerIsInteractive]);
```

The first effect measures only when footer geometry changes and observes reveal entry; it performs no per-scroll React update. The second effect keeps hidden footer descendants out of the tab order despite React 18's HTML type definitions not declaring `inert`.

- [ ] **Step 3: Restructure the three landing layers**

Replace the current root/stage relationship with this structure while leaving every section and footer child in its existing order:

```tsx
      <div ref={landingRootRef} className="landing-page relative min-h-screen overflow-x-hidden font-outfit antialiased">
        <div ref={revealRootRef} className="landing-curtain relative z-10 bg-white">
          <Navigation mobileMenuTriggerIcon="hamburger" landingSidebar />

          <main>
            {/* existing hero and landing sections, unchanged */}
          </main>
        </div>

        <div
          ref={footerRevealSpacerRef}
          aria-hidden="true"
          className="footer-curtain-spacer pointer-events-none relative z-0"
        />

        <footer
          ref={footerRef}
          id="landing-footer"
          aria-hidden={!footerIsInteractive}
          className={`landing-footer fixed inset-x-0 bottom-0 z-0 w-full max-h-[100svh] min-h-[min(30rem,100svh)] overflow-x-hidden overflow-y-auto overscroll-contain bg-white text-black sm:min-h-[min(34rem,100svh)] ${footerIsInteractive ? 'pointer-events-auto' : 'pointer-events-none'}`}
        >
          {/* existing approved footer content */}
        </footer>
      </div>
```

Remove `footer-reveal-stage`, `-mt-32`, `sm:-mt-48`, `sticky`, and the extra stage closing tag. Restore the footer content container from `pt-20 sm:pt-48` to `pt-8 sm:pt-10`; the foreground curtain now creates the overlap, so compensating padding is no longer needed.

- [ ] **Step 4: Add spacer fallback styles**

Add these rules before the existing footer-link styles:

```css
          .landing-page {
            --landing-footer-height: min(30rem, 100svh);
          }

          .footer-curtain-spacer {
            height: var(--landing-footer-height);
          }

          @media (min-width: 640px) {
            .landing-page {
              --landing-footer-height: min(34rem, 100svh);
            }
          }
```

- [ ] **Step 5: Run the focused test and verify GREEN**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
```

Expected: all four landing layout tests pass.

- [ ] **Step 6: Inspect the source diff**

Run:

```powershell
git diff -- resources/js/Pages/UserSide/Products/LandingPage.tsx resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
git diff --check
```

Expected: only the observer lifecycle, three-layer wrapper, footer positioning classes, spacer styles, and focused assertions change; no footer copy or landing section changes.

---

### Task 3: Verify the curtain interaction in Chromium

**Files:**
- Create temporarily: `tmp/landing_footer_curtain_check.py`
- Modify: none after the temporary file is removed

**Interfaces:**
- Consumes: local Laravel/Vite servers and the selectors created in Task 2.
- Produces: runtime evidence for concealment, stable footer position, progressive exposure, observer gating, mobile resizing, reduced motion, and overflow safety.

- [ ] **Step 1: Confirm the server helper interface**

Run:

```powershell
python .agents/skills/webapp-testing/scripts/with_server.py --help
```

Expected: helper usage showing repeated `--server` and `--port` arguments.

- [ ] **Step 2: Add the temporary Playwright check**

Create a synchronous Playwright script that:

```python
from playwright.sync_api import sync_playwright


def open_landing(page):
    page.goto("http://127.0.0.1:8000/", wait_until="networkidle")
    page.wait_for_function("document.documentElement.classList.contains('solespace-app-ready')")
    page.locator("#solespace-app-loader").wait_for(state="hidden")


def metrics(page):
    return page.evaluate(
        """
        () => {
          const footer = document.querySelector('#landing-footer');
          const curtain = document.querySelector('.landing-curtain');
          const spacer = document.querySelector('.footer-curtain-spacer');
          const footerRect = footer.getBoundingClientRect();
          const curtainRect = curtain.getBoundingClientRect();
          const spacerRect = spacer.getBoundingClientRect();
          return {
            footerTop: footerRect.top,
            footerBottom: footerRect.bottom,
            footerHeight: footerRect.height,
            curtainBottom: curtainRect.bottom,
            spacerHeight: spacerRect.height,
            footerPosition: getComputedStyle(footer).position,
            ariaHidden: footer.getAttribute('aria-hidden'),
            inert: footer.hasAttribute('inert'),
            viewportHeight: innerHeight,
            viewportWidth: innerWidth,
            scrollWidth: document.documentElement.scrollWidth,
          };
        }
        """
    )


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    desktop = browser.new_page(viewport={"width": 1440, "height": 900})
    open_landing(desktop)
    initial = metrics(desktop)
    assert initial["footerPosition"] == "fixed", initial
    assert initial["ariaHidden"] == "true" and initial["inert"], initial
    assert abs(initial["spacerHeight"] - initial["footerHeight"]) <= 1, initial
    assert desktop.evaluate(
        "document.elementFromPoint(innerWidth / 2, innerHeight - 8)?.closest('.landing-curtain') !== null"
    )

    desktop.evaluate(
        """
        () => {
          const spacer = document.querySelector('.footer-curtain-spacer');
          const footer = document.querySelector('#landing-footer');
          scrollTo(0, spacer.offsetTop - innerHeight + footer.offsetHeight / 2);
        }
        """
    )
    desktop.wait_for_timeout(200)
    middle = metrics(desktop)
    assert abs(middle["footerTop"] - initial["footerTop"]) <= 1, (initial, middle)
    assert middle["curtainBottom"] < middle["viewportHeight"], middle

    desktop.evaluate("scrollTo(0, document.documentElement.scrollHeight)")
    desktop.wait_for_timeout(200)
    final = metrics(desktop)
    assert final["ariaHidden"] == "false" and not final["inert"], final
    assert final["curtainBottom"] <= final["footerTop"] + 1, final
    assert final["scrollWidth"] <= final["viewportWidth"], final
    assert desktop.evaluate(
        "document.elementFromPoint(innerWidth / 2, innerHeight - 8)?.closest('#landing-footer') !== null"
    )

    mobile = browser.new_page(viewport={"width": 390, "height": 844})
    open_landing(mobile)
    mobile.evaluate("scrollTo(0, document.documentElement.scrollHeight)")
    mobile.wait_for_timeout(200)
    disclosures = mobile.locator("#landing-footer details")
    before_open = metrics(mobile)
    assert disclosures.count() == 3
    disclosures.first.locator("summary").click()
    mobile.wait_for_timeout(200)
    after_open = metrics(mobile)
    assert disclosures.first.get_attribute("open") is not None
    assert abs(after_open["spacerHeight"] - after_open["footerHeight"]) <= 1, after_open
    assert after_open["footerHeight"] >= before_open["footerHeight"], (before_open, after_open)
    assert after_open["scrollWidth"] <= after_open["viewportWidth"], after_open

    reduced = browser.new_page(viewport={"width": 390, "height": 844})
    reduced.emulate_media(reduced_motion="reduce")
    open_landing(reduced)
    assert reduced.evaluate("document.documentElement.scrollWidth <= innerWidth")

    browser.close()
    print("landing footer curtain checks passed")
```

- [ ] **Step 3: Run the browser check**

Run:

```powershell
python .agents/skills/webapp-testing/scripts/with_server.py --server "php artisan serve --host=127.0.0.1 --port=8000" --port 8000 --server "pnpm.cmd run dev -- --host=127.0.0.1 --port=5173" --port 5173 --timeout 60 -- python tmp/landing_footer_curtain_check.py
```

Expected: `landing footer curtain checks passed` and exit code 0.

- [ ] **Step 4: Remove the temporary check with `apply_patch`**

Delete `tmp/landing_footer_curtain_check.py` and confirm it does not appear in `git status --short`.

---

### Task 4: Review, verify, and refresh production assets

**Files:**
- Modify: `public/build/**` through Vite only
- Modify: no additional source files

**Interfaces:**
- Consumes: the verified curtain implementation.
- Produces: review evidence, a fresh production bundle, and an exact landing-only commit.

- [ ] **Step 1: Run the sequential review stack**

Record these results before claiming completion:

```text
simplify: native observers and CSS layers; no dependency or per-scroll state
standards/spec/correctness: landing-only scope and every acceptance criterion checked
clean-code-typescript: refs/state named by responsibility; observer cleanup complete; no any/assertion
karpathy-guidelines: surgical change; assumptions and fallback behavior explicit
code-splitting: N/A, no new import or heavy conditional UI
gauge-improvements: browser geometry provides before/after behavior evidence
security-review: N/A, no trust boundary, authentication, input, upload, API, or secret change
```

- [ ] **Step 2: Run the full frontend suite**

Run:

```powershell
node_modules/.bin/vitest.cmd run
```

Expected: all frontend tests pass; report any pre-existing non-failing React warnings separately.

- [ ] **Step 3: Preserve unrelated tracked edits before building**

Temporarily stash only these pre-existing paths:

```powershell
git stash push -m "codex-preserve-unrelated-work-before-curtain-build" -- app/Http/Controllers/Logistics/ErpLogisticsController.php package-lock.json resources/js/Pages/ERP/HR/LeaveApprovals.tsx resources/js/Pages/ERP/HR/OvertimeApprovals.tsx tests/Feature/Logistics/LogisticsPageAccessTest.php
```

Do not include `.pnpm-store/`, `.superpowers/brainstorm/staff-articles-20260825/`, or `DESIGN.md`.

- [ ] **Step 4: Generate and restore the production build**

Run:

```powershell
node_modules/.bin/vite.cmd build
git stash pop 'stash@{0}'
```

Expected: Vite exits 0, `public/build/manifest.json` references the new landing chunk, and all unrelated edits return without conflicts.

- [ ] **Step 5: Run the documented backend and hygiene gates**

Run:

```powershell
php artisan test tests/Feature/Logistics
git diff --check
```

Expected: logistics tests pass apart from an environment-only GD skip if the extension remains unavailable; diff hygiene exits 0.

- [ ] **Step 6: Stage only authorized files and commit**

Run:

```powershell
git add -- docs/superpowers/plans/2026-08-30-landing-footer-curtain-reveal.md docs/superpowers/specs/2026-08-30-landing-footer-curtain-reveal-design.md resources/js/Pages/UserSide/Products/LandingPage.tsx resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts public/build
git diff --cached --check
git diff --cached --name-only
git commit -m "feat: add landing footer curtain reveal"
```

Expected: every staged file is one of the four named source/docs paths or begins with `public/build/`.

---

### Task 5: Rebase and push the feature branch

**Files:**
- Modify: Git history only

**Interfaces:**
- Consumes: the verified curtain commit and `origin/solespace-b`.
- Produces: an up-to-date remote `feature/monochrome-erp-theme-clean` branch for the user's PR.

- [ ] **Step 1: Preserve unrelated tracked edits for rebase**

Use the same exact five-path stash command from Task 4 with message `codex-preserve-unrelated-work-before-curtain-rebase`.

- [ ] **Step 2: Fetch and rebase**

Run:

```powershell
git fetch origin --prune
git rebase origin/solespace-b
git stash pop 'stash@{0}'
```

Expected: rebase succeeds without changing the curtain tree, and the unrelated worktree changes return.

- [ ] **Step 3: Verify exact branch scope**

Run:

```powershell
git diff --name-status origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
git diff --check origin/solespace-b...HEAD
```

Expected: source changes remain landing-only, plus the two documentation files and generated `public/build` paths; no unrelated ERP/backend/package-lock path appears.

- [ ] **Step 4: Push the feature branch**

Run:

```powershell
git push -u origin feature/monochrome-erp-theme-clean
```

Expected: remote feature branch updates successfully; `origin/feature/monochrome-erp-theme-clean...HEAD` reports `0 0` afterward.
