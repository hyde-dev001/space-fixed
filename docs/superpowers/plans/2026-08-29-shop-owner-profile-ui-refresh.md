# Shop Owner Profile UI Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refresh the Shop Owner profile page with a responsive Facebook-style cover/profile layout and monochrome Light Mode controls without changing backend contracts.

**Architecture:** Keep the existing `shopProfile.tsx` page and its upload, password, edit-form, and operating-hours handlers. Replace the duplicated mobile/desktop visual markup with shared presentational sections and explicit Light/Dark utility classes, while preserving all existing routes and field names.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Vite.

## Global Constraints

- Do not change Laravel routes, controllers, migrations, models, validation rules, API endpoints, or database behavior.
- Preserve `POST /shop-owner/shop-profile` and `POST /shop-owner/shop-profile/password` payload keys.
- Preserve the existing photo upload/delete endpoints and response field names.
- Light Mode uses SoleSpace monochrome tokens from `DESIGN.md`; Dark Mode retains explicit dark variants.
- Preserve unrelated working-tree changes and do not edit `.env`, `vendor/`, or `node_modules/`.

---

### Task 1: Record the approved design

**Files:**
- Create: `docs/superpowers/specs/2026-08-29-shop-owner-profile-ui-design.md`
- Create: `docs/superpowers/plans/2026-08-29-shop-owner-profile-ui-refresh.md`

**Interfaces:**
- Consumes: the existing `shopProfile.tsx` handlers and `ShopProfileController` contracts.
- Produces: an approved UI design and an executable file-level implementation plan.

- [x] **Step 1: Write the design and plan documents**
- [x] **Step 2: Self-review for scope, placeholders, and contract consistency**
- [ ] **Step 3: Commit the design documents without staging unrelated changes**

Run:

```powershell
git add docs/superpowers/specs/2026-08-29-shop-owner-profile-ui-design.md docs/superpowers/plans/2026-08-29-shop-owner-profile-ui-refresh.md
git commit -m "docs: plan shop owner profile ui refresh"
```

Expected: only the two planning documents are included in the commit.

### Task 2: Add focused UI regression coverage

**Files:**
- Modify: `resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx`
- Test: `resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx`

**Interfaces:**
- Consumes: the existing mocked Inertia page props and router.
- Produces: assertions for the profile hero, monochrome action labels, and preserved password section.

- [ ] **Step 1: Extend the test fixture with profile and cover photo props**
- [ ] **Step 2: Add assertions for the profile identity, photo actions, and labeled sections**
- [ ] **Step 3: Run the focused test before implementation**

Run:

```powershell
node_modules\\.bin\\vitest.cmd run resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx --reporter=dot
```

Expected: existing assertions pass; any new structure assertions fail until Task 3 is implemented.

### Task 3: Refactor the Shop Profile presentation

**Files:**
- Modify: `resources/js/Pages/ShopOwner/Settings/shopProfile.tsx`

**Interfaces:**
- Consumes: unchanged `ShopOwner`, `OperatingHours`, upload handlers, password handler, edit modal, and operating-hours modal.
- Produces: responsive shared profile layout with monochrome Light Mode controls and preserved Dark Mode variants.

- [ ] **Step 1: Keep all existing request URLs, payload keys, and response handling unchanged**
- [ ] **Step 2: Replace duplicated mobile/desktop profile markup with a responsive cover/profile hero**
- [ ] **Step 3: Apply neutral token-based buttons, cards, inputs, badges, and section headers**
- [ ] **Step 4: Add accessible labels, photo action feedback, and responsive overflow handling**
- [ ] **Step 5: Restyle the edit and operating-hours modals without removing dark variants**
- [ ] **Step 6: Remove only imports or markup made unused by this refactor**

Run after the edit:

```powershell
node_modules\\.bin\\vitest.cmd run resources/js/Pages/ShopOwner/Settings/__tests__/shopProfile.security.test.tsx --reporter=dot
```

Expected: focused profile tests pass with no backend contract changes.

### Task 4: Verify and package the UI update

**Files:**
- Modify: `public/build/**`

**Interfaces:**
- Consumes: the completed Shop Profile page and focused tests.
- Produces: verified frontend assets and a reviewable diff.

- [ ] **Step 1: Run the full frontend test suite**

```powershell
node_modules\\.bin\\vitest.cmd run --reporter=dot
```

- [ ] **Step 2: Build fresh production assets**

```powershell
node_modules\\.bin\\vite.cmd build
```

- [ ] **Step 3: Run diff hygiene and inspect changed files**

```powershell
git diff --check
git status --short
git diff --stat
```

- [ ] **Step 4: Confirm backend source is unchanged**

```powershell
git diff --name-only HEAD -- app routes database
```

Expected: no backend files are changed by this UI refresh.
