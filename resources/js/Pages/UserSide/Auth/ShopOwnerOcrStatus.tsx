import type { ShopOwnerOcrSlotState } from './useShopOwnerOcr';

const progressMessage = 'Checking your document. Please wait for the document scan to finish before submitting.';
const conflictMessage = 'Document type recognized. Some details differ from your registration and need admin review.';
const uncertainMessage = 'Document recognition is uncertain. A clearer photo may help; an admin will review this document.';
const replacementMessage = 'Invalid document image. We couldn\'t identify the required government document in your upload. Please upload a clear photo of the correct document.';
const technicalFailureMessage = 'We couldn\'t complete the automatic document check. You may continue your registration, but your documents will require manual verification.';

export function shopOwnerOcrStatusMessage(state: ShopOwnerOcrSlotState): string {
  if (state.status === 'queued' || state.status === 'loading' || state.status === 'recognizing') return progressMessage;
  if (state.status === 'replacement_required') return replacementMessage;
  if (state.status === 'complete') return 'Check complete. An admin still reviews your registration.';
  if (state.status === 'review' && state.score === 0 && state.relevance === 'plausible_document') return conflictMessage;
  if (state.status === 'review' && state.relevance === 'plausible_document') return 'Document type recognized. Some details need admin review.';
  if (state.status === 'unable' && state.relevance === 'technical_failure') return technicalFailureMessage;
  return uncertainMessage;
}

export default function ShopOwnerOcrStatus({ state }: { state?: ShopOwnerOcrSlotState }) {
  if (!state) return null;

  const tone = state.status === 'complete'
    ? 'text-green-700'
    : state.status === 'replacement_required'
      ? 'text-red-700'
      : state.status === 'review' || state.status === 'unable'
        ? 'text-amber-800'
        : 'text-gray-600';

  return (
    <p role="status" aria-live="polite" aria-atomic="true" className={`mt-2 text-sm ${tone}`}>
      {shopOwnerOcrStatusMessage(state)}
    </p>
  );
}
