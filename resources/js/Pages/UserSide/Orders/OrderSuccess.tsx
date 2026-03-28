import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';

export default function OrderSuccess() {
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState('Verifying your payment with PayMongo...');

  useEffect(() => {
    const run = async () => {
      const urlParams         = new URLSearchParams(window.location.search);
      const isPaymongoSuccess = urlParams.get('paymongo_success') === '1';
      const isPaymongoFailed  = urlParams.get('paymongo_failed')  === '1';
      const pendingOrderIdRaw = sessionStorage.getItem('pendingOrderId');

      window.history.replaceState({}, '', '/order-success');

      if (isPaymongoFailed) {
        sessionStorage.removeItem('pendingOrderId');
        router.visit('/my-orders', { replace: true });
        return;
      }

      if (!pendingOrderIdRaw || !isPaymongoSuccess) {
        router.visit('/my-orders', { replace: true });
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
          sessionStorage.removeItem('pendingOrderId');
          router.visit('/my-orders', { replace: true });
          return;
        }
      } catch (e) {
        sessionStorage.removeItem('pendingOrderId');
        router.visit('/my-orders', { replace: true });
        return;
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
          {loading && (
            <div className="py-12">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-black mx-auto"></div>
              <p className="mt-4 text-gray-600">{message}</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
