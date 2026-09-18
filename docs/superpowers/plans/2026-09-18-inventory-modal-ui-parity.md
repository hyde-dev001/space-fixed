# Inventory Modal UI Parity Implementation Plan

> **For agentic workers:** Use inline sequential execution for this plan. Do not dispatch subagents or commit changes unless explicitly requested.

**Goal:** Make inventory add/edit stock modals match the existing product variant UI and add one searchable named-color picker to both product and inventory flows.

**Architecture:** Keep `UploadInventory.tsx` as the owner of inventory state and API persistence. Extend the existing shared variant/image components only with optional behavior so current product-management callers retain their defaults. Put named colors and search normalization in one small data utility consumed by `ColorVariantManager`.

**Tech Stack:** React 18, TypeScript 5.7, Inertia, Tailwind CSS 4, Vitest, Testing Library, existing Laravel inventory APIs.

## Global Constraints

- No database, migration, route, controller, or authorization changes.
- No new dependency.
- Preserve existing inventory API calls, validation, confirmations, permission guards, and repair-material flow.
- Use `pnpm` for frontend commands.
- Keep existing product-management behavior unchanged when optional hooks are not supplied.

---

### Task 1: Add the shared named-color catalog and search helper

**Files:**
- Create: `resources/js/data/namedColors.ts`
- Create: `resources/js/data/__tests__/namedColors.test.ts`

**Interfaces:**
- Produces `NamedColor = { name: string; code: string }`.
- Produces `NAMED_COLORS: readonly NamedColor[]`.
- Produces `searchNamedColors(query: string): NamedColor[]`.

- [ ] **Step 1: Write the failing tests**

Create tests that prove the search is case-insensitive, matches a named color, returns its hex value, and returns the full catalog for an empty query:

```ts
import { describe, expect, it } from 'vitest';
import { NAMED_COLORS, searchNamedColors } from '../namedColors';

describe('named color search', () => {
  it('finds Indigo by name regardless of query casing', () => {
    expect(searchNamedColors('InDiGo')).toContainEqual({ name: 'Indigo', code: '#4B0082' });
  });

  it('returns the complete catalog for an empty query', () => {
    expect(searchNamedColors('')).toEqual(NAMED_COLORS);
  });

  it('returns no result for an unknown name', () => {
    expect(searchNamedColors('not-a-real-color')).toEqual([]);
  });
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```bash
pnpm exec vitest run resources/js/data/__tests__/namedColors.test.ts
```

Expected: FAIL because `namedColors.ts` does not exist yet.

- [ ] **Step 3: Implement the catalog and helper**

Add an explicit static catalog containing the CSS/X11 named-color vocabulary, including `Indigo`, plus the repository's common palette names where they are not CSS names. Normalize queries with trim, lowercase, and whitespace collapse; match the normalized name with `includes` so `indigo` and `dark indigo`-style partial searches remain predictable. Keep the existing custom color input as the fallback for names outside the catalog.

Define `NamedColor`, export the complete explicit `NAMED_COLORS` table sorted by display name, and export `searchNamedColors` with this exact normalization and matching behavior:

```ts
export type NamedColor = { name: string; code: string };

export const searchNamedColors = (query: string): NamedColor[] => {
  const normalizedQuery = query.trim().toLowerCase().replace(/\s+/g, ' ');
  if (!normalizedQuery) return [...NAMED_COLORS];

  return NAMED_COLORS.filter((color) =>
    color.name.toLowerCase().includes(normalizedQuery),
  );
};
```

- [ ] **Step 4: Run the focused test and verify it passes**

Run the same Vitest command. Expected: all named-color tests pass.

---

### Task 2: Add searchable color selection to the shared variant manager

**Files:**
- Modify: `resources/js/components/variants/ColorVariantManager.tsx:1-500`
- Create: `resources/js/components/variants/__tests__/ColorVariantManager.test.tsx`

**Interfaces:**
- Consumes `NAMED_COLORS` and `searchNamedColors` from Task 1.
- Produces the existing `ColorVariant` through the unchanged `onColorVariantsChange` callback.

- [ ] **Step 1: Write the failing component test**

Render an empty manager, open `Add Color`, search for `indigo`, click the visible Indigo result, and assert that a new variant is emitted with the expected name and swatch code. Also assert that custom color input remains visible.

```tsx
it('searches and selects a named Indigo color', () => {
  const onChange = vi.fn();
  render(<ColorVariantManager colorVariants={[]} onColorVariantsChange={onChange} />);

  fireEvent.click(screen.getByRole('button', { name: /add color/i }));
  fireEvent.change(screen.getByRole('searchbox', { name: /search named colors/i }), {
    target: { value: 'indigo' },
  });
  fireEvent.click(screen.getByRole('button', { name: /^indigo$/i }));

  expect(onChange).toHaveBeenCalledWith([
    expect.objectContaining({ color_name: 'Indigo', color_code: '#4B0082' }),
  ]);
  expect(screen.getByPlaceholderText(/forest green/i)).toBeInTheDocument();
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```bash
pnpm exec vitest run resources/js/components/variants/__tests__/ColorVariantManager.test.tsx
```

Expected: FAIL because the picker has no searchbox or searchable Indigo result.

- [ ] **Step 3: Implement the shared search UI**

Replace the hard-coded quick-color lookup with the shared catalog while preserving the existing twelve quick-select colors. Add a labeled native search input above the color grid, keep the grid keyboard-selectable, filter results through `searchNamedColors`, clear the query after a successful selection, and show a short no-results message while leaving the custom color field available. Keep duplicate-color checks and combined quick-color selection unchanged.

- [ ] **Step 4: Run the focused component test and verify it passes**

Run the same Vitest command. Expected: the Indigo selection test passes without changing existing manager tests (there were none before this task).

---

### Task 3: Preserve inventory image actions while adopting the shared gallery treatment

**Files:**
- Modify: `resources/js/components/variants/ColorVariantImageUploader.tsx:10-285`
- Modify: `resources/js/Pages/ERP/inventory/UploadInventory.tsx:527-624,1280-1570`

**Interfaces:**
- Adds optional uploader hooks:

```ts
type ColorVariantImageUploaderProps = {
  colorName: string;
  images: ColorVariantImage[];
  onImagesChange: (images: ColorVariantImage[]) => void;
  maxImages?: number;
  readOnly?: boolean;
  onAddFiles?: (files: File[]) => void | Promise<void>;
  onRemoveImage?: (imageId: string) => void | Promise<void>;
  onSetThumbnail?: (imageId: string) => void | Promise<void>;
  disableReorder?: boolean;
};
```

- Existing callers continue to use local `onImagesChange` behavior when hooks are omitted.
- `UploadInventory.tsx` consumes existing `inventoryItemAPI.uploadImages`, `deleteImage`, and `setThumbnail` operations.

- [ ] **Step 1: Add a focused failing contract test for the persisted image hooks**

Add a small component test that renders the uploader with `onAddFiles`, `onRemoveImage`, and `onSetThumbnail`, then verifies those callbacks are used instead of mutating local images directly. Keep the test limited to callback routing; the existing default local behavior remains covered by the implementation path.

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```bash
pnpm exec vitest run resources/js/components/variants/__tests__/ColorVariantImageUploader.test.tsx
```

Expected: FAIL because the optional callback props do not exist.

- [ ] **Step 3: Implement optional persisted image hooks**

Route add/remove/thumbnail events to the optional callbacks when supplied. Keep validation and max-image checks in the uploader. When `disableReorder` is true, disable drag handlers and hide the reorder hint so inventory never exposes a non-persisted action.

- [ ] **Step 4: Add inventory thumbnail persistence**

Implement `handleSetColorImageThumbnail` in `UploadInventory.tsx` using `inventoryItemAPI.setThumbnail`, then update local thumbnail flags only after success. Keep existing upload/delete error dialogs and loading state unchanged.

- [ ] **Step 5: Restyle existing inventory color cards using the shared gallery treatment**

Replace the compact inventory image row with `ColorVariantImageUploader` configured with the existing inventory callbacks and `disableReorder`. Keep the current inventory size chips, add-size form, quantity editor, and server calls, but use the same variant header, border, spacing, expanded-card, and size-card classes as `ColorVariantManager`.

- [ ] **Step 6: Run the focused uploader and inventory tests**

Run:

```bash
pnpm exec vitest run resources/js/components/variants/__tests__/ColorVariantImageUploader.test.tsx resources/js/components/variants/__tests__/ColorVariantManager.test.tsx
```

Expected: all focused tests pass.

---

### Task 4: Align both inventory modal shells and verify both page call paths

**Files:**
- Modify: `resources/js/Pages/ERP/inventory/UploadInventory.tsx:1219-1770`
- Modify: `resources/js/Pages/ERP/STAFF/ProductManagementWithVariants.tsx` only if the shared manager integration requires an import adjustment; otherwise leave unchanged.
- Modify: `docs/superpowers/specs/2026-09-18-inventory-modal-ui-parity-design.md` to record the searchable-color addition.

**Interfaces:**
- `UploadInventory.tsx` continues to call `ColorVariantManager` for add mode and new colors in edit mode.
- Product management continues to call `ColorVariantManager` with default behavior.

- [ ] **Step 1: Write the failing modal layout contract**

Add a source/layout contract test that checks the inventory modal uses the same `max-w-7xl` shell, fixed two-column footer, and `ColorVariantManager` call path for add/new-color flows.

- [ ] **Step 2: Run the contract test and verify it fails**

Run the focused contract test and confirm the current `max-w-6xl` inventory shell is the expected failure.

- [ ] **Step 3: Align the inventory shell**

Change only the inventory modal shell and section classes needed to match the product modal: `max-w-7xl`, matching border/radius treatment, shared body spacing, and the same fixed footer treatment. Preserve repair-mode conditional sections and all existing form handlers.

- [ ] **Step 4: Run the complete frontend suite**

Run:

```bash
pnpm run test:frontend
```

Expected: exit code 0 with no failed tests.

- [ ] **Step 5: Build the frontend**

Run:

```bash
pnpm run build
```

Expected: Vite production build exits with code 0.

- [ ] **Step 6: Inspect the final diff and hygiene**

Run:

```bash
git status --short
git diff --stat
git diff --check
git diff
```

Confirm only the requested UI, shared component, named-color, test, and spec files changed; no `.env`, lockfile, generated vendor/node_modules file, debug output, or unrelated user work changed.

---

## Plan self-review

- Modal parity: Task 4 aligns the inventory shell; Task 3 aligns existing color cards and galleries; Task 2 preserves the shared variant manager used by both pages.
- Color search: Task 1 provides the one catalog/search function; Task 2 wires it into every `ColorVariantManager` call path.
- Data safety: Task 3 keeps existing inventory API calls and guards; no backend changes are planned.
- Verification: every non-trivial behavior has a focused test before implementation, followed by the full frontend test suite, production build, and final diff inspection.
- No placeholders or unbounded feature work are included; custom color input remains the fallback beyond the maintained named catalog.
