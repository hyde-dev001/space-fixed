import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';

interface Props {
  shopId: number;
  shopName: string;
  isOpen: boolean;
  onClose: () => void;
}

interface ReportShopPageProps {
  auth?: {
    user?: {
      id: number;
      name?: string;
      email?: string;
    } | null;
  };
  csrf_token?: string;
}

const REASONS = [
  { value: 'fraud',         label: 'Fraud / Scam' },
  { value: 'fake_products', label: 'Fake / Counterfeit Products' },
  { value: 'harassment',    label: 'Harassment' },
  { value: 'no_show',       label: 'No-Show / Never Delivered' },
  { value: 'misconduct',    label: 'Poor Service Misconduct' },
  { value: 'other',         label: 'Other' },
];

type Step = 'form' | 'success' | 'error';

const ReportShopModal: React.FC<Props> = ({ shopId, shopName, isOpen, onClose }) => {
  const [reason, setReason] = useState('');
  const [description, setDescription] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [step, setStep] = useState<Step>('form');
  const [errorMessage, setErrorMessage] = useState('');

  if (!isOpen) return null;

  const handleClose = () => {
    // Reset state when closing
    setReason('');
    setDescription('');
    setSubmitting(false);
    setStep('form');
    setErrorMessage('');
    onClose();
  };

  const page = usePage<ReportShopPageProps>();
  const { csrf_token, auth } = page.props;
  const authUser = auth?.user ?? null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!authUser) {
      window.location.href = `/login?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
      return;
    }

    if (!reason || description.trim().length < 20) return;

    setSubmitting(true);
    setErrorMessage('');

    try {
      const response = await fetch(`/api/shops/${shopId}/report`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf_token,
        },
        body: JSON.stringify({ reason, description: description.trim() }),
      });

      const data = await response.json();

      if (response.status === 401) {
        window.location.href = `/login?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
        return;
      }

      if (response.ok) {
        setStep('success');
      } else {
        setErrorMessage(data.message ?? 'Something went wrong. Please try again.');
        setStep('error');
      }
    } catch {
      setErrorMessage('Network error. Please check your connection and try again.');
      setStep('error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-9999 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      onClick={(e) => { if (e.target === e.currentTarget) handleClose(); }}
    >
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-5 border-b border-gray-100">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-lg bg-red-50 flex items-center justify-center">
              <svg className="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6H13l-1-1H5a2 2 0 00-2 2zm9-13.5V9" />
              </svg>
            </div>
            <div>
              <h2 className="text-base font-bold text-gray-900">Report Shop</h2>
              <p className="text-xs text-gray-500 truncate max-w-50">{shopName}</p>
            </div>
          </div>
          <button
            onClick={handleClose}
            aria-label="Close report shop modal"
            title="Close"
            className="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-colors"
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Success state */}
        {step === 'success' && (
          <div className="px-6 py-12 text-center">
            <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg className="w-8 h-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <h3 className="text-lg font-bold text-gray-900 mb-2">Report Submitted</h3>
            <p className="text-sm text-gray-500 mb-6">
              Thank you. Our team will review your report and take appropriate action.
            </p>
            <button
              onClick={handleClose}
              className="px-8 py-2.5 bg-black text-white rounded-xl text-sm font-semibold hover:bg-gray-800 transition-colors"
            >
              Close
            </button>
          </div>
        )}

        {/* Error state */}
        {step === 'error' && (
          <div className="px-6 py-10 text-center">
            <div className="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg className="w-8 h-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <h3 className="text-lg font-bold text-gray-900 mb-2">Unable to Submit</h3>
            <p className="text-sm text-gray-500 mb-6">{errorMessage}</p>
            <div className="flex gap-3 justify-center">
              <button
                onClick={() => setStep('form')}
                className="px-6 py-2.5 border border-gray-300 rounded-xl text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
              >
                Try Again
              </button>
              <button
                onClick={handleClose}
                className="px-6 py-2.5 bg-black text-white rounded-xl text-sm font-semibold hover:bg-gray-800 transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        )}

        {/* Guest state */}
        {!authUser && step === 'form' && (
          <div className="px-6 py-10 text-center">
            <div className="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg className="w-8 h-8 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 11c0-1.657 1.343-3 3-3s3 1.343 3 3v3m-6-3V9a4 4 0 118 0v2m-9 10h10a2 2 0 002-2v-5a2 2 0 00-2-2H7a2 2 0 00-2 2v5a2 2 0 002 2z" />
              </svg>
            </div>
            <h3 className="text-lg font-bold text-gray-900 mb-2">Login Required</h3>
            <p className="text-sm text-gray-500 mb-6">
              Please log in first before reporting a shop.
            </p>
            <div className="flex gap-3 justify-center">
              <button
                onClick={handleClose}
                className="px-6 py-2.5 border border-gray-300 rounded-xl text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
              >
                Close
              </button>
              <button
                onClick={() => {
                  window.location.href = `/login?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
                }}
                className="px-6 py-2.5 bg-black text-white rounded-xl text-sm font-semibold hover:bg-gray-800 transition-colors"
              >
                Login
              </button>
            </div>
          </div>
        )}

        {/* Form state */}
        {authUser && step === 'form' && (
          <form onSubmit={handleSubmit}>
            <div className="px-6 py-5 space-y-5">
              {/* Notice */}
              <div className="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700 leading-relaxed">
                <strong>Note:</strong> Reports require a completed transaction with this shop.
                False reports may result in account restrictions.
              </div>

              {/* Reason */}
              <div>
                <label className="block text-sm font-semibold text-gray-800 mb-2">
                  Reason for report <span className="text-red-500">*</span>
                </label>
                <select
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  required
                  aria-label="Reason for report"
                  title="Reason for report"
                  className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white text-gray-900 focus:ring-2 focus:ring-red-400 focus:border-red-400 outline-none"
                >
                  <option value="">Select a reason…</option>
                  {REASONS.map((r) => (
                    <option key={r.value} value={r.value}>{r.label}</option>
                  ))}
                </select>
              </div>

              {/* Description */}
              <div>
                <label className="block text-sm font-semibold text-gray-800 mb-2">
                  Description <span className="text-red-500">*</span>
                  <span className="font-normal text-gray-400 ml-1">(min. 20 characters)</span>
                </label>
                <textarea
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  required
                  minLength={20}
                  maxLength={2000}
                  rows={4}
                  placeholder="Please describe what happened in detail. Include dates and specifics if possible…"
                  className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-red-400 focus:border-red-400 outline-none resize-none"
                />
                <p className={`text-xs mt-1 text-right ${description.length < 20 ? 'text-gray-400' : 'text-green-600'}`}>
                  {description.length} / 2000
                </p>
              </div>
            </div>

            {/* Footer */}
            <div className="flex gap-3 px-6 pb-6">
              <button
                type="button"
                onClick={handleClose}
                className="flex-1 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={submitting || !reason || description.trim().length < 20}
                className="flex-1 py-2.5 bg-red-600 text-white rounded-xl text-sm font-semibold hover:bg-red-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
              >
                {submitting ? (
                  <span className="flex items-center justify-center gap-2">
                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Submitting…
                  </span>
                ) : 'Submit Report'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
};

export default ReportShopModal;
