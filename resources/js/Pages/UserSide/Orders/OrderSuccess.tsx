import React, { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';

export default function OrderSuccess() {
  const [loading, setLoading] = useState(true);
  const [failed,  setFailed]  = useState(false);
  const [message, setMessage] = useState('Verifying your payment with PayMongo...');
  const [pendingOrderId, setPendingOrderId] = useState<number | null>(null);
  const [retrying, setRetrying] = useState(false);

  const handleRetryPaymentSession = async () => {
    if (!pendingOrderId || retrying) {
      return;
    }

    try {
      setRetrying(true);
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch(`/api/orders/${pendingOrderId}/retry-payment-session`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
      });

      const result = await response.json();
      if (!response.ok || !result?.checkout_url) {
        throw new Error(result?.message || 'Failed to create retry payment session.');
      }

      sessionStorage.setItem('pendingOrderId', String(pendingOrderId));
      window.location.href = result.checkout_url;
    } catch (error) {
      setRetrying(false);
      setMessage(error instanceof Error ? error.message : 'Failed to retry payment session.');
    }
  };

  useEffect(() => {
    const run = async () => {
      const urlParams         = new URLSearchParams(window.location.search);
      const isPaymongoSuccess = urlParams.get('paymongo_success') === '1';
      const isPaymongoFailed  = urlParams.get('paymongo_failed')  === '1';
      const pendingOrderIdRaw = sessionStorage.getItem('pendingOrderId');
      const parsedPendingOrderId = pendingOrderIdRaw ? Number(pendingOrderIdRaw) : null;

      if (parsedPendingOrderId && !Number.isNaN(parsedPendingOrderId)) {
        setPendingOrderId(parsedPendingOrderId);
      }

      window.history.replaceState({}, '', '/order-success');

      if (isPaymongoFailed) {
        setFailed(true);
        setLoading(false);
        return;
      }

      if (!pendingOrderIdRaw || !isPaymongoSuccess) {
        router.visit('/my-orders');
        return;
      }

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // PayMongo redirects back before its own status update propagates.
        // Retry up to 6 times (12 seconds total) waiting for payment_status = 'paid'.
        const MAX_ATTEMPTS = 6;
        const RETRY_DELAY  = 2000;
        let data: any = null;

        for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
          const res = await fetch(`/api/orders/${pendingOrderIdRaw}/verify-payment`, {
            method: 'POST',
            credentials: 'include',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrfToken || '',
            },
          });
          data = await res.json();

          if (data.success && data.payment_verified) break;

          if (res.status === 410) {
            break;
          }

          if (res.status >= 500 || res.status === 404) break;

          if (attempt < MAX_ATTEMPTS) {
            await new Promise(resolve => setTimeout(resolve, RETRY_DELAY));
          }
        }

        if (data?.success && data?.payment_verified) {
          sessionStorage.removeItem('pendingOrderId');
          router.visit('/my-orders', { replace: true });
          return;
        } else {
          if (data?.expired) {
            setMessage('Payment session expired. Please retry checkout to generate a new payment session.');
          } else {
            setMessage(data?.message || 'Payment could not be verified.');
          }
          setFailed(true);
        }
      } catch (e) {
        setMessage('Could not reach the server to verify payment.');
        setFailed(true);
      } finally {
        setLoading(false);
      }
    };

    run();
  }, []);

  return (
    <div className="min-h-screen bg-gray-50">
      <Navigation />
      <div className="max-w-2xl mx-auto px-4 py-16 sm:px-6 lg:px-8">
        <div className="bg-white rounded-lg shadow-lg p-8 text-center">
          {loading ? (
            <div className="py-12">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-black mx-auto"></div>
              <p className="mt-4 text-gray-600">{message}</p>
            </div>
          ) : failed ? (
            <>
              <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-6">
                <svg className="h-10 w-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </div>
              <h1 className="text-2xl font-bold text-gray-900 mb-2">Payment Not Completed</h1>
              <p className="text-gray-600 mb-6">{message}</p>
              <div className="flex flex-col sm:flex-row gap-3 justify-center">
                {pendingOrderId && (
                  <button
                    onClick={handleRetryPaymentSession}
                    disabled={retrying}
                    className={`inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white transition-colors ${retrying ? 'bg-gray-400 cursor-not-allowed' : 'bg-black hover:bg-gray-800'}`}
                  >
                    {retrying ? 'Retrying...' : 'Retry Payment'}
                  </button>
                )}
                <Link href="/my-orders" className="inline-flex items-center justify-center px-6 py-3 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                  Go to My Orders
                </Link>
              </div>
            </>
          ) : (
            <div className="py-12">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-black mx-auto"></div>
              <p className="mt-4 text-gray-600">Payment confirmed. Redirecting to My Orders...</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
