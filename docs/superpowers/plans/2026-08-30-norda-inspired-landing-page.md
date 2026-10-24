# Norda-Inspired SoleSpace Landing Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the customer landing page sections after the hero with a SoleSpace-owned, Norda-inspired storefront flow that preserves existing products, routes, responsive behavior, and landing-only scroll animation.

**Architecture:** Keep the current `LandingPage.tsx` page as the only runtime boundary. Reuse its existing hero, `Navigation`, footer, product prop, local images, and root-scoped `IntersectionObserver`; replace only the old statistics-through-CTA JSX and the client state that becomes orphaned. Add one source-contract test beside the landing page to lock the section sequence, local asset usage, preserved integration hooks, and reduced-motion contract.

**Tech Stack:** Laravel 12 + Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Vitest/jsdom, Playwright for browser verification.

## Global Constraints

- Runtime implementation changes only `resources/js/Pages/UserSide/Products/LandingPage.tsx`.
- Do not edit `Navigation`, global CSS, routes, the landing controller, database code, other pages, or shared components.
- Reuse the existing `products` prop, named routes, and `/images/shop/p1.jpg` through `/images/shop/p4.jpg` assets.
- Do not add dependencies, external image URLs, copied Norda assets, or copied Norda marketing copy.
- Preserve the current hero loader handoff classes, hero carousel, navigation props, footer, and existing product/repair/services routes.
- Use native CSS and the existing `IntersectionObserver`; do not add Framer Motion, GSAP, or a per-frame scroll listener.
- Write a failing test before production implementation and run it to confirm the expected failure.
- Preserve unrelated working-tree edits and stage only files belonging to this change.

---

### Task 1: Establish the baseline and write the failing landing contract test

**Files:**
- Create: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`
- Read only: `resources/js/Pages/UserSide/Products/LandingPage.tsx`

**Interfaces:**
- Consumes: the current landing-page source file as UTF-8 text.
- Produces: a runnable Vitest contract proving the intended section markers, local assets, preserved routes/hooks, and removed legacy CTA/stat labels.

- [ ] **Step 1: Run the frontend baseline before adding the test**

Run:

```powershell
pnpm run test:frontend
```

Expected: record the current pass/fail result before the landing change. Any failures in unrelated existing files are baseline failures and must not be attributed to this work without comparison.

- [ ] **Step 2: Create the failing landing contract test**

Add this exact source-contract test:

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const landingSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Products/LandingPage.tsx'),
  'utf8',
);

describe('SoleSpace landing page redesign', () => {
  it('uses the SoleSpace storefront section sequence', () => {
    [
      'New releases',
      'Shop by category',
      'KEEP EVERY STEP GOING',
      'Curated footwear',
      'Expert repairs',
      'One space for every step',
      'STEP INTO SOLESPACE',
    ].forEach((marker) => {
      expect(landingSource).toContain(marker);
    });

    expect(landingSource).toContain('id="landing-new-releases"');
    expect(landingSource).toContain('id="landing-categories"');
    expect(landingSource).toContain('id="landing-story"');
    expect(landingSource).toContain('id="landing-benefits"');
    expect(landingSource).toContain('id="landing-community"');
  });

  it('uses local SoleSpace assets and preserves landing integration hooks', () => {
    ['/images/shop/p1.jpg', '/images/shop/p2.jpg', '/images/shop/p3.jpg', '/images/shop/p4.jpg'].forEach((asset) => {
      expect(landingSource).toContain(asset);
    });

    expect(landingSource).toContain('<Navigation mobileMenuTriggerIcon="hamburger" landingSidebar />');
    expect(landingSource).toContain("route('products.show', product.slug)");
    expect(landingSource).toContain('route("repair")');
    expect(landingSource).toContain('route("services")');
    expect(landingSource).toContain('data-scroll-reveal');
    expect(landingSource).toContain('prefers-reduced-motion');
    expect(landingSource).toContain('html.solespace-first-load:not(.solespace-app-ready) .landing-hero-motion');
  });

  it('removes the superseded statistics and final CTA copy', () => {
    expect(landingSource).not.toContain('Satisfaction');
    expect(landingSource).not.toContain('READY TO STEP INTO STYLE?');
  });
});
```

- [ ] **Step 3: Run only the new test and verify the RED failure**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
```

Expected: FAIL because the current page does not yet contain `New releases` and the new section IDs. Do not modify the test to make the existing page pass.

- [ ] **Step 4: Commit only the landing contract test**

```powershell
git add -- 'resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts'
git commit -m "test: define landing page redesign contract"
```

### Task 2: Replace the old post-hero landing sections with the new storefront flow

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.tsx`

**Interfaces:**
- Consumes: existing `products: Product[]`, existing `sectionContainerClass`, existing named routes, and local shop image paths.
- Produces: five rendered sections with stable IDs: `landing-new-releases`, `landing-categories`, `landing-story`, `landing-benefits`, and `landing-community`.

- [ ] **Step 1: Remove client state and helpers made obsolete by removing the statistics/product hover implementation**

Delete only the old landing-specific items that no longer have consumers:

```tsx
const [activeImageIndexes, setActiveImageIndexes] = useState<Record<number, number>>({});
const [statsValues, setStatsValues] = useState([0, 0, 0]);
const hoverTimersRef = useRef<Record<number, number>>({});
const statsSectionRef = useRef<HTMLElement | null>(null);
const statsAnimationRef = useRef<number | null>(null);
const statsHasAnimatedRef = useRef(false);
```

Also remove `statsTargets`, `formatCompactNumber`, `formatStatValue`, the cleanup effect that clears product hover timers, the stats animation effect, `getProductImages`, `startImageCycle`, and `stopImageCycle`. Keep `revealRootRef`, `prefersReducedMotionRef`, `activeHeroSlide`, the hero timer, and the existing generic reveal effect.

- [ ] **Step 2: Add the local category metadata inside `LandingPage`**

Place this after the existing `heroSlides` declaration so Ziggy route resolution occurs during page render:

```tsx
const categoryCards = [
  {
    title: 'Shoes',
    routeName: 'products',
    image: '/images/shop/p1.jpg',
    alt: 'SoleSpace footwear collection',
  },
  {
    title: 'Repair',
    routeName: 'repair',
    image: '/images/shop/p2.jpg',
    alt: 'SoleSpace shoe repair service',
  },
  {
    title: 'Services',
    routeName: 'services',
    image: '/images/shop/p3.jpg',
    alt: 'SoleSpace footwear care services',
  },
] as const;
```

- [ ] **Step 3: Replace the old statistics-through-CTA block with the five-section JSX**

Replace the existing block beginning with `Stats Section` and ending after the current `CTA Section` with this structure. Keep the existing footer immediately after it unchanged:

```tsx
      <section id="landing-new-releases" data-scroll-reveal className="scroll-reveal w-full bg-white py-16 text-black sm:py-24 lg:py-32">
        <div className={sectionContainerClass}>
          <div className="mb-10 flex flex-col justify-between gap-5 sm:mb-14 sm:flex-row sm:items-end">
            <div data-scroll-reveal className="scroll-reveal">
              <p className="mb-3 text-xs font-semibold uppercase tracking-[0.22em] text-black/55">SoleSpace collection</p>
              <h2 className="text-4xl font-light tracking-[-0.05em] sm:text-6xl lg:text-8xl">New releases</h2>
            </div>
            <Link
              href={route('products')}
              className="group inline-flex min-h-11 items-center gap-3 text-sm font-semibold text-black transition-opacity hover:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-black focus-visible:ring-offset-4"
            >
              Shop all products
              <svg className="h-5 w-5 transition-transform duration-300 group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.75} d="M5 12h14m-6-6 6 6-6 6" />
              </svg>
            </Link>
          </div>

          <div className="flex snap-x snap-mandatory gap-4 overflow-x-auto pb-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden md:grid md:grid-cols-3 md:gap-6 md:overflow-visible">
            {products.length > 0 ? products.map((product, index) => (
              <Link
                key={product.id}
                href={route('products.show', product.slug)}
                data-scroll-reveal
                data-scroll-delay={Math.min(index * 90, 270)}
                className="scroll-reveal group min-w-[84%] snap-start border border-black/10 bg-[#f5f5f3] transition-transform duration-300 hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-black focus-visible:ring-offset-4 sm:min-w-[58%] md:min-w-0"
              >
                <div className="relative aspect-square overflow-hidden bg-[#f5f5f3]">
                  <img
                    src={product.main_image || '/images/product/product-01.jpg'}
                    alt={product.name}
                    className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
                    loading="lazy"
                    decoding="async"
                    sizes="(max-width: 767px) 84vw, (max-width: 1023px) 58vw, 33vw"
                  />
                  {index === 0 && (
                    <span className="absolute left-4 top-4 bg-white px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-black sm:left-5 sm:top-5">
                      New
                    </span>
                  )}
                  {product.stock_quantity === 0 && (
                    <span className="absolute inset-0 flex items-center justify-center bg-black/60 px-4 text-center text-sm font-semibold uppercase tracking-[0.14em] text-white">
                      Out of stock
                    </span>
                  )}
                </div>
                <div className="flex items-start justify-between gap-4 bg-white p-4 sm:p-5">
                  <div className="min-w-0">
                    <h3 className="truncate text-sm font-semibold text-black sm:text-base">{product.name}</h3>
                    <p className="mt-1 line-clamp-1 text-xs text-black/55">{product.description || 'Made for your next step.'}</p>
                  </div>
                  <span className="shrink-0 text-sm font-semibold text-black">₱{product.price.toLocaleString()}</span>
                </div>
              </Link>
            )) : (
              <div className="min-h-56 w-full border border-dashed border-black/20 p-8 text-center md:col-span-3">
                <p className="text-sm text-black/55">New footwear releases will appear here soon.</p>
              </div>
            )}
          </div>
        </div>
      </section>

      <section id="landing-categories" data-scroll-reveal className="scroll-reveal w-full bg-white py-16 text-black sm:py-24 lg:py-32">
        <div className={sectionContainerClass}>
          <div className="mb-10 flex items-end justify-between gap-6 sm:mb-14">
            <h2 className="max-w-3xl text-4xl font-light tracking-[-0.05em] sm:text-6xl lg:text-8xl">Shop by category</h2>
            <span className="hidden pb-2 text-xs uppercase tracking-[0.2em] text-black/50 sm:block">Find your next step</span>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {categoryCards.map((card, index) => (
              <Link
                key={card.title}
                href={route(card.routeName)}
                data-scroll-reveal
                data-scroll-delay={Math.min(index * 100, 200)}
                className="scroll-reveal group relative min-h-[28rem] overflow-hidden bg-black focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-black focus-visible:ring-offset-4 sm:min-h-[34rem]"
              >
                <img src={card.image} alt={card.alt} className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" loading="lazy" decoding="async" sizes="(max-width: 639px) 100vw, (max-width: 1023px) 50vw, 33vw" />
                <div className="absolute inset-0 bg-gradient-to-t from-black/75 via-black/15 to-transparent" />
                <div className="absolute inset-x-0 bottom-0 flex items-end justify-between gap-4 p-5 text-white sm:p-7">
                  <h3 className="text-2xl font-semibold tracking-tight sm:text-3xl">{card.title}</h3>
                  <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/60 transition-colors group-hover:bg-white group-hover:text-black" aria-hidden="true">
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.75} d="M5 12h14m-6-6 6 6-6 6" /></svg>
                  </span>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>

      <section id="landing-story" data-scroll-reveal className="scroll-reveal relative min-h-[32rem] overflow-hidden bg-black text-white sm:min-h-[38rem] lg:min-h-[46rem]">
        <img src="/images/shop/p4.jpg" alt="SoleSpace footwear story" className="absolute inset-0 h-full w-full object-cover opacity-80" loading="lazy" decoding="async" sizes="100vw" />
        <div className="absolute inset-0 bg-black/50" />
        <div className={`${sectionContainerClass} relative flex min-h-[32rem] items-end py-12 sm:min-h-[38rem] sm:py-16 lg:min-h-[46rem] lg:py-20`}>
          <div data-scroll-reveal className="scroll-reveal max-w-3xl">
            <p className="mb-5 text-xs font-semibold uppercase tracking-[0.22em] text-white/75">KEEP EVERY STEP GOING</p>
            <h2 className="max-w-3xl text-4xl font-light leading-[0.95] tracking-[-0.05em] sm:text-6xl lg:text-8xl">Find a pair worth keeping.</h2>
            <p className="mt-6 max-w-xl text-base leading-relaxed text-white/80 sm:text-lg">Discover the right footwear, care for what you love, and keep moving with SoleSpace.</p>
            <Link href={route('products')} className={`${buttonBaseClass} ${buttonLightClass} mt-8 sm:mt-10`}>Discover SoleSpace <span aria-hidden="true">→</span></Link>
          </div>
        </div>
      </section>

      <section id="landing-benefits" data-scroll-reveal className="scroll-reveal w-full bg-white py-16 text-black sm:py-24 lg:py-32">
        <div className={sectionContainerClass}>
          <div className="grid grid-cols-1 gap-12 text-center sm:grid-cols-3 sm:gap-8 lg:gap-20">
            <div data-scroll-reveal data-scroll-delay="0" className="scroll-reveal">
              <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full border border-black sm:h-20 sm:w-20"><svg className="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="m5 12 4 4L19 6" /></svg></div>
              <h3 className="text-xl font-semibold sm:text-2xl">Curated footwear</h3>
              <p className="mx-auto mt-3 max-w-xs text-sm leading-relaxed text-black/60 sm:text-base">Thoughtful pairs selected to bring comfort and character to every day.</p>
            </div>
            <div data-scroll-reveal data-scroll-delay="90" className="scroll-reveal">
              <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full border border-black sm:h-20 sm:w-20"><svg className="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></div>
              <h3 className="text-xl font-semibold sm:text-2xl">Expert repairs</h3>
              <p className="mx-auto mt-3 max-w-xs text-sm leading-relaxed text-black/60 sm:text-base">Give your favorite footwear more life with trusted repair support.</p>
            </div>
            <div data-scroll-reveal data-scroll-delay="180" className="scroll-reveal">
              <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full border border-black sm:h-20 sm:w-20"><svg className="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 7h16M4 12h16M4 17h10" /></svg></div>
              <h3 className="text-xl font-semibold sm:text-2xl">One space for every step</h3>
              <p className="mx-auto mt-3 max-w-xs text-sm leading-relaxed text-black/60 sm:text-base">Shop, repair, and discover footwear services in one simple place.</p>
            </div>
          </div>
        </div>
      </section>

      <section id="landing-community" data-scroll-reveal className="scroll-reveal w-full overflow-hidden bg-black py-12 text-white sm:py-16 lg:py-20">
        <div className={`${sectionContainerClass} grid items-center gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.72fr)] lg:gap-16`}>
          <div data-scroll-reveal className="scroll-reveal scroll-reveal--side">
            <p className="mb-6 text-xs font-semibold uppercase tracking-[0.22em] text-white/60">JOIN THE SOLESPACE COMMUNITY</p>
            <h2 className="max-w-4xl text-[3.4rem] font-light leading-[0.86] tracking-[-0.07em] sm:text-7xl lg:text-[8.5rem]">STEP INTO SOLESPACE</h2>
            <p className="mt-7 max-w-xl text-base leading-relaxed text-white/70 sm:text-lg">Find your next pair, keep your favorites going, and make every step feel like yours.</p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:gap-4">
              <Link href={route('products')} className={`${buttonBaseClass} ${buttonLightClass} w-full sm:w-auto`}>Shop products</Link>
              <Link href={route('repair')} className={`${buttonBaseClass} ${buttonDarkClass} w-full sm:w-auto`}>Book a repair</Link>
            </div>
          </div>
          <div data-scroll-reveal data-scroll-delay="140" className="scroll-reveal scroll-reveal--scale aspect-[4/5] overflow-hidden bg-white/10">
            <img src="/images/shop/p2.jpg" alt="SoleSpace community footwear" className="h-full w-full object-cover" loading="lazy" decoding="async" sizes="(max-width: 1023px) 100vw, 38vw" />
          </div>
        </div>
      </section>
```

- [ ] **Step 4: Run the focused contract test and verify GREEN**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
```

Expected: PASS with all landing section, asset, integration, and removal assertions passing.

- [ ] **Step 5: Commit the landing markup and state cleanup**

```powershell
git add -- 'resources/js/Pages/UserSide/Products/LandingPage.tsx'
git commit -m "feat: redesign customer landing sections"
```

### Task 3: Add the scoped Norda-style scroll reveal and responsive motion behavior

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.tsx`

**Interfaces:**
- Consumes: existing `revealRootRef`, `prefersReducedMotionRef`, `data-scroll-reveal`, and `data-scroll-delay` behavior.
- Produces: landing-only reveal variants for vertical, side, and image-scale entrances with reduced-motion fallback.

- [ ] **Step 1: Update the existing reveal CSS without changing global styles**

Keep the existing hero animation rules and replace only the `.scroll-reveal` rules with this scoped style block:

```css
.scroll-reveal {
  opacity: 0;
  transform: translate3d(0, 36px, 0);
  transition: opacity 700ms ease, transform 800ms cubic-bezier(0.22, 1, 0.36, 1);
}

.scroll-reveal--side {
  transform: translate3d(32px, 0, 0);
}

.scroll-reveal--scale {
  transform: scale(1.04);
}

.scroll-reveal.is-visible {
  opacity: 1;
  transform: translate3d(0, 0, 0);
}

.scroll-reveal--scale.is-visible {
  transform: scale(1);
}

@media (prefers-reduced-motion: reduce) {
  .scroll-reveal,
  .scroll-reveal.is-visible {
    opacity: 1 !important;
    transform: none !important;
    transition: none !important;
  }
}
```

- [ ] **Step 2: Confirm the observer still sets delays and unobserves revealed elements**

Keep the existing observer behavior with `threshold: 0.16`, `rootMargin: '0px 0px -10% 0px'`, `element.classList.add('is-visible')`, `observer.unobserve(element)`, and the existing `data-scroll-delay` inline transition delay. Do not add a `window.addEventListener('scroll', ...)` implementation.

- [ ] **Step 3: Run the focused test after the motion change**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
```

Expected: PASS; the animation remains represented by landing-page-local source and the test still verifies the reduced-motion hook.

- [ ] **Step 4: Commit the motion rules**

```powershell
git add -- 'resources/js/Pages/UserSide/Products/LandingPage.tsx'
git commit -m "feat: add landing page scroll reveals"
```

### Task 4: Run quality gates and browser verification

**Files:**
- Read/verify: `resources/js/Pages/UserSide/Products/LandingPage.tsx`
- Read/verify: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`
- Temporary test script only if needed: `tmp/landing_page_check.py`

**Interfaces:**
- Consumes: the completed landing page and local Laravel/Vite application.
- Produces: fresh test, build, diff, and browser evidence for desktop/mobile layout and on-scroll behavior.

- [ ] **Step 1: Run the complete frontend test suite**

Run:

```powershell
pnpm run test:frontend
```

Expected: exit code 0 with no new failures. If a baseline failure remains, report the exact test and distinguish it from the recorded baseline.

- [ ] **Step 2: Build the frontend bundle**

Run:

```powershell
pnpm run build
```

Expected: exit code 0 and Vite emits the production bundle. Do not edit generated files by hand.

- [ ] **Step 3: Run the repository diff hygiene check**

Run:

```powershell
git diff --check HEAD~3..HEAD
```

Expected: no whitespace errors for the commits created by this plan. Also inspect `git status --short` and confirm unrelated ERP/package changes remain present and unmodified by this work.

- [ ] **Step 4: Read the webapp-testing helper usage before browser verification**

Run:

```powershell
python 'C:/programmers/xampp/files/htdocs/solespace-master/.agents/skills/webapp-testing/scripts/with_server.py' --help
```

Expected: usage text. Then start the local Laravel/Vite servers with the exact commands below; use headless Chromium and wait for `networkidle` before inspecting the DOM.

For this repository, use these exact commands when the browser script is ready:

```powershell
python 'C:/programmers/xampp/files/htdocs/solespace-master/.agents/skills/webapp-testing/scripts/with_server.py' --server "php artisan serve --host=127.0.0.1 --port=8000" --port 8000 --server "pnpm run dev -- --host 127.0.0.1 --port 5173" --port 5173 --timeout 60 -- python 'tmp/landing_page_check.py'
```

The Playwright script must navigate to `http://127.0.0.1:8000/`; Vite assets will be served from the second managed server.

- [ ] **Step 5: Verify desktop landing behavior with Playwright**

Use a native Playwright script with these assertions after navigating to `/` at a 1440×900 viewport:

```python
page.goto(base_url + '/', wait_until='networkidle')
page.locator('#landing-new-releases').wait_for()
assert page.locator('#landing-categories').count() == 1
assert page.locator('#landing-story').count() == 1
assert page.locator('#landing-benefits').count() == 1
assert page.locator('#landing-community').count() == 1
assert page.locator('#landing-new-releases a[href*="/products"]').count() >= 1
assert page.locator('#landing-categories a').count() == 3
page.locator('#landing-community').scroll_into_view_if_needed()
page.wait_for_timeout(900)
assert page.locator('#landing-community.is-visible').count() == 1
assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth')
```

Also capture a full-page screenshot for visual inspection and record any browser console errors.

- [ ] **Step 6: Verify mobile layout and reduced-motion behavior with Playwright**

At a 390×844 viewport, assert:

```python
assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth')
assert page.locator('#landing-new-releases').locator('a').first.get_attribute('class')
page.emulate_media(reduced_motion='reduce')
page.reload(wait_until='networkidle')
assert page.locator('#landing-community.is-visible').count() == 1
```

Confirm that only the product rail is horizontally scrollable, category tiles stack/read well, CTA buttons remain reachable, and no console error is introduced.

- [ ] **Step 7: Perform the sequential review stack before completion**

Review the final diff in this order:

1. Simplification: remove any unused imports, obsolete stats code, unused helpers, or unnecessary dependency/abstraction.
2. Standards/spec: compare the diff against the committed design spec and this plan; confirm only the landing page runtime plus its landing test changed.
3. TypeScript/React: confirm stable keys, typed product access, readable control flow, functional state updates where state remains, no `any`, no inline component definitions, and no unnecessary bundle import.
4. Performance: confirm lazy images, reserved aspect ratios, transform/opacity-only motion, no scroll listener, and no new dependency.
5. Security: mark `N/A` for auth/input/API changes; confirm no external or secret-bearing source was introduced.
6. Verification: re-run any stale focused command after review edits, then preserve exact exit codes and outputs for the final report.

- [ ] **Step 8: Record durable learning only if a reusable project lesson was discovered**

Add a short entry to `docs/ai-learning-log.md` only if this work reveals a durable SoleSpace convention that future landing-page work should follow. Do not record one-off visual choices, temporary failures, secrets, or personal data.

- [ ] **Step 9: Report exact evidence and leave unrelated changes untouched**

Before claiming completion, inspect:

```powershell
git status --short
git diff --stat HEAD~3..HEAD
```

Report the focused test, full frontend tests, build, diff check, and browser results exactly as observed. Do not claim TypeScript or lint success because the repository has no committed `tsconfig` or frontend lint script.
