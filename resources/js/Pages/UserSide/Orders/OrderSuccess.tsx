import React, { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';

type VerifiedOrder = {
  order_number?: string | number | null;
};

type VerifyPaymentResponse = {
  success?: boolean;
  payment_verified?: boolean;
  order?: VerifiedOrder | null;
};

type OrderSuccessStatus = 'loading' | 'success';

export default function OrderSuccess() {
  const [status, setStatus] = useState<OrderSuccessStatus>('loading');
  const message = 'Verifying your payment with PayMongo...';
  const [verifiedOrderNumber, setVerifiedOrderNumber] = useState<string | null>(null);

  useEffect(() => {
    const run = async () => {
      const urlParams         = new URLSearchParams(window.location.search);
      const isPaymongoSuccess = urlParams.get('paymongo_success') === '1';
      const isPaymongoFailed  = urlParams.get('paymongo_failed')  === '1';
      const pendingOrderIdFromSession = Number(sessionStorage.getItem('pendingOrderId') || '0');
      const pendingOrderIdFromQuery = Number(urlParams.get('pending_order_id') || '0');
      const returnTs = Number(urlParams.get('return_ts') || '0');
      const returnSig = String(urlParams.get('return_sig') || '');
      const usedSessionPendingId = Number.isFinite(pendingOrderIdFromSession) && pendingOrderIdFromSession > 0;
      const pendingOrderId = Number.isFinite(pendingOrderIdFromSession) && pendingOrderIdFromSession > 0
        ? pendingOrderIdFromSession
        : (Number.isFinite(pendingOrderIdFromQuery) && pendingOrderIdFromQuery > 0 ? pendingOrderIdFromQuery : 0);
      const pendingOrderIdRaw = pendingOrderId > 0 ? String(pendingOrderId) : null;
      const postReturnDestination = usedSessionPendingId ? '/my-orders' : '/';

      const cancelPendingOrder = async () => {
        if (!Number.isFinite(pendingOrderId) || pendingOrderId <= 0) {
          return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        await fetch('/orders/cancel', {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
          },
          body: JSON.stringify({
            order_id: pendingOrderId,
            reason: 'payment_not_completed',
            note: 'Auto-cancelled from OrderSuccess after failed PayMongo return.',
          }),
        });
      };

      if (isPaymongoSuccess || isPaymongoFailed) {
        const cleanedParams = new URLSearchParams(urlParams);
        cleanedParams.delete('paymongo_success');
        cleanedParams.delete('paymongo_failed');
        cleanedParams.delete('pending_order_id');
        cleanedParams.delete('return_ts');
        cleanedParams.delete('return_sig');
        const cleanedQuery = cleanedParams.toString();
        window.history.replaceState({}, '', `/order-success${cleanedQuery ? `?${cleanedQuery}` : ''}`);
      }

      if (isPaymongoFailed) {
        try {
          await cancelPendingOrder();
        } catch (error) {
          console.warn('Failed to auto-cancel pending order after failed PayMongo return:', error);
        }
        sessionStorage.removeItem('pendingOrderId');
        router.visit(postReturnDestination, { replace: true });
        return;
      }

      if (!pendingOrderIdRaw || !isPaymongoSuccess) {
        router.visit(postReturnDestination, { replace: true });
        return;
      }

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // PayMongo redirects back before its own status update propagates.
        // Retry up to 6 times (12 seconds total) waiting for payment_status = 'paid'.
        const MAX_ATTEMPTS = 6;
        const RETRY_DELAY  = 2000;
        let data: VerifyPaymentResponse | null = null;

        for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
          const res = await fetch(`/api/orders/${pendingOrderIdRaw}/verify-payment-return`, {
            method: 'POST',
            credentials: 'include',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrfToken || '',
            },
            body: JSON.stringify({
              return_ts: returnTs,
              return_sig: returnSig,
            }),
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
          const orderNumber = data?.order?.order_number;
          setVerifiedOrderNumber(String(orderNumber ?? pendingOrderIdRaw));
          setStatus('success');
          return;
        } else {
          try {
            await cancelPendingOrder();
          } catch (error) {
            console.warn('Failed to auto-cancel unpaid order after verification:', error);
          }
          sessionStorage.removeItem('pendingOrderId');
          router.visit(postReturnDestination, { replace: true });
          return;
        }
      } catch (e) {
        sessionStorage.removeItem('pendingOrderId');
        router.visit(postReturnDestination, { replace: true });
        return;
      }
    };

    run();
  }, []);

  return (
    <>
      <Head title="Payment Successful" />
      <Navigation />
      <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-16">
        {status === 'loading' ? (
          <div className="text-center">
            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-black mx-auto"></div>
            <p className="mt-4 text-gray-600">{message}</p>
          </div>
        ) : (
          <div className="w-full max-w-xl rounded-2xl bg-white p-8 text-center shadow-lg sm:p-10">
            <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
              <svg
                aria-hidden="true"
                className="h-10 w-10 text-green-600"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M5 13l4 4L19 7"
                />
              </svg>
            </div>
            <p className="mb-2 text-sm font-semibold uppercase tracking-[0.2em] text-green-700">
              Payment confirmed
            </p>
            <h1 className="text-3xl font-bold text-gray-900">Payment Successful!</h1>
            <p className="mt-3 text-gray-600">
              Your payment has been verified and your order is now being processed.
            </p>
            <div className="mt-6 rounded-xl border border-gray-200 bg-gray-50 px-5 py-4">
              <p className="text-sm text-gray-500">Order Number</p>
              <p className="mt-1 text-xl font-bold text-gray-900">#{verifiedOrderNumber}</p>
            </div>
            <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
              <Link
                href="/my-orders"
                className="inline-flex items-center justify-center rounded-lg bg-black px-6 py-3 text-sm font-semibold text-white transition hover:bg-gray-800"
              >
                View My Orders
              </Link>
              <Link
                href="/"
                className="inline-flex items-center justify-center rounded-lg border border-gray-300 px-6 py-3 text-sm font-semibold text-gray-800 transition hover:bg-gray-100"
              >
                Continue Shopping
              </Link>
            </div>
          </div>
        )}
      </div>
    </>
  );
}
