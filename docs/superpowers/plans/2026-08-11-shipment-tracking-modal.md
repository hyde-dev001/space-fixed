# Shipment Tracking Modal and Refund Eligibility Tooltip Implementation Plan

> For agentic workers: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Let customers view outbound and return shipment details in an accessible modal from My Purchases, while preserving the standalone tracking page and replacing the refund eligibility sentence with an accessible hover/focus/tap tooltip.

**Architecture:** Extract the current tracking sections and proof viewer into a reusable ShipmentTrackingPanel. Keep ShipmentTracking.tsx as the authenticated Inertia page wrapper, and add a ShipmentTrackingModal that loads the same existing tracking route as JSON and renders the shared panel. Keep refund eligibility logic in MyOrders; add a small tooltip component only for the disabled refund explanation.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, React Testing Library, Playwright browser verification.

## Global Constraints

- Reuse the existing customer tracking endpoint and ownership checks; do not add a new backend endpoint.
- Keep /tracking/shipments/{shipment} working for direct URLs and as the modal error fallback.
- Both Track Shipment and Track Return use the same modal component.
- Keep refund eligibility calculation and backend refund rules unchanged.
- Show Only online-paid orders are eligible for refund requests. for payment-method ineligibility.
- Support mouse hover, keyboard focus, and touch tap for the tooltip; hover must not be the only access path.
- Preserve unrelated working-tree changes in package-lock.json and DESIGN.md.
- Use pnpm; the repository has no committed TypeScript compiler configuration or frontend lint script.

---

### Task 1: Add failing regression coverage for the new interactions

**Files:**
- Modify: resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx
- Create: resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx

**Interfaces:**
- The MyOrders test will consume the future Track Shipment and Track Return button behavior and the modal labelled dialog.
- The modal test will consume ShipmentTrackingModal with shipmentId, isOpen, onClose, and returnFocusRef.

- [ ] Step 1: Update the MyOrders test fixture and mocks

Keep the existing order fixture, change the tracking assertion from a link to a button, and add a deterministic JSON fetch response:

~~~tsx
const trackingPayload = {
  shipment: {
    id: 12,
    purpose: 'retail_delivery',
    status: 'active',
    source_type: 'order',
    legs: [{
      id: 22,
      sequence: 1,
      leg_type: 'outbound',
      status: 'in_transit',
      scheduled_delivery_date: '2026-07-18',
      delivery_window: 'morning',
      origin_snapshot: { name: 'Urban Kicks Store', address: 'Cavite' },
      destination_snapshot: { name: 'Mia Santos', address: 'Cavite' },
    }],
    events: [],
  },
};

vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
  ok: true,
  json: () => Promise.resolve(trackingPayload),
})));
~~~

- [ ] Step 2: Add failing assertions for both modal actions and refund explanation

The tests should assert that the outbound action is a button, the modal loads and displays the tracking number/status, the return action uses its own shipment ID, and an ineligible refund button exposes the exact payment message through a tooltip on focus:

~~~tsx
it('opens outbound tracking in a modal without navigating away', async () => {
  render(<MyOrders />);

  fireEvent.click(screen.getByRole('button', { name: 'Track Shipment' }));

  expect(await screen.findByRole('dialog', { name: 'Shipment tracking' })).toBeInTheDocument();
  expect(screen.getByText('SHP-12')).toBeInTheDocument();
});

it('reveals the online-payment refund explanation on keyboard focus', () => {
  order.status = 'completed';
  order.payment_method = 'cash_on_delivery';
  render(<MyOrders />);

  fireEvent.focus(screen.getByRole('group', { name: 'Refund eligibility information' }));

  expect(screen.getByRole('tooltip')).toHaveTextContent(
    'Only online-paid orders are eligible for refund requests.',
  );
});
~~~

The return-action assertion should set order.refund_stage.logistics_shipment_id = 18, click Track Return, and assert the fetch call includes /tracking/shipments/18.

- [ ] Step 3: Add modal loading/error/close tests

Create ShipmentTrackingModal.test.tsx with a minimal payload and these cases:

~~~tsx
it('shows loading, renders the shipment, and returns focus on close', async () => {
  const trigger = document.createElement('button');
  document.body.appendChild(trigger);
  const returnFocusRef = { current: trigger };
  const onClose = vi.fn();

  render(<ShipmentTrackingModal
    shipmentId={12}
    isOpen
    onClose={onClose}
    returnFocusRef={returnFocusRef}
  />);

  expect(screen.getByText('Loading shipment tracking...')).toBeInTheDocument();
  expect(await screen.findByText('Shipment Movement')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Close shipment tracking' }));
  expect(onClose).toHaveBeenCalledOnce();
});

it('offers the standalone tracking page when JSON loading fails', async () => {
  vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: false, status: 500 })));
  render(<ShipmentTrackingModal shipmentId={12} isOpen onClose={vi.fn()} />);

  expect(await screen.findByText('Unable to load shipment tracking.')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Open full tracking page' }))
    .toHaveAttribute('href', '/tracking/shipments/12');
});
~~~

- [ ] Step 4: Run the focused tests and verify they fail for the missing behavior

Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx
~~~

Expected: FAIL because MyOrders still renders links and ShipmentTrackingModal does not exist yet. Do not modify production code before observing this failure.

---

### Task 2: Extract the shared tracking presentation

**Files:**
- Create: resources/js/components/logistics/ShipmentTrackingPanel.tsx
- Modify: resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx
- Test: resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx

**Interfaces:**
- ShipmentTrackingPanel consumes shipment: TrackingShipment and renders tracking details plus the proof viewer.
- ShipmentTracking.tsx continues to consume usePage<{ shipment: TrackingShipment }>(), renders Navigation and Head, and passes the payload into the panel.

- [ ] Step 1: Define the panel props and shared formatting helpers

Move the current titleCase, customerStatus, formatDate, formatDeliveryDate, and snapshotText helpers into the new panel. Preserve the existing logistics types.

~~~tsx
type ShipmentTrackingPanelProps = {
  shipment: TrackingShipment;
  compact?: boolean;
};

export default function ShipmentTrackingPanel({
  shipment,
  compact = false,
}: ShipmentTrackingPanelProps) {
  // Shared movement, failed-attempt, update, and proof-viewer sections.
}
~~~

- [ ] Step 2: Move the proof viewer and tracking sections without changing data behavior

Keep the current proof viewer role=dialog, Escape listener, focus return, zoom levels, download link, image error fallback, failed-attempt proof fallback, and event rendering. Replace only layout primitives needed for the improved hierarchy:

~~~tsx
<section className="rounded-2xl border border-slate-200 bg-white shadow-[0_18px_50px_-35px_rgba(15,23,42,0.45)]">
  <div className="border-b border-slate-100 px-5 py-4 sm:px-6">
    <h2 className="text-base font-bold tracking-tight text-[#16233b]">Shipment Movement</h2>
  </div>
  <div className="divide-y divide-slate-100">
    <div className="grid gap-4 px-5 py-4 md:grid-cols-[120px_1fr_1fr_160px]">
      Render one responsive movement row for each shipment leg, including origin, destination, status, and proof action.
    </div>
  </div>
</section>
~~~

Use responsive grids that collapse to one column on small screens and retain minimum 44px controls. In compact mode, omit page-only navigation and let the modal own the scroll container; the panel itself must not set min-h-screen or render a second Navigation.

- [ ] Step 3: Make the standalone page a thin wrapper around the panel

Keep the existing page title, status header, standalone sections, and /my-orders or /my-repairs back link in ShipmentTracking.tsx, but render the shared panel for detailed content. The page must continue to work from direct URLs and retain existing Head and Navigation behavior.

- [ ] Step 4: Run the existing tracking tests

Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
~~~

Expected: all existing scheduled-delivery, repair, failed-attempt, proof-viewer, zoom, error, and fallback assertions remain green.

---

### Task 3: Implement the tracking modal with existing JSON data

**Files:**
- Create: resources/js/components/logistics/ShipmentTrackingModal.tsx
- Modify: resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx

**Interfaces:**
- Props:

~~~tsx
type ShipmentTrackingModalProps = {
  shipmentId: number | null;
  isOpen: boolean;
  onClose: () => void;
  returnFocusRef?: React.RefObject<HTMLElement | null>;
};
~~~

- Produces a labelled role=dialog and renders ShipmentTrackingPanel compact after a successful request.

- [ ] Step 1: Add request state and abort cleanup

Use a same-origin fetch and do not introduce a new client dependency:

~~~tsx
useEffect(() => {
  if (!isOpen || !shipmentId) return;

  const controller = new AbortController();
  setState({ status: 'loading' });

  fetch('/tracking/shipments/' + shipmentId, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    signal: controller.signal,
  })
    .then(async (response) => {
      if (!response.ok) throw new Error('Tracking request failed.');
      const payload = await response.json() as { shipment?: TrackingShipment };
      if (!payload.shipment) throw new Error('Tracking payload is missing shipment data.');
      setState({ status: 'success', shipment: payload.shipment });
    })
    .catch((error: unknown) => {
      if (error instanceof DOMException && error.name === 'AbortError') return;
      setState({ status: 'error' });
    });

  return () => controller.abort();
}, [isOpen, shipmentId, retryKey]);
~~~

- [ ] Step 2: Add accessible modal shell and interactions

Implement a fixed overlay with a scrollable panel. On open, focus the close button; on Escape or backdrop click call onClose; on close restore returnFocusRef; and restore document.body.style.overflow in cleanup. Use a stable aria-labelledby ID and aria-live=polite for loading/error text.

- [ ] Step 3: Add loading, success, and fallback error UI

Use the existing page's navy/neutral palette and action-button sizing. The error state must include retry and a direct link:

~~~tsx
<p role="alert">Unable to load shipment tracking.</p>
<button type="button" onClick={() => setRetryKey((value) => value + 1)}>Try again</button>
<a href={'/tracking/shipments/' + shipmentId}>Open full tracking page</a>
~~~

- [ ] Step 4: Run the modal tests

Run:

~~~powershell
pnpm exec vitest run resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx
~~~

Expected: loading, successful panel rendering, close callback/focus restoration, Escape close, and error fallback pass.

---

### Task 4: Wire both order actions and add the refund tooltip

**Files:**
- Create: resources/js/components/common/RefundEligibilityTooltip.tsx
- Modify: resources/js/Pages/UserSide/Orders/MyOrders.tsx
- Modify: resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx

**Interfaces:**
- RefundEligibilityTooltip consumes message: string and children: React.ReactNode and renders an accessible tooltip trigger with role=group and aria-describedby.
- MyOrders stores trackingShipmentId: number | null, showTrackingModal: boolean, and trackingTriggerRef: React.MutableRefObject<HTMLElement | null>.

- [ ] Step 1: Implement the tooltip trigger behavior

Use one small component with useId and useState. Reveal on onMouseEnter, onFocus, or onPointerDown; hide on onMouseLeave and onBlur; toggle on click for touch. Set tabIndex=0 on the wrapper so a native disabled child remains explainable, and apply pointer-events-none only to the disabled child so the wrapper receives touch events.

~~~tsx
<span
  role="group"
  tabIndex={0}
  aria-label="Refund eligibility information"
  aria-describedby={tooltipId}
>
  {children}
  {visible && <span id={tooltipId} role="tooltip">{message}</span>}
</span>
~~~

- [ ] Step 2: Replace tracking links with modal-opening buttons

Add one handler in MyOrders:

~~~tsx
const openTrackingModal = (shipmentId: number, trigger: HTMLElement) => {
  trackingTriggerRef.current = trigger;
  setTrackingShipmentId(shipmentId);
  setShowTrackingModal(true);
};
~~~

Render both actions with the same button classes and accessible names:

~~~tsx
<button
  type="button"
  onClick={(event) => openTrackingModal(order.logistics_shipment_id!, event.currentTarget)}
  className="actionButtonBase actionButtonSecondaryClass"
>
  Track Shipment
</button>
~~~

Apply the same pattern to order.refund_stage?.logistics_shipment_id with the label Track Return. Leave the failed-attempt detail link unchanged unless it is already a main tracking action.

- [ ] Step 3: Mount the modal once near the other MyOrders overlays

Render ShipmentTrackingModal outside the order map:

~~~tsx
<ShipmentTrackingModal
  shipmentId={trackingShipmentId}
  isOpen={showTrackingModal}
  onClose={() => {
    setShowTrackingModal(false);
    setTrackingShipmentId(null);
  }}
  returnFocusRef={trackingTriggerRef}
/>
~~~

- [ ] Step 4: Move the refund explanation into the tooltip

Keep disabled={!canRefund} and the existing getRefundIneligibilityMessage(order) logic. For an ineligible order, wrap the button in RefundEligibilityTooltip and remove the duplicate red inline message. For an eligible order, keep only the deadline guidance below the actions. The payment-method branch already returns the exact required sentence.

- [ ] Step 5: Run the MyOrders tests

Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx
~~~

Expected: both modal actions, JSON request IDs, tooltip explanation, delivery details, scheduled estimate, and existing refund/cancel behavior pass.

---

### Task 5: Full quality gates and browser verification

**Files:**
- Inspect only: changed frontend files and tests

- [ ] Step 1: Run all frontend tests

Run:

~~~powershell
pnpm run test:frontend
~~~

Expected: Vitest exits with code 0 and reports zero failed tests.

- [ ] Step 2: Build the production frontend

Run:

~~~powershell
pnpm run build
~~~

Expected: Vite exits with code 0 and writes the production assets.

- [ ] Step 3: Verify browser behavior with the local app

Using the webapp-testing Playwright workflow, verify an authenticated customer can:
1. Click Track Shipment and see the modal without URL navigation.
2. Click Track Return and see the selected return shipment details.
3. Scroll the modal on a narrow viewport and close it with the close button, Escape, and backdrop.
4. Focus, hover, or tap an ineligible REFUND control and see the online-payment explanation.
5. Open /tracking/shipments/{id} directly and confirm the standalone page still renders.

- [ ] Step 4: Run diff hygiene and dead-code checks

Run:

~~~powershell
git diff --check
git status --short
git diff --name-status HEAD~1..HEAD
~~~

Expected: no whitespace errors; only intentional feature files are staged/committed; package-lock.json and DESIGN.md remain uncommitted and untouched.

- [ ] Step 5: Commit the implementation

Stage only feature files and tests, never the existing user files:

~~~powershell
git add resources/js/components/logistics resources/js/components/common/RefundEligibilityTooltip.tsx resources/js/Pages/UserSide/Orders/MyOrders.tsx resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
git diff --cached --stat
git commit -m "feat: show shipment tracking in order modals"
~~~
