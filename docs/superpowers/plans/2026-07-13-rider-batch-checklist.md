# Rider Batch Checklist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn My delivery batches into a status-driven route checklist with progress, automatic completion, and next-stop guidance.

**Architecture:** Keep the existing backend response and workflow APIs. Derive ordered, completed, and next-stop state inside `MyDeliveries.tsx`; render the existing batch and leg actions in a responsive checklist without storing duplicate completion state.

**Tech Stack:** React, TypeScript, Inertia, Tailwind CSS, Vitest, Testing Library

---

### Task 1: Rider batch route checklist

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx` (preserve the existing offered-batch control coverage and extend its mocks)
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`

- [ ] **Step 1: Write the failing component tests**

Keep the existing offered-batch test. Make the mocked page props replaceable, then render a batch containing deliberately unordered pending, `awaiting_proof_approval`, and `delivered` legs, including equal and null `stop_sequence` values. Assert:

```tsx
expect(screen.getByText('2 of 5 completed')).toBeInTheDocument();
expect(screen.getByText('Next stop')).toBeInTheDocument();
expect(screen.getByText('Proof submitted')).toBeInTheDocument();
expect(screen.getByText('Delivered')).toBeInTheDocument();
expect(screen.getAllByTestId('batch-stop').map((stop) => stop.dataset.legId))
  .toEqual(['12', '13', '14', '15', '16']); // sequence, then ID; null last
```

Also assert that the next stop is expanded, Open delivery expands only one non-next stop, and a mocked proof-status prop update plus rerender advances the next stop and clears the manual expansion. Cover `No stops in this batch`, `All stops completed`, exact `Not provided` receiver/address/phone fallbacks, hidden Call/Directions links, Accept/Reject/Start, Confirm pickup, and Out for delivery using the existing status combinations.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
npm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: FAIL because progress, checklist labels, stable ordering, and next-stop UI do not exist.

- [ ] **Step 3: Implement the minimum status-derived checklist**

In `MyDeliveries.tsx`:

```tsx
const isComplete = (status: string) => ['awaiting_proof_approval', 'delivered'].includes(status);
const orderedLegs = [...batch.legs].sort((a, b) =>
  (a.stop_sequence ?? Number.MAX_SAFE_INTEGER) - (b.stop_sequence ?? Number.MAX_SAFE_INTEGER) || a.id - b.id
);
const completed = orderedLegs.filter((leg) => isComplete(leg.status)).length;
const nextLeg = orderedLegs.find((leg) => !isComplete(leg.status));
```

Store manual expansion as one hook outside the batch loop:

```tsx
const [expandedLegs, setExpandedLegs] = useState<Record<number, number | null>>({});
```

Always expand each batch's `nextLeg`; permit one manually expanded non-next stop per batch. Use an effect keyed by the batch-to-next-leg signature to clear manual expansions when refreshed batch statuses advance the next stop. Render:

- batch date/window/status and safe progress percentage (`0` for no legs);
- next, compact, completed, and all-complete visual states;
- exact `Not provided` receiver/address/phone fallbacks;
- `tel:` and `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}` links only when their data exists;
- status-specific `Proof submitted` / `Delivered` labels;
- existing Accept, Reject, Start, Confirm pickup, and Out for delivery actions unchanged.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run the Step 2 command. Expected: all `MyDeliveries` tests pass.

- [ ] **Step 5: Run regression verification**

```bash
npm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
npm run build
```

Expected: tests pass and Vite production build exits successfully.

- [ ] **Step 6: Commit only the checklist files**

```bash
git add resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
git commit -m "feat: improve rider batch checklist"
```
