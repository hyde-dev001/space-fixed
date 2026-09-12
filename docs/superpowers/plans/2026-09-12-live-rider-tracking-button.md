# Live Rider Tracking Button Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a motor-icon action only for shipment rows represented by the current authorized live-rider location response, and open the existing shipment tracking modal for the selected shipment.

**Architecture:** Reuse the existing DispatcherLiveTracking poll and add one optional onLocationsChange callback that reports its normalized successful response to Shipments. The Shipments page will compare LiveRiderLocation.shipment_id strictly with each shipment ID, render the icon beside the existing action, and reuse one page-level ShipmentTrackingModal; no backend endpoint or tracking behavior changes.

**Tech Stack:** Laravel 12 application frontend, React 18, TypeScript 5.7, Inertia 2, Vite 7, Tailwind CSS 4, Vitest, Testing Library, and existing Lucide icons.

## Global Constraints

- Show the motor icon only when location.shipment_id === shipment.id in the latest successful live-location response.
- Preserve the existing CARTO basemap, Leaflet maps, GPS polling, rider marker movement, route rendering, routing, stop order, assignments, delivery statuses, returns, repairs, and customer tracking permissions.
- Reuse the existing DispatcherLiveTracking, LiveRiderLocation, and ShipmentTrackingModal implementations.
- Do not add a second live-location poll, backend endpoint, dependency, or authorization path.
- Do not change the existing Open delivery action or redesign the surrounding page.
- Keep the new icon accessible with an explicit label, title, dialog relationship, touch target, and focus styling.
- On a successful empty response, clear the page's live-location set; on a transient polling error, preserve the last successful set as the existing tracking component preserves its displayed data.
- Do not add secrets, credentials, or unrelated generated files.

---

## File map

- Modify resources/js/components/logistics/DispatcherLiveTracking.tsx: expose the optional callback and forward the normalized successful location array without changing polling or rendering.
- Modify resources/js/components/logistics/__tests__/DispatcherLiveTracking.test.tsx: verify callback delivery and disabled behavior.
- Modify resources/js/Pages/ERP/Logistics/Shipments.tsx: retain live locations, derive exact per-shipment visibility, add the motor action, and mount the existing tracking modal once.
- Modify resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx: isolate the page from the global map, cover matching/nonmatching visibility, and verify the selected shipment reaches the modal.
- Do not modify ShipmentTrackingModal.tsx, LiveTrackingMap.tsx, the logistics API service, or backend logistics code.

## Task 1: Forward live locations from the existing dispatcher poll

**Files:**
- Modify: resources/js/components/logistics/DispatcherLiveTracking.tsx:6-55
- Test: resources/js/components/logistics/__tests__/DispatcherLiveTracking.test.tsx:6-70

**Interfaces:**
- Consumes: existing logisticsApi.liveLocations() response and LiveRiderLocation[] type.
- Produces: optional prop onLocationsChange?: (locations: LiveRiderLocation[]) => void.

- [ ] **Step 1: Write the failing callback test**

In DispatcherLiveTracking.test.tsx, add the callback mock and pass it to the enabled render:

~~~tsx
const mocks = vi.hoisted(() => ({
  liveLocations: vi.fn(),
  onLocationsChange: vi.fn(),
}));

// Keep the existing response setup.

render(<DispatcherLiveTracking enabled onLocationsChange={mocks.onLocationsChange} />);

await waitFor(() => expect(mocks.liveLocations).toHaveBeenCalledTimes(1));
expect(mocks.onLocationsChange).toHaveBeenCalledWith(
  expect.arrayContaining([
    expect.objectContaining({ shipment_id: 7 }),
  ]),
);
~~~

Pass a callback to the disabled test and assert it is not called:

~~~tsx
it('does not poll while the feature is disabled', () => {
  const onLocationsChange = vi.fn();

  render(<DispatcherLiveTracking enabled={false} onLocationsChange={onLocationsChange} />);

  expect(mocks.liveLocations).not.toHaveBeenCalled();
  expect(onLocationsChange).not.toHaveBeenCalled();
  expect(screen.queryByText('Live rider tracking')).not.toBeInTheDocument();
});
~~~

- [ ] **Step 2: Run the focused test and verify the new assertion fails**

Run:

~~~powershell
pnpm exec vitest run resources/js/components/logistics/__tests__/DispatcherLiveTracking.test.tsx --reporter=dot
~~~

Expected: the existing rendering assertions pass, but the new callback assertion fails because the current component does not invoke onLocationsChange.

- [ ] **Step 3: Add the minimal optional callback**

Update the props, function parameters, successful response handling, and effect dependencies in DispatcherLiveTracking.tsx:

~~~tsx
type Props = {
  enabled: boolean;
  pollIntervalSeconds?: number;
  onLocationsChange?: (locations: LiveRiderLocation[]) => void;
};

export default function DispatcherLiveTracking({
  enabled,
  pollIntervalSeconds = 5,
  onLocationsChange,
}: Props) {
  // Keep the existing loading, error, lastUpdated, and locations state.

  useEffect(() => {
    if (!enabled) return undefined;
    let disposed = false;

    const load = async () => {
      if (disposed) return;
      setLoading(true);

      try {
        const response = await logisticsApi.liveLocations();
        if (disposed) return;
        const nextLocations = Array.isArray(response.data.locations) ? response.data.locations : [];
        setLocations(nextLocations);
        onLocationsChange?.(nextLocations);
        setLastUpdated(response.data.server_time ?? new Date().toISOString());
        setError(null);
      } catch {
        if (!disposed) setError('Live rider locations are temporarily unavailable.');
      } finally {
        if (!disposed) setLoading(false);
      }
    };

    void load();
    const interval = window.setInterval(load, Math.max(5, pollIntervalSeconds) * 1000);

    return () => {
      disposed = true;
      window.clearInterval(interval);
    };
  }, [enabled, onLocationsChange, pollIntervalSeconds]);
~~~

Keep the existing section, map, list, loading, error, and interval rendering unchanged.

- [ ] **Step 4: Run the callback test and verify it passes**

Run:

~~~powershell
pnpm exec vitest run resources/js/components/logistics/__tests__/DispatcherLiveTracking.test.tsx --reporter=dot
~~~

Expected: both dispatcher tests pass, including callback delivery and the disabled no-poll assertion.

- [ ] **Step 5: Commit the isolated component change**

~~~powershell
git add resources/js/components/logistics/DispatcherLiveTracking.tsx resources/js/components/logistics/__tests__/DispatcherLiveTracking.test.tsx
git diff --cached --check
git commit -m "feat: expose dispatcher live locations"
~~~

## Task 2: Add the exact-shipment motor action and tracking modal wiring

**Files:**
- Modify: resources/js/Pages/ERP/Logistics/Shipments.tsx:2-20,153-210,534-692,1140-1175
- Test: resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx:1-70 and new focused cases near the existing accessible-modal tests

**Interfaces:**
- Consumes: DispatcherLiveTracking.onLocationsChange from Task 1, LiveRiderLocation from LiveTrackingMap, and ShipmentTrackingModalProps from the existing modal.
- Produces: one icon action per matching shipment with an accessible label containing its shipment number, plus one page-level tracking modal selected by shipment ID.

- [ ] **Step 1: Add a controlled page-test seam and write failing visibility/modal tests**

In Shipments.test.tsx, extend the hoisted mocks with controlled dispatcher locations and mock only the page-level dispatcher/modal components:

~~~tsx
const mocks = vi.hoisted(() => ({
  post: vi.fn(() => Promise.resolve()),
  get: vi.fn(),
  reload: vi.fn(),
  props: {} as any,
  dispatcherLocations: [] as Array<{ shipment_id: number | null }>,
}));

vi.mock('@/components/logistics/DispatcherLiveTracking', () => ({
  default: ({
    onLocationsChange,
  }: {
    onLocationsChange?: (locations: Array<{ shipment_id: number | null }>) => void;
  }) => {
    React.useEffect(() => {
      onLocationsChange?.(mocks.dispatcherLocations);
    }, [onLocationsChange]);

    return null;
  },
}));

vi.mock('@/components/logistics/ShipmentTrackingModal', () => ({
  default: ({
    shipmentId,
    isOpen,
    onClose,
  }: {
    shipmentId: number | null;
    isOpen: boolean;
    onClose: () => void;
  }) => isOpen ? (
    <div role="dialog" aria-label="Shipment tracking">
      <p>Tracking shipment {shipmentId}</p>
      <button type="button" onClick={onClose}>Close shipment tracking</button>
    </div>
  ) : null,
}));
~~~

Reset the controlled array in the existing beforeEach:

~~~tsx
mocks.dispatcherLocations = [];
~~~

Add these cases near the existing accessible-modal tests:

~~~tsx
it('shows live tracking only for the shipment in the live-location response', async () => {
  const second = structuredClone(mocks.props.shipments.data[0]);
  second.id = 2;
  mocks.props.shipments.data.push(second);
  mocks.props.riderMode = false;
  mocks.props.liveTrackingEnabled = true;
  mocks.props.canViewShipments = true;
  mocks.dispatcherLocations = [{ shipment_id: 1 }];

  render(<Shipments />);

  expect(await screen.findByRole('button', { name: 'Open live tracking for Shipment 1' })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Open live tracking for Shipment 2' })).not.toBeInTheDocument();
});

it('hides live tracking when no returned location matches the shipment', async () => {
  mocks.props.riderMode = false;
  mocks.props.liveTrackingEnabled = true;
  mocks.props.canViewShipments = true;
  mocks.dispatcherLocations = [{ shipment_id: 999 }];

  render(<Shipments />);

  await waitFor(() => expect(screen.queryByRole('button', { name: 'Open live tracking for Shipment 1' })).not.toBeInTheDocument());
});

it('opens the existing tracking modal for the clicked shipment', async () => {
  mocks.props.riderMode = false;
  mocks.props.liveTrackingEnabled = true;
  mocks.props.canViewShipments = true;
  mocks.dispatcherLocations = [{ shipment_id: 1 }];

  render(<Shipments />);
  fireEvent.click(await screen.findByRole('button', { name: 'Open live tracking for Shipment 1' }));

  expect(screen.getByRole('dialog', { name: 'Shipment tracking' })).toHaveTextContent('Tracking shipment 1');
});
~~~

- [ ] **Step 2: Run the focused Shipments tests and verify the new cases fail**

Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx --reporter=dot
~~~

Expected: existing Shipments tests pass, while the new motor-action cases fail because the page has no live-location state, action, or tracking modal wiring.

- [ ] **Step 3: Wire live-location state and the existing tracking modal**

In Shipments.tsx, add these imports:

~~~tsx
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Bike, CalendarDays, ExternalLink, MapPin, Search, UserRound, X } from 'lucide-react';
import ShipmentTrackingModal from '@/components/logistics/ShipmentTrackingModal';
import type { LiveRiderLocation } from '@/components/logistics/LiveTrackingMap';
~~~

Add the state and handlers beside the existing shipment modal state:

~~~tsx
const [selectedShipmentId, setSelectedShipmentId] = useState<number | null>(null);
const [selectedTrackingShipmentId, setSelectedTrackingShipmentId] = useState<number | null>(null);
const [liveLocations, setLiveLocations] = useState<LiveRiderLocation[]>([]);
const [selectedProofUrl, setSelectedProofUrl] = useState<string | null>(null);
const returnFocusRef = useRef<HTMLButtonElement | null>(null);
const trackingTriggerRef = useRef<HTMLButtonElement | null>(null);

const handleLiveLocationsChange = useCallback((locations: LiveRiderLocation[]) => {
  setLiveLocations(locations);
}, []);

const openLiveTracking = (shipmentId: number, trigger: HTMLButtonElement) => {
  trackingTriggerRef.current = trigger;
  setSelectedTrackingShipmentId(shipmentId);
};

const closeLiveTracking = () => {
  setSelectedTrackingShipmentId(null);
};
~~~

Pass the stable callback to the existing global tracking component:

~~~tsx
{!riderMode && canViewShipments && liveTrackingEnabled && (
  <DispatcherLiveTracking
    enabled
    pollIntervalSeconds={liveTrackingIntervalSeconds}
    onLocationsChange={handleLiveLocationsChange}
  />
)}
~~~

Inside the shipment map callback, derive the exact ID match after shipmentNumber:

~~~tsx
const hasLiveTracking = !riderMode
  && canViewShipments
  && liveTrackingEnabled
  && liveLocations.some((location) => location.shipment_id === shipment.id);
~~~

Replace only the existing action button wrapper with a two-button action group. Keep the existing Open delivery handler and its desktop classes; add the icon before it:

~~~tsx
<div className="flex w-full items-center justify-end gap-2 xl:w-auto">
  {hasLiveTracking && (
    <button
      type="button"
      aria-label={'Open live tracking for Shipment ' + shipmentNumber}
      title="Open live tracking"
      aria-haspopup="dialog"
      onClick={(event) => openLiveTracking(shipment.id, event.currentTarget)}
      className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-xl border border-gray-300 bg-gray-50 p-2 text-gray-800 transition-colors hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 dark:focus-visible:ring-gray-300 xl:rounded-lg"
    >
      <Bike aria-hidden="true" size={18} />
    </button>
  )}
  <button
    type="button"
    aria-label={shipments.data.length > 1 ? 'Open delivery for Shipment ' + shipmentNumber : undefined}
    aria-haspopup="dialog"
    onClick={(event) => openShipment(shipment.id, event.currentTarget)}
    className="inline-flex min-h-11 w-full flex-1 items-center justify-center gap-2 rounded-xl border border-gray-300 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-800 transition-colors hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 dark:focus-visible:ring-2 dark:focus-visible:ring-gray-300 xl:w-auto xl:flex-none xl:shrink-0 xl:rounded-lg"
  >
    Open delivery
    <ExternalLink aria-hidden="true" size={16} />
  </button>
</div>
~~~

Mount one existing modal after the shipment list and before pagination:

~~~tsx
<ShipmentTrackingModal
  shipmentId={selectedTrackingShipmentId}
  isOpen={selectedTrackingShipmentId !== null}
  onClose={closeLiveTracking}
  returnFocusRef={trackingTriggerRef}
/>
~~~

Do not alter ShipmentTrackingModal.tsx; its existing fetch, error state, tracking panel, focus trap, escape handling, and return-focus cleanup remain authoritative.

- [ ] **Step 4: Run the Shipments tests and verify they pass**

Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx --reporter=dot
~~~

Expected: all existing Shipments tests and the three new visibility/modal tests pass.

- [ ] **Step 5: Commit the page integration**

~~~powershell
git add resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
git diff --cached --check
git commit -m "feat: add shipment live tracking action"
~~~

## Task 3: Run complete verification and prepare the deployment build

**Files:**
- Verify: all source and test files changed by Tasks 1 and 2
- Generate: public/build/ only through the repository build command when deployment requires committed assets

**Interfaces:**
- Consumes: the committed component and page integration from Tasks 1 and 2.
- Produces: passing focused/frontend tests, a successful production bundle, and a reviewed diff containing only this feature plus intentionally generated build assets.

- [ ] **Step 1: Run the focused regression set**

Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/components/logistics/__tests__/DispatcherLiveTracking.test.tsx resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx --reporter=dot
~~~

Expected: all three test files pass, including the pre-existing shipment tracking modal tests.

- [ ] **Step 2: Run the frontend test suite**

Run:

~~~powershell
pnpm run test:frontend
~~~

Expected: the repository frontend test command exits successfully with no failed tests.

- [ ] **Step 3: Build the fresh production assets**

Run after source tests pass:

~~~powershell
pnpm run build
~~~

Expected: Vite exits successfully and updates only the repository's intended public/build/ output. Do not manually edit generated assets.

- [ ] **Step 4: Review final scope and hygiene**

Run:

~~~powershell
git status --short
git diff --stat
git diff --check
rg -n "liveLocations|Open live tracking|ShipmentTrackingModal|shipment_id === shipment.id" resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/components/logistics/DispatcherLiveTracking.tsx
git diff --name-status
~~~

Expected: only the planned source/tests/spec/plan files and intentional public/build/ output are changed; no backend, map, GPS, routing, or unrelated ERP files appear. The search shows the callback, strict shipment-ID comparison, icon label, and one existing modal mount.

- [ ] **Step 5: Perform the final behavior review**

Confirm from the code and focused tests that:

~~~text
matching shipment_id -> one motor button -> existing ShipmentTrackingModal(selected shipment id)
nonmatching/empty response -> no motor button
successful empty response -> stored locations cleared
polling error -> existing dispatcher map/list state remains unchanged
~~~

Also confirm the original Open delivery click path, ShipmentTrackingModal.tsx, LiveTrackingMap.tsx, and all backend logistics files are unchanged.

- [ ] **Step 6: Commit generated assets with the final page revision**

After reviewing git status, stage only the intended build output:

~~~powershell
git add public/build
git diff --cached --check
git commit --amend --no-edit
~~~

The generated build commit is allowed only when pnpm run build succeeded and public/build/ is required by the deployment workflow.
