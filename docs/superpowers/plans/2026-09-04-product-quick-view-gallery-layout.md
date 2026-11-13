# Product Quick View Gallery Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the product quick-view gallery responsive, white, and free of horizontal scrolling or stretched bottom gaps.

**Architecture:** Keep the existing `ProductQuickView` component, image navigation, thumbnail buttons, and cart action unchanged. Make only the modal/gallery layout classes responsive: the dialog blocks horizontal overflow, the gallery sizes to its content, the image keeps its natural aspect ratio, and thumbnails wrap.

**Tech Stack:** React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library, Vite.

## Global Constraints

- Remove horizontal/landscape scrolling from the thumbnail gallery.
- Keep every thumbnail accessible by wrapping them within the available width.
- Let the main image preserve its natural aspect ratio and scale responsively.
- Prevent the left gallery column from stretching below its content.
- Use white backgrounds for the gallery surface, image frame, and thumbnails.
- Preserve existing image navigation, keyboard closing, cart behavior, and responsive modal behavior.
- Do not change product image URLs, image ordering, or fallback behavior.
- Do not crop product images with `object-cover`.
- Do not change the dimmed modal backdrop; it remains intentional modal context.
- Do not change the product details or cart workflow.

---

### Task 1: Add the gallery layout regression test

**Files:**
- Modify: `resources/js/components/products/__tests__/ProductQuickView.test.tsx`

**Interfaces:**
- Consumes: the existing `ProductQuickView` component and `product` fixture.
- Produces: a regression test that fails until the dialog, gallery, image frame, and thumbnail layout classes match the approved design.

- [ ] **Step 1: Write the failing test**

Add this test after the existing centering test:

```tsx
  it('uses a flexible white gallery without horizontal thumbnail scrolling', () => {
    render(<ProductQuickView product={product} detailsHref="/products/solespace-runner" onClose={vi.fn()} />);

    const dialog = screen.getByRole('dialog', { name: 'SoleSpace Runner' });
    const gallery = screen.getByRole('region', { name: 'Product images' });
    const imageFrame = screen.getByAltText('SoleSpace Runner image 1').parentElement;
    const thumbnails = screen.getByLabelText('Product image thumbnails');

    expect(dialog).toHaveClass('overflow-x-hidden');
    expect(gallery).toHaveClass('h-fit', 'self-start', 'bg-white');
    expect(imageFrame).toHaveClass('bg-white');
    expect(imageFrame).not.toHaveClass('aspect-square');
    expect(thumbnails).toHaveClass('flex-wrap');
    expect(thumbnails).not.toHaveClass('overflow-x-auto');
  });
```

- [ ] **Step 2: Run the focused test to verify it fails**

Run:

```powershell
pnpm exec vitest run resources/js/components/products/__tests__/ProductQuickView.test.tsx
```

Expected: FAIL because the current dialog lacks `overflow-x-hidden`, the gallery uses slate backgrounds and stretches, the image frame has `aspect-square`, and the thumbnails use `overflow-x-auto`.

---

### Task 2: Implement the responsive white gallery layout

**Files:**
- Modify: `resources/js/components/products/ProductQuickView.tsx:139-232`

**Interfaces:**
- Consumes: the existing `images`, `selectedImage`, `moveImage`, and thumbnail button behavior.
- Produces: the same rendered product gallery and interactions with responsive layout classes only.

- [ ] **Step 1: Update the modal overflow classes**

Change the overlay and dialog class names to keep vertical scrolling available while preventing horizontal overflow:

```tsx
      className="fixed inset-0 z-[60] flex items-center justify-center overflow-x-hidden overflow-y-auto bg-slate-950/60 p-3 transition-opacity duration-200 motion-reduce:transition-none sm:p-6"
```

```tsx
        className="relative my-0 grid max-h-[calc(100vh-1.5rem)] w-full max-w-5xl overflow-x-hidden overflow-y-auto bg-white shadow-2xl transition-transform duration-200 motion-reduce:transition-none sm:my-4 sm:max-h-[calc(100vh-3rem)] lg:grid-cols-2"
```

- [ ] **Step 2: Make the gallery content-sized and white**

Replace the gallery section and main image frame classes with:

```tsx
        <section className="h-fit self-start bg-white p-4 sm:p-6" aria-label="Product images">
          <div className="relative flex min-h-64 items-center justify-center overflow-hidden bg-white">
```

This keeps the gallery from stretching to the details column height and gives the image frame a white surface without forcing a square aspect ratio.

- [ ] **Step 3: Make the main image preserve its natural ratio**

Replace the main image class with:

```tsx
                className="block h-auto w-auto max-h-[min(70vh,40rem)] max-w-full object-contain"
```

Keep `onError`, `src`, and `alt` unchanged. Keep the fallback branch so products without a valid image still render a readable `No image available` state.

- [ ] **Step 4: Remove the thumbnail horizontal scroll and enable wrapping**

Replace the thumbnail container class with:

```tsx
            <div className="mt-3 flex flex-wrap justify-center gap-2" aria-label="Product image thumbnails">
```

Keep the existing fixed thumbnail button dimensions, `shrink-0`, image ordering, selection state, and lazy loading unchanged.

- [ ] **Step 5: Run the focused test to verify it passes**

Run:

```powershell
pnpm exec vitest run resources/js/components/products/__tests__/ProductQuickView.test.tsx
```

Expected: PASS with all ProductQuickView tests passing.

- [ ] **Step 6: Commit the component and regression test**

Run:

```powershell
git add resources/js/components/products/ProductQuickView.tsx resources/js/components/products/__tests__/ProductQuickView.test.tsx
git commit -m "fix: make quick view gallery responsive"
```

---

### Task 3: Run the full verification gate and refresh deployment assets

**Files:**
- Modify: `public/build/` (generated output only)

**Interfaces:**
- Consumes: the approved gallery source and regression test from Task 2.
- Produces: verified frontend tests, a fresh production build, and committed deployment assets.

- [ ] **Step 1: Run the full frontend test suite**

Run:

```powershell
pnpm run test:frontend
```

Expected: exit code 0 with all frontend test files and tests passing.

- [ ] **Step 2: Build fresh production assets**

Run:

```powershell
pnpm run build
```

Expected: exit code 0 with Vite completing the production build and updating `public/build`.

- [ ] **Step 3: Check diff hygiene**

Run:

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors; only the generated `public/build` output is uncommitted after the source commit, and existing unrelated user changes remain unstaged.

- [ ] **Step 4: Commit the fresh generated assets**

Run:

```powershell
git add public/build
git commit -m "chore: refresh frontend build"
```

- [ ] **Step 5: Review the final committed diff**

Run:

```powershell
git diff --check HEAD~2..HEAD
git diff --name-status HEAD~2..HEAD
```

Expected: the two new commits contain only the quick-view component/test and fresh `public/build`; the pre-existing unrelated worktree changes are not staged or committed.
