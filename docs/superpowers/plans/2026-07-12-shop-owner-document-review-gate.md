# Shop Owner Document Review Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Disable approval and rejection until every submitted document for the selected shop-owner registration has been opened.

**Architecture:** Keep session-only review progress in `ShopOwnerRegistrationView` as document-index sets keyed by registration ID. Use one small exported predicate for the action-button gate so the non-trivial rule has a focused Vitest check without adding dependencies or backend storage.

**Tech Stack:** React 18, TypeScript, Vitest, existing Tailwind CSS and Button component

## Global Constraints

- Clicking `View` is what marks a document as viewed.
- Preserve viewed progress per registration until the page reloads.
- Registrations with zero documents must keep approval and rejection disabled.
- Change no backend behavior and add no dependency.

---

### Task 1: Document Review Gate

**Files:**
- Modify: `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx:165-180,691-777`
- Create: `resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts`

**Interfaces:**
- Consumes: selected registration ID and `selectedRegistrationDocuments.length`
- Produces: `areAllDocumentsViewed(documentCount: number, viewedDocuments?: Set<number>): boolean`

- [ ] **Step 1: Write the failing predicate test**

```ts
import { describe, expect, it } from 'vitest';
import { areAllDocumentsViewed } from '../ShopOwnerRegistrationView';

describe('areAllDocumentsViewed', () => {
  it('requires every submitted document to be viewed', () => {
    expect(areAllDocumentsViewed(0, new Set())).toBe(false);
    expect(areAllDocumentsViewed(2, new Set([0]))).toBe(false);
    expect(areAllDocumentsViewed(2, new Set([0, 1]))).toBe(true);
  });
});
```

- [ ] **Step 2: Run the test and verify RED**

Run: `pnpm vitest run resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts`

Expected: FAIL because `areAllDocumentsViewed` is not exported.

- [ ] **Step 3: Add the minimal review state and gate**

Add the predicate near the existing interfaces:

```ts
export const areAllDocumentsViewed = (documentCount: number, viewedDocuments = new Set<number>()) =>
  documentCount > 0 && viewedDocuments.size >= documentCount;
```

Add component state and derive the current gate:

```ts
const [viewedDocuments, setViewedDocuments] = useState<Record<number, Set<number>>>({});
const allDocumentsViewed = areAllDocumentsViewed(
  selectedRegistrationDocuments.length,
  selectedRegistration ? viewedDocuments[selectedRegistration.id] : undefined,
);
```

Inside the existing document `View` click handler, record the viewed index before toggling the inline preview:

```ts
setViewedDocuments((current) => ({
  ...current,
  [selectedRegistration.id]: new Set([
    ...(current[selectedRegistration.id] ?? []),
    index,
  ]),
}));
```

Show `Viewed` next to the document name when its index is present:

```tsx
{viewedDocuments[selectedRegistration.id]?.has(index) && (
  <span className="text-xs font-medium text-green-600 dark:text-green-400">Viewed</span>
)}
```

Apply the native disabled state and existing Tailwind styling to both pending action buttons:

```tsx
disabled={!allDocumentsViewed}
className="bg-red-600 hover:bg-red-700 text-white disabled:cursor-not-allowed disabled:opacity-50"
```

```tsx
disabled={!allDocumentsViewed}
className="bg-green-600 hover:bg-green-700 text-white disabled:cursor-not-allowed disabled:opacity-50"
```

- [ ] **Step 4: Run the focused test and verify GREEN**

Run: `pnpm vitest run resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts`

Expected: one test passes.

- [ ] **Step 5: Run the production build**

Run: `pnpm build`

Expected: Vite exits with code 0 and reports a completed build.

- [ ] **Step 6: Review the final diff**

Run: `git diff -- resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts`

Expected: only the session review state, viewed marker, disabled action gate, and focused test are present. If Git metadata remains unavailable, inspect those two files directly instead.
