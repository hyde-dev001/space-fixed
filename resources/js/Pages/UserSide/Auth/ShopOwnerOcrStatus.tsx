import type { ShopOwnerOcrSlotState } from './useShopOwnerOcr';

const statusText: Record<ShopOwnerOcrSlotState['status'], string> = {
  queued: 'OCR check queued. You can submit without waiting.',
  loading: 'Loading local OCR. You can submit without waiting.',
  recognizing: 'Reading this image in your browser. You can submit without waiting.',
  complete: 'Check complete. An admin still reviews your registration.',
  review: 'Needs admin review. You can still submit this registration.',
  unable: 'Unable to assess. You can still submit for admin review.',
};

export default function ShopOwnerOcrStatus({ state }: { state?: ShopOwnerOcrSlotState }) {
  if (!state) return null;

  const tone = state.status === 'complete'
    ? 'text-green-700'
    : state.status === 'review' || state.status === 'unable'
      ? 'text-amber-800'
      : 'text-gray-600';

  return (
    <p role="status" aria-live="polite" aria-atomic="true" className={`mt-2 text-sm ${tone}`}>
      {statusText[state.status]}
    </p>
  );
}
