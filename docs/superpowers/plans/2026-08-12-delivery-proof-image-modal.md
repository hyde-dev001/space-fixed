# Delivery Proof Image Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the delivery-proof text link with a full-width image preview and an accessible in-page enlarged-image modal.

**Architecture:** Keep the behavior inside the existing `Shipments` page and reuse its existing `Modal`. A small piece of page state selects a proof URL; while selected, the shipment details stay mounted but hidden and the image viewer becomes the only visible dialog. This avoids nested active-dialog and Escape conflicts while preserving the original trigger node for reliable focus restoration. Existing proof action endpoints and buttons remain unchanged.

**Tech Stack:** React 18, TypeScript 5.7, Tailwind CSS 4, Testing Library, Vitest, existing ERP `Modal` component.

## Global Constraints

- Do not add dependencies or change backend/API behavior.
- Do not open delivery proofs in a new tab.
- Preview uses `object-cover`; enlarged image uses `object-contain`.
- Enlarged viewer closes through its top-left button, `Escape`, or backdrop click.
- Restore focus to the proof `View` trigger after closing the viewer.
- Preserve existing approval, rejection, and return-receipt behavior.
- Preserve unrelated `package-lock.json`, `.pnpm-store/`, and `DESIGN.md` worktree changes.

---

### Task 1: Delivery proof preview and enlarged viewer

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

**Interfaces:**
- Consumes: `proof.proof_url`, the existing shipment `Modal`, `trapDialogFocus`, and existing proof review actions.
- Produces: a `View delivery proof` button that opens a `Delivery proof image` dialog and a `Close delivery proof image` button that restores trigger focus.

- [ ] **Step 1: Write the failing interaction test**

Replace the completed-proof assertion with a test that verifies the new preview, absence of the old link, enlarged viewer, complete image rendering, top-left close control, and restored focus:

```tsx
it('opens completed delivery proof in an in-page image modal and restores focus', async () => {
  setDispatcherLeg({
    id: 2,
    leg_type: 'outbound',
    status: 'delivered',
    assignments: [],
    proofs: [{ id: 17, handoff_type: 'delivery', review_status: 'approved', proof_url: '/api/logistics/proofs/17/file' }],
    attempts: [],
  });

  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));

  const view = screen.getByRole('button', { name: 'View delivery proof' });
  expect(screen.queryByText('View delivery proof')).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Open uploaded delivery proof' })).not.toBeInTheDocument();
  expect(screen.getByAltText('Uploaded delivery proof')).toHaveClass('object-cover');

  fireEvent.click(view);

  expect(screen.getByRole('dialog', { name: 'Delivery proof image' })).toBeInTheDocument();
  expect(screen.getByAltText('Enlarged delivery proof')).toHaveAttribute('src', '/api/logistics/proofs/17/file');
  expect(screen.getByAltText('Enlarged delivery proof')).toHaveClass('object-contain');
  const close = screen.getByRole('button', { name: 'Close delivery proof image' });
  expect(close).toHaveClass('left-4', 'top-4');

  fireEvent.click(close);

  await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Delivery proof image' })).not.toBeInTheDocument());
  expect(document.activeElement).toBe(view);
});
```

Update the pending-proof test to assert the `View delivery proof` button instead of the removed new-tab link, while retaining its reject-proof request assertion.

- [ ] **Step 2: Run the focused test to verify it fails**

Run:

```powershell
.\node_modules\.bin\vitest.CMD run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx --reporter=dot
```

Expected: FAIL because the current proof is a link, no image viewer exists, and the current thumbnail is not full-width.

- [ ] **Step 3: Add minimal proof viewer state and focus behavior**

In `Shipments`, add:

```tsx
const [selectedProofUrl, setSelectedProofUrl] = useState<string | null>(null);
const proofTriggerRef = useRef<HTMLButtonElement | null>(null);
const proofCloseButtonRef = useRef<HTMLButtonElement | null>(null);

const openProof = (url: string, trigger: HTMLButtonElement) => {
  proofTriggerRef.current = trigger;
  setSelectedProofUrl(url);
};

const closeProof = () => {
  const trigger = proofTriggerRef.current;
  setSelectedProofUrl(null);
  window.requestAnimationFrame(() => trigger?.focus());
};
```

Focus `proofCloseButtonRef` when `selectedProofUrl` becomes non-null. Clear proof state in `closeShipment`. Pass `selectedProofUrl ? closeProof : closeShipment` to the existing `Modal` so Escape and backdrop clicks close only the proof viewer while it is active.

- [ ] **Step 4: Render the accessible enlarged viewer**

Hide the shipment detail dialog with a conditional `hidden` class and `aria-hidden` while `selectedProofUrl` is active, then conditionally render this viewer as its sibling:

```tsx
<div
  role="dialog"
  aria-modal="true"
  aria-label="Delivery proof image"
  onKeyDown={trapDialogFocus}
  className="relative flex h-[min(88dvh,56rem)] items-center justify-center overflow-hidden rounded-3xl bg-gray-950 p-4 sm:p-8"
>
  <button
    ref={proofCloseButtonRef}
    type="button"
    aria-label="Close delivery proof image"
    onClick={closeProof}
    className="absolute left-4 top-4 z-10 inline-flex min-h-11 min-w-11 items-center justify-center rounded-full bg-black/65 text-white backdrop-blur-sm transition-colors hover:bg-black/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
  >
    <X aria-hidden="true" size={22} />
  </button>
  <img src={selectedProofUrl} alt="Enlarged delivery proof" className="h-full w-full object-contain" />
</div>
```

- [ ] **Step 5: Replace the thumbnail/link with the full-width preview**

For each eligible proof URL, render a full-width landscape preview and centered overlay button:

```tsx
<div className="relative h-48 w-full overflow-hidden rounded-xl border border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-gray-900">
  <img src={proof.proof_url} alt="Uploaded delivery proof" className="h-full w-full object-cover" />
  <div className="absolute inset-0 flex items-center justify-center bg-black/15 transition-colors hover:bg-black/25">
    <button
      type="button"
      aria-label="View delivery proof"
      onClick={(event) => openProof(proof.proof_url!, event.currentTarget)}
      className="rounded-lg bg-black/65 px-4 py-2 text-sm font-semibold text-white shadow-lg backdrop-blur-sm transition-colors hover:bg-black/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
    >
      View
    </button>
  </div>
</div>
```

Keep existing approve/reject/receive controls in a separate wrapping row below the preview.

- [ ] **Step 6: Run focused tests and resolve only related failures**

Run:

```powershell
.\node_modules\.bin\vitest.CMD run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx --reporter=dot
```

Expected: all shipment tests PASS.

- [ ] **Step 7: Run quality and regression gates**

Run:

```powershell
.\node_modules\.bin\vitest.CMD run --reporter=dot
.\node_modules\.bin\vite.CMD build
git diff --check
```

Expected: all frontend tests PASS, Vite production build succeeds, and diff hygiene passes.

- [ ] **Step 8: Review scope and commit**

Confirm the source/test diff is limited to the two listed files, plus the fresh `public/build` requested for deployment. Do not stage unrelated worktree files.

```powershell
git add -- resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx public/build
git commit -m "feat: add delivery proof image viewer"
```
