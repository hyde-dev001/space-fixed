import React, { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import Swal from 'sweetalert2';

interface AppealPayload {
  token: string;
  account_type: 'shop_owner' | 'customer';
  account_name: string | null;
  recipient_email: string;
  suspension_reason: string | null;
  status: 'eligible' | 'submitted' | 'approved' | 'rejected' | 'expired';
  expires_at: string | null;
  submitted_at: string | null;
}

interface Props {
  appeal: AppealPayload;
  submitUrl: string;
}

export default function SubmitAppeal({ appeal, submitUrl }: Props) {
  const [appealMessage, setAppealMessage] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const statusLabel = useMemo(() => {
    if (appeal.status === 'eligible') return 'Eligible for appeal';
    if (appeal.status === 'submitted') return 'Already submitted';
    if (appeal.status === 'approved') return 'Appeal approved';
    if (appeal.status === 'rejected') return 'Appeal rejected';
    return 'Appeal expired';
  }, [appeal.status]);

  const canSubmit = appeal.status === 'eligible';

  const handleSubmit = async () => {
    if (appealMessage.trim().length < 20) {
      Swal.fire({
        icon: 'warning',
        title: 'More details needed',
        text: 'Please provide at least 20 characters for your appeal message.',
      });
      return;
    }

    setIsSubmitting(true);
    try {
      const response = await fetch(submitUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ appeal_message: appealMessage }),
      });

      const payload = await response.json();

      if (!response.ok) {
        throw new Error(payload?.message || 'Failed to submit appeal.');
      }

      Swal.fire({
        icon: 'success',
        title: 'Appeal Submitted',
        text: payload?.message || 'Your appeal was submitted successfully.',
      }).then(() => {
        window.location.reload();
      });
    } catch (error) {
      Swal.fire({
        icon: 'error',
        title: 'Submission failed',
        text: error instanceof Error ? error.message : 'Unable to submit your appeal right now.',
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <>
      <Head title="Submit Suspension Appeal" />
      <div className="min-h-screen bg-gray-50 px-4 py-10">
        <div className="mx-auto w-full max-w-3xl rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div className="border-b border-gray-200 px-6 py-5">
            <h1 className="text-2xl font-bold text-gray-900">Suspension Appeal</h1>
            <p className="mt-1 text-sm text-gray-600">Review your suspension details and submit an appeal for manual review.</p>
          </div>

          <div className="space-y-4 px-6 py-6">
            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Account</p>
                  <p className="text-sm font-medium text-gray-900">{appeal.account_name || 'N/A'}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Type</p>
                  <p className="text-sm font-medium capitalize text-gray-900">{appeal.account_type.replace('_', ' ')}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Email</p>
                  <p className="text-sm font-medium text-gray-900">{appeal.recipient_email}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Status</p>
                  <p className="text-sm font-medium text-gray-900">{statusLabel}</p>
                </div>
              </div>
              {appeal.suspension_reason && (
                <div className="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                  <strong>Suspension reason:</strong> {appeal.suspension_reason}
                </div>
              )}
            </div>

            {canSubmit ? (
              <>
                <div>
                  <label className="mb-2 block text-sm font-medium text-gray-700">Appeal Message</label>
                  <textarea
                    value={appealMessage}
                    onChange={(e) => setAppealMessage(e.target.value)}
                    rows={8}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-[#16233b] focus:outline-none focus:ring-2 focus:ring-[#16233b]/20"
                    placeholder="Please explain why your suspension should be reconsidered."
                  />
                  <p className="mt-1 text-xs text-gray-500">Minimum 20 characters.</p>
                </div>
                <button
                  type="button"
                  onClick={handleSubmit}
                  disabled={isSubmitting}
                  className="inline-flex items-center rounded-lg bg-[#16233b] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#0f1a2d] disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {isSubmitting ? 'Submitting...' : 'Submit Appeal'}
                </button>
              </>
            ) : (
              <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                This appeal link can no longer be submitted.
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
