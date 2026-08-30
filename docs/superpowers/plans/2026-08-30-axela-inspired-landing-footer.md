# Axela-Inspired SoleSpace Landing Footer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current landing-page footer with a SoleSpace-owned sticky underlap footer that exposes an anchored oversized wordmark as the page scrolls, matching the interaction rhythm observed on the Axel Arigato storefront.

**Architecture:** Keep the implementation inside the existing `LandingPage.tsx` page to preserve the landing-only boundary. Wrap the existing landing sections in a foreground `<main>` layer, place a native sticky footer inside a bounded footer-only reveal stage after it, and use a responsive negative top margin on that stage to create the final landing-section overlap. CSS stacking order lets the anchored wordmark sit beneath the foreground page content while the sticky layer is revealed at the bottom. Use a desktop link grid and a separate mobile `details`/`summary` presentation so mobile groups are closed by default without hydration-dependent viewport state.

**Tech Stack:** React 18, TypeScript 5.7, Inertia React, Tailwind CSS 4 utility classes, scoped JSX CSS, Vitest, Playwright.

## Global Constraints

- Modify the footer rendered by `resources/js/Pages/UserSide/Products/LandingPage.tsx` only, plus its focused contract test, design/plan documentation, and generated build output.
- Keep global navigation, product, repair, services, backend routes, and non-landing pages unchanged.
- Use SoleSpace copy, existing routes, existing local assets, and the exact white footer background `#ffffff`; do not copy Axel Arigato brand text, assets, or implementation code.
- Preserve existing landing section order, hero behavior, and existing scroll-reveal behavior outside the footer.
- Keep the footer horizontally clipped and responsive at desktop and mobile widths.
- Honor `prefers-reduced-motion: reduce` for the landing page’s existing reveal and control transitions.
- Use native sticky positioning for the footer underlap; do not add a footer-specific scroll listener or scroll-progress state.

## Follow-up: restore the Axela-style underlap interval

The previous bounded-stage correction stopped the footer from appearing at the top of the landing page, but its stage began only after the main content and therefore removed the visible underlap interval. The approved follow-up keeps that safety boundary and adds only responsive overlap spacing:

- Change the stage class to `footer-reveal-stage relative -mt-32 min-h-[30rem] sm:-mt-48 sm:min-h-[34rem]`.
- Keep the footer's approved UI structure intact while using `pt-20 sm:pt-48` on its content container so the overlapped controls remain visible and interactive.
- Keep `<main className="relative z-10">` above the footer and keep the footer at `sticky bottom-0 z-0`.
- Do not change footer copy, layout, responsive disclosures, wordmark styling, or non-landing files.
- Add a focused contract assertion for the negative-margin stage and verify the initial, maximum-scroll, mobile, and reduced-motion states in a browser.

---

### Task 1: Add the failing footer contract test

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`
- Test: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`

**Interfaces:**
- Consumes: the existing `landingSource` fixture read from `LandingPage.tsx`.
- Produces: source-level acceptance checks for the footer identifiers, responsive controls, sticky reveal, and reduced-motion safeguard.

- [ ] **Step 1: Add the footer structure test**

Append this test to the existing `describe('SoleSpace landing page redesign', ...)` block:

```ts
  it('uses a SoleSpace sticky footer reveal with responsive navigation', () => {
    [
      'id="landing-footer"',
      'landing-footer',
      'SOLESPACE',
      'Explore',
      'Support',
      'Community',
      'Shipping to',
      'Language',
      '<details',
      '<summary',
      'sticky',
      'footer-wordmark',
    ].forEach((marker) => {
      expect(landingSource).toContain(marker);
    });

    expect(landingSource).toContain('className="landing-footer sticky bottom-0 z-0 w-full min-h-[30rem] overflow-hidden bg-white text-black sm:min-h-[34rem]"');
    expect(landingSource).toContain('className="footer-reveal-stage relative -mt-32 min-h-[30rem] sm:-mt-48 sm:min-h-[34rem]"');
    expect(landingSource).toContain('<main className="relative z-10">');
    expect(landingSource).toContain('prefers-reduced-motion');
    expect(landingSource).not.toContain('footerRef');
    expect(landingSource).not.toContain('--footer-reveal-progress');
    expect(landingSource).not.toContain('hidden w-full bg-gray-100');
  });
```

- [ ] **Step 2: Run the focused test and confirm it fails**

Run:

```bash
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
```

For the follow-up, the focused test should fail only because the existing bounded stage does not yet include the responsive negative-margin underlap contract.

- [ ] **Step 3: Commit the failing contract**

```bash
git add resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
git commit -m "test: define landing footer reveal contract"
```

### Task 2: Implement the sticky underlap footer and wordmark reveal

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.tsx:1-121,132-459,460-520`

**Interfaces:**
- Consumes: existing `sectionContainerClass`, Inertia `Link`, current landing routes, and existing `prefersReducedMotionRef`/reveal observer.
- Produces: `landing-footer`, `footer-wordmark`, a native sticky footer layer, desktop link grid, and mobile native disclosure groups.

- [ ] **Step 1: Keep the footer reveal native**

Do not add a footer ref or footer-specific scroll effect. The native `sticky bottom-0 z-0` positioning handles the underlap reveal while the foreground `z-10` content stays above it and the wordmark stays anchored.

- [ ] **Step 2: Put the existing landing content in a foreground layer**

Inside the root landing `<div>`, place the current hero and all five landing sections inside:

```tsx
        <main className="relative z-10">
          {/* existing hero and landing sections, unchanged in order */}
        </main>
```

Keep the existing inner hero wrapper `<div>` and close the new `<main>` immediately after `</section>` for `id="landing-community"`. Do not change the section IDs, links, or section copy.

- [ ] **Step 3: Replace the existing footer markup**

Replace the current hidden gray footer with this structure and SoleSpace-owned content:

```tsx
        <footer
          id="landing-footer"
          className="landing-footer sticky bottom-0 z-0 w-full min-h-[30rem] overflow-hidden bg-white text-black sm:min-h-[34rem]"
        >
          <div className={`${sectionContainerClass} relative z-10 pt-20 sm:pt-48`}>
            <div className="hidden grid-cols-4 gap-8 lg:grid">
              <div>
                <Link href={route("landing")} className="text-sm font-semibold uppercase tracking-[0.08em]">
                  SOLESPACE
                </Link>
              </div>
              <div>
                <h2 className="mb-4 text-xs font-semibold uppercase tracking-[0.14em]">Explore</h2>
                <ul className="space-y-2 text-xs font-medium uppercase tracking-[0.08em]">
                  <li><a href="#landing-new-releases" className="footer-link">New releases</a></li>
                  <li><a href="#landing-categories" className="footer-link">Shop by category</a></li>
                  <li><Link href={route("repair")} className="footer-link">Book a repair</Link></li>
                </ul>
              </div>
              <div>
                <h2 className="mb-4 text-xs font-semibold uppercase tracking-[0.14em]">Support</h2>
                <ul className="space-y-2 text-xs font-medium uppercase tracking-[0.08em]">
                  <li><a href="#landing-story" className="footer-link">Our story</a></li>
                  <li><Link href={route("services")} className="footer-link">Care services</Link></li>
                  <li><Link href={route("services")} className="footer-link">Contact support</Link></li>
                </ul>
              </div>
              <div>
                <h2 className="mb-4 text-xs font-semibold uppercase tracking-[0.14em]">Community</h2>
                <ul className="space-y-2 text-xs font-medium uppercase tracking-[0.08em]">
                  <li><a href="#landing-community" className="footer-link">Join the community</a></li>
                  <li><Link href={route("products")} className="footer-link">Shop SoleSpace</Link></li>
                  <li><Link href={route("services")} className="footer-link">Step in with us</Link></li>
                </ul>
              </div>
            </div>

            <div className="lg:hidden">
              <p className="mb-7 text-sm font-semibold uppercase tracking-[0.08em]">SOLESPACE</p>
              <details className="footer-disclosure">
                <summary>Explore <span aria-hidden="true">+</span></summary>
                <div className="footer-disclosure__links">
                  <a href="#landing-new-releases" className="footer-link">New releases</a>
                  <a href="#landing-categories" className="footer-link">Shop by category</a>
                  <Link href={route("repair")} className="footer-link">Book a repair</Link>
                </div>
              </details>
              <details className="footer-disclosure">
                <summary>Support <span aria-hidden="true">+</span></summary>
                <div className="footer-disclosure__links">
                  <a href="#landing-story" className="footer-link">Our story</a>
                  <Link href={route("services")} className="footer-link">Care services</Link>
                  <Link href={route("services")} className="footer-link">Contact support</Link>
                </div>
              </details>
              <details className="footer-disclosure">
                <summary>Community <span aria-hidden="true">+</span></summary>
                <div className="footer-disclosure__links">
                  <a href="#landing-community" className="footer-link">Join the community</a>
                  <Link href={route("products")} className="footer-link">Shop SoleSpace</Link>
                  <Link href={route("services")} className="footer-link">Step in with us</Link>
                </div>
              </details>
            </div>

            <div className="mt-12 grid grid-cols-1 gap-4 border-t border-black/15 py-5 text-[11px] font-medium uppercase tracking-[0.08em] sm:grid-cols-3 sm:gap-8">
              <p>Copyright &copy; 2024 SoleSpace</p>
              <p>Shipping to <span aria-hidden="true">&rsaquo;</span> Philippines</p>
              <p>Language <span aria-hidden="true">&rsaquo;</span> English</p>
            </div>
          </div>

          <div aria-hidden="true" className="footer-wordmark">
            SOLESPACE
          </div>
        </footer>
```

The desktop grid is visible from `lg` upward; the mobile disclosure groups are visible below `lg` and closed by default. Use the existing `Link` for application routes and plain anchor links for landing sections.

- [ ] **Step 4: Add scoped footer styles**

Add these rules inside the existing JSX `<style>` block:

```css
          .footer-link {
            display: inline-block;
            transition: opacity 180ms ease, transform 180ms ease;
          }

          .footer-link:hover {
            opacity: 0.55;
            transform: translateX(3px);
          }

          .footer-link:focus-visible,
          .footer-disclosure summary:focus-visible {
            outline: 2px solid currentColor;
            outline-offset: 4px;
          }

          .footer-disclosure {
            border-top: 1px solid rgb(0 0 0 / 15%);
          }

          .footer-disclosure:last-of-type {
            border-bottom: 1px solid rgb(0 0 0 / 15%);
          }

          .footer-disclosure summary {
            display: flex;
            min-height: 44px;
            cursor: pointer;
            list-style: none;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
          }

          .footer-disclosure summary::-webkit-details-marker {
            display: none;
          }

          .footer-disclosure[open] summary span {
            transform: rotate(45deg);
          }

          .footer-disclosure summary span {
            font-size: 1.25rem;
            font-weight: 400;
            line-height: 1;
            transition: transform 180ms ease;
          }

          .footer-disclosure__links {
            display: grid;
            gap: 10px;
            padding: 0 0 18px;
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.08em;
            text-transform: uppercase;
          }

          .footer-wordmark {
            position: relative;
            z-index: 0;
            width: max-content;
            min-width: 100%;
            padding: 2.2rem 0 0;
            overflow: hidden;
            white-space: nowrap;
            font-size: clamp(7rem, 25vw, 26rem);
            font-weight: 700;
            line-height: 0.68;
            letter-spacing: -0.105em;
          }

          @media (prefers-reduced-motion: reduce) {
            .footer-link,
            .footer-disclosure summary span {
              transition: none;
            }
          }
```

- [ ] **Step 5: Run the focused test and confirm it passes**

Run:

```bash
node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
```

Expected: PASS with the existing landing tests plus the new footer contract.

- [ ] **Step 6: Commit the landing footer implementation**

```bash
git add resources/js/Pages/UserSide/Products/LandingPage.tsx
git commit -m "feat: add sticky landing footer reveal"
```

### Task 3: Verify responsive interaction in a browser

**Files:**
- Create temporarily: `tmp/landing_footer_check.py`
- Modify: none
- Test: `tmp/landing_footer_check.py`

**Interfaces:**
- Consumes: the local Laravel/Vite landing page and the footer selectors from Task 2.
- Produces: browser evidence for desktop/mobile sticky reveal, mobile disclosure behavior, reduced motion, and overflow safety.

- [ ] **Step 1: Confirm the server helper invocation**

Run:

```bash
python .agents/skills/webapp-testing/scripts/with_server.py --help
```

Expected: usage output for the multi-server helper.

- [ ] **Step 2: Write the browser check**

The temporary script must check the following exact behaviors:

```python
from playwright.sync_api import sync_playwright

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    desktop = browser.new_page(viewport={"width": 1440, "height": 900})
    desktop.goto("http://127.0.0.1:8000", wait_until="networkidle")
    desktop.locator("#landing-footer").scroll_into_view_if_needed()
    desktop.wait_for_timeout(200)
    assert desktop.locator("#landing-footer").count() == 1
    assert desktop.locator(".footer-wordmark").count() == 1
    assert desktop.locator("#landing-footer").evaluate("element => getComputedStyle(element).position === 'sticky'")
    assert desktop.evaluate("document.documentElement.scrollWidth <= window.innerWidth")

    mobile = browser.new_page(viewport={"width": 390, "height": 844})
    mobile.goto("http://127.0.0.1:8000", wait_until="networkidle")
    disclosures = mobile.locator("#landing-footer details")
    assert disclosures.count() == 3
    assert all(not disclosures.nth(index).get_attribute("open") for index in range(3))
    disclosures.first.locator("summary").click()
    assert disclosures.first.get_attribute("open") is not None
    assert mobile.evaluate("document.documentElement.scrollWidth <= window.innerWidth")

    reduced = browser.new_page(viewport={"width": 390, "height": 844})
    reduced.emulate_media(reduced_motion="reduce")
    reduced.goto("http://127.0.0.1:8000", wait_until="networkidle")
    reduced.locator("#landing-footer").scroll_into_view_if_needed()
    reduced.wait_for_timeout(200)
    assert reduced.evaluate("getComputedStyle(document.querySelector('.footer-wordmark')).transitionDuration === '0s'")

    browser.close()
```

The local landing page is served at `/`; do not change application code to accommodate the test.

- [ ] **Step 3: Run the browser check and remove the temporary script**

Run:

```bash
python .agents/skills/webapp-testing/scripts/with_server.py --server "php artisan serve --host=127.0.0.1 --port=8000" --port 8000 --server "pnpm.cmd run dev -- --host 127.0.0.1 --port=5173" --port 5173 --timeout 60 -- python tmp/landing_footer_check.py
```

Expected: the script prints a success message and exits with code 0. Remove `tmp/landing_footer_check.py` with `apply_patch` after the check so no test-only file remains.

### Task 4: Run full verification and refresh committed build output

**Files:**
- Modify: `public/build/**` (generated output only)
- Modify: none outside the landing footer source/test/docs and generated build output

**Interfaces:**
- Consumes: the completed landing footer implementation and verified browser behavior.
- Produces: a passing frontend suite, a passing production build, and a committed fresh `public/build` tree.

- [ ] **Step 1: Run the full frontend suite**

Run:

```bash
node_modules/.bin/vitest.cmd run
```

Expected: all existing frontend test files and tests pass; existing non-failing React warnings may remain visible.

- [ ] **Step 2: Generate the production assets**

Run:

```bash
node_modules/.bin/vite.cmd build
```

Expected: Vite exits 0 and reports a completed production build.

- [ ] **Step 3: Confirm generated staging scope**

Run:

```bash
git add -- public/build
git diff --cached --check
git diff --cached --name-only
```

Expected: every staged generated path starts with `public/build/`; no source, test, or unrelated worktree file is staged.

- [ ] **Step 4: Commit the generated assets**

```bash
git commit -m "build: refresh landing footer assets"
```

- [ ] **Step 5: Run final diff hygiene**

Run:

```bash
git diff --check
git status --short
```

Expected: no whitespace errors; the only remaining status entries are the pre-existing unrelated worktree changes, if still present.
