import React, { useEffect, useRef } from 'react';
import { Modal } from '@/components/ui/modal';
import type { DeliveryBatch } from '@/types/logistics';
import BatchTable from './BatchTable';

type Props = {
  batches: DeliveryBatch[];
  isOpen: boolean;
  onClose: () => void;
  onOpen: (batchId: number) => void;
  onDetails: (batchId: number, trigger: HTMLButtonElement) => void;
  onRestore?: (batchId: number) => void;
};

export default function BatchHistoryModal({ batches, isOpen, onClose, onOpen, onDetails, onRestore }: Props) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!isOpen) return;
    setTimeout(() => closeRef.current?.focus(), 0);
  }, [isOpen]);

  const handleKeys = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.stopPropagation();
      onClose();
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = dialogRef.current?.querySelectorAll<HTMLElement>('button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');
    if (!focusable?.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  };

  return <Modal isOpen={isOpen} onClose={onClose} size="7xl" showCloseButton={false}>
    <div ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="batch-history-title" onKeyDown={handleKeys} className="max-h-[100dvh] overflow-y-auto p-4 sm:max-h-[90vh] sm:p-7">
      <div className="flex items-start justify-between gap-4">
        <div><p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-700">Completed records</p><h2 id="batch-history-title" className="mt-1 text-xl font-bold text-gray-950 dark:text-white">Batch history</h2><p className="mt-1 text-sm text-gray-500">Review completed and cancelled batches without expanding the page.</p></div>
        <button ref={closeRef} type="button" onClick={onClose} aria-label="Close batch history" className="min-h-11 rounded-lg border px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 xl:min-h-10">Close</button>
      </div>
      <div className="mt-5"><BatchTable batches={batches} variant="history" onOpen={onOpen} onDetails={onDetails} onRestore={onRestore} /></div>
    </div>
  </Modal>;
}
