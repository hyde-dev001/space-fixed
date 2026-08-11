import React, { useCallback, useEffect, useRef, useState } from 'react';
import ShipmentTrackingPanel from './ShipmentTrackingPanel';
import type { TrackingShipment } from '@/types/logistics';

type TrackingRequestState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'success'; shipment: TrackingShipment }
  | { status: 'error' };

export type ShipmentTrackingModalProps = {
  shipmentId: number | null;
  isOpen: boolean;
  onClose: () => void;
  returnFocusRef?: { current: HTMLElement | null };
};

export default function ShipmentTrackingModal({
  shipmentId,
  isOpen,
  onClose,
  returnFocusRef,
}: ShipmentTrackingModalProps) {
  const closeButton = useRef<HTMLButtonElement>(null);
  const dialog = useRef<HTMLDivElement>(null);
  const [request, setRequest] = useState<TrackingRequestState>({ status: 'idle' });
  const [retryCount, setRetryCount] = useState(0);

  const closeModal = useCallback(() => {
    onClose();
  }, [onClose]);

  useEffect(() => {
    if (!isOpen || shipmentId === null) {
      setRequest({ status: 'idle' });
      return;
    }

    const controller = new AbortController();
    let active = true;
    setRequest({ status: 'loading' });

    fetch(`/tracking/shipments/${shipmentId}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then(async (response) => {
        if (!response.ok) throw new Error('Tracking request failed');

        const payload = await response.json() as { shipment?: TrackingShipment };
        if (!payload.shipment) throw new Error('Tracking payload is incomplete');

        if (active) setRequest({ status: 'success', shipment: payload.shipment });
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        if (active) setRequest({ status: 'error' });
      });

    return () => {
      active = false;
      controller.abort();
    };
  }, [isOpen, retryCount, shipmentId]);

  useEffect(() => {
    if (!isOpen) return;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const focusFrame = window.requestAnimationFrame(() => closeButton.current?.focus());

    return () => {
      document.body.style.overflow = previousOverflow;
      window.cancelAnimationFrame(focusFrame);
      queueMicrotask(() => returnFocusRef?.current?.focus());
    };
  }, [isOpen, returnFocusRef]);

  useEffect(() => {
    if (!isOpen) return;

    const closeOnEscape = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      if (event.key !== 'Escape') return;
      if (target && typeof target.closest === 'function' && target.closest('[aria-labelledby="delivery-proof-title"]')) return;

      event.preventDefault();
      closeModal();
    };

    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [closeModal, isOpen]);

  const handleKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    const target = event.target as HTMLElement | null;
    if (target && typeof target.closest === 'function' && target.closest('[aria-labelledby="delivery-proof-title"]')) return;
    if (event.key !== 'Tab') return;

    const focusable = Array.from(dialog.current?.querySelectorAll<HTMLElement>(
      'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ) ?? []);
    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  if (!isOpen || shipmentId === null) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="shipment-tracking-title"
      className="fixed inset-0 z-[90] flex items-center justify-center bg-[#16233b]/55 p-0 sm:p-6"
      onClick={(event) => {
        if (event.target === event.currentTarget) closeModal();
      }}
      onKeyDown={handleKeyDown}
    >
      <div
        ref={dialog}
        className="flex h-full w-full flex-col overflow-hidden bg-gray-50 shadow-2xl sm:max-h-[92vh] sm:max-w-5xl sm:rounded-2xl"
        onClick={(event) => event.stopPropagation()}
      >
        <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-gray-200 bg-white px-4 py-4 sm:px-6">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Shipment #{shipmentId}</p>
            <h2 id="shipment-tracking-title" className="mt-1 text-xl font-bold tracking-tight text-[#16233b]">
              Shipment tracking
            </h2>
          </div>
          <button
            ref={closeButton}
            type="button"
            aria-label="Close shipment tracking"
            onClick={closeModal}
            className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 text-xl font-bold text-gray-900 transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]"
          >
            <span aria-hidden="true">×</span>
          </button>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6 sm:py-6" aria-busy={request.status === 'loading'}>
          {request.status === 'loading' && (
            <div className="space-y-4" aria-live="polite">
              <p className="text-sm font-semibold text-gray-700">Loading shipment tracking...</p>
              <div className="h-24 animate-pulse rounded-2xl border border-gray-200 bg-white" />
              <div className="h-48 animate-pulse rounded-2xl border border-gray-200 bg-white" />
              <div className="h-32 animate-pulse rounded-2xl border border-gray-200 bg-white" />
            </div>
          )}

          {request.status === 'error' && (
            <div className="rounded-2xl border border-red-200 bg-red-50 p-5" role="alert">
              <h3 className="font-bold text-red-950">Unable to load shipment tracking.</h3>
              <p className="mt-1 text-sm leading-6 text-red-900">Please try again or open the full tracking page.</p>
              <div className="mt-4 flex flex-wrap gap-3">
                <button
                  type="button"
                  onClick={() => setRetryCount((count) => count + 1)}
                  className="inline-flex min-h-11 items-center justify-center rounded-lg bg-[#16233b] px-4 text-sm font-bold text-white transition-colors hover:bg-[#243758] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]"
                >
                  Try again
                </button>
                <a
                  href={`/tracking/shipments/${shipmentId}`}
                  className="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-900 bg-white px-4 text-sm font-bold text-gray-950 transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]"
                >
                  Open full tracking page
                </a>
              </div>
            </div>
          )}

          {request.status === 'success' && <ShipmentTrackingPanel shipment={request.shipment} compact />}
        </div>
      </div>
    </div>
  );
}
